<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Exceptions\LoginChallengeException;
use App\Models\User;
use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Support\AtomicCounter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Throwable;

/**
 * Đăng nhập học sinh ở host api (US-001). Mọi nơi bắt đầu phiên học sinh đi qua `startSession()`,
 * nơi DUY NHẤT gọi `StudentSessionService::bind()` (ADR-003).
 */
class LoginService
{
    public function __construct(private readonly StudentSessionService $sessions) {}

    private static ?string $dummyHash = null;

    private static ?string $dummyHashRounds = null;

    private const DECAY_SECONDS = 3600;

    public const GENERIC_FAILURE = 'Thông tin đăng nhập hoặc mật khẩu không đúng.';

    /**
     * @throws LoginChallengeException sai thông tin (thông điệp chung, BR5) / CAPTCHA_REQUIRED / CAPTCHA_INVALID (GL-A2)
     * @throws DomainException ACCOUNT_LOCKED / WRONG_PORTAL (chỉ sau khi mật khẩu ĐÚNG — S20)
     */
    public function attempt(string $login, #[\SensitiveParameter] string $password, Request $request, ?string $captchaToken = null): User
    {
        // M2: tìm tài khoản TRƯỚC để khoá đếm theo user id (DB so khớp email không phân biệt dấu/hoa thường, nên mọi
        // cách viết của 1 email phải dùng chung 1 bộ đếm). Không có tài khoản → khoá chuẩn hoá (bỏ dấu, hạ chữ).
        $user = self::findByLogin($login);
        $accountKey = 'login-fail:'.self::throttleSubject($login, $user);
        $ipKey = 'login-fail-ip:'.$request->ip();

        // M3: đếm NGUYÊN TỬ trước khi so mật khẩu (`hit` = INCR). Request đồng thời mỗi cái nhận 1 số thứ tự riêng, chỉ
        // ngưỡng đầu tiên được so mật khẩu; vượt ngưỡng thì mật khẩu đúng cũng bị chặn (S10).
        // GL-A2: từ ngưỡng lần sai (config auth.login.captcha_threshold) phải kèm captcha thay vì bị khoá; trần cứng → 429.
        ['count' => $count, 'keys' => $reserved] = self::reserveWithCaptchaGate(
            $accountKey,
            (int) config('auth.login.max_failures_per_account'),
            $ipKey,
            (int) config('auth.login.max_failures_per_ip'),
            (int) config('auth.login.captcha_threshold'),
            $captchaToken,
            $request->ip(),
            'login-captcha-reject',
            'login-fail-ip-captcha:'.$request->ip(),
            (int) config('auth.login.max_captcha_failures_per_ip'),
        );

        // Luôn băm 1 lần dù không có tài khoản, để thời gian phản hồi không lộ tài khoản tồn tại.
        $hash = $user !== null ? $user->password : self::dummyHash();
        $passwordOk = Hash::check($password, $hash);

        if ($user === null || ! $passwordOk) {
            // Lượt sai: giữ nguyên số đã đếm ở trên.
            // `captcha_required`: lần sau có cần captcha không. Chỉ phụ thuộc bộ đếm (tài khoản không tồn tại y hệt).
            throw LoginChallengeException::badCredentials(self::GENERIC_FAILURE, self::captchaNeededAfter($count));
        }

        // Chỉ đếm lượt SAI (contract §1.6): mật khẩu đúng thì hoàn lượt đã giữ chỗ (kể cả khi sau đó bị WRONG_PORTAL/LOCKED).
        self::releaseAttempts(...$reserved);

        if ($user->role !== UserRole::Student) {
            throw new DomainException(
                code: 'WRONG_PORTAL',
                message: 'Tài khoản này không đăng nhập ở trang học sinh.',
                status: 403,
            );
        }

        if ($user->status === UserStatus::Locked) {
            throw new DomainException(
                code: 'ACCOUNT_LOCKED',
                message: 'Tài khoản của bạn đã bị khoá.',
                status: 403,
            );
        }

        RateLimiter::clear($accountKey);

        $this->startSession($request, $user);

        return $user;
    }

    /**
     * GL-A2: cổng captcha + giữ chỗ lượt.
     *
     * - Có `captcha_token`: xác minh TRƯỚC (sai → 422 CAPTCHA_INVALID). Token hợp lệ thì lượt KHÔNG tính vào bộ đếm IP thường
     *   (R1: NAT lớp học không bị chặn khi đã giải captcha) mà tính vào trần IP riêng, cao, cho lượt có captcha (V2-2) và
     *   trần tài khoản, tất-cả-hoặc-không.
     * - Không token: giữ chỗ nguyên tử [IP, tài khoản]. Chạm trần IP → 422 CAPTCHA_REQUIRED (V2-3b: người sau NAT giải captcha
     *   là vào được, không bị 429); chạm trần tài khoản → 429. Số lượt tài khoản > ngưỡng → hoàn cả hai và 422 CAPTCHA_REQUIRED.
     * - Lượt bị captcha từ chối (thiếu/sai) tính vào 2 limiter (V2-3a): theo cặp IP+tài khoản (thấp, để người ngoài không chặn
     *   được người khác cùng IP) và theo IP (cao, chống đốt quota siteverify).
     *
     * @return array{count: int, keys: list<string>} `count`: số lượt của khoá tài khoản; `keys`: các khoá đã cộng (hoàn khi đúng)
     *
     * @throws ThrottleRequestsException trần cứng / quá nhiều lượt captcha bị từ chối
     * @throws LoginChallengeException thiếu/sai captcha
     */
    public static function reserveWithCaptchaGate(string $accountKey, int $accountMax, string $ipKey, int $ipMax, int $threshold, ?string $captchaToken, ?string $ip, string $rejectLimiter = 'login-captcha-reject', ?string $ipCaptchaKey = null, ?int $ipCaptchaMax = null): array
    {
        $rejectKeys = [
            [$rejectLimiter.':'.$ip.':'.$accountKey, (int) config('auth.login.captcha_rejects_per_minute')],
            [$rejectLimiter.'-ip:'.$ip, (int) config('auth.login.captcha_rejects_per_minute_ip')],
        ];
        $ipCaptchaKey ??= $ipKey.'-captcha';
        $ipCaptchaMax ??= (int) config('auth.login.max_captcha_failures_per_ip');

        if ($captchaToken !== null && $captchaToken !== '') {
            self::assertCaptchaBudget($rejectKeys);

            if (! app(CaptchaVerifier::class)->verify($captchaToken, $ip)) {
                self::rejectCaptcha($rejectKeys, LoginChallengeException::captchaInvalid());
            }

            ['counts' => [, $count]] = self::reserve([[$ipCaptchaKey, $ipCaptchaMax], [$accountKey, $accountMax]]);

            return ['count' => $count, 'keys' => [$ipCaptchaKey, $accountKey]];
        }

        $result = AtomicCounter::hitAll([[$ipKey, $ipMax], [$accountKey, $accountMax]], self::DECAY_SECONDS);

        if ($result['blocked'] === $ipKey) {
            // V2-3b: trần IP với lượt không captcha → đòi captcha (lượt có captcha hợp lệ không bị trần này chặn).
            self::assertCaptchaBudget($rejectKeys);
            self::rejectCaptcha($rejectKeys, LoginChallengeException::captchaRequired());
        }

        if ($result['blocked'] !== null) {
            throw self::throttled($result['blocked']);
        }

        $count = $result['counts'][1];

        if ($count <= $threshold) {
            return ['count' => $count, 'keys' => [$ipKey, $accountKey]];
        }

        self::releaseAttempts($ipKey, $accountKey);
        self::assertCaptchaBudget($rejectKeys);

        self::rejectCaptcha($rejectKeys, LoginChallengeException::captchaRequired());
    }

    /** @param  list<array{0: string, 1: int}>  $rejectKeys */
    private static function assertCaptchaBudget(array $rejectKeys): void
    {
        foreach ($rejectKeys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw self::throttled($key, RateLimiter::availableIn($key));
            }
        }
    }

    /**
     * Lượt bị captcha từ chối (thiếu/sai) tính vào các limiter riêng rồi mới trả lỗi.
     *
     * @param  list<array{0: string, 1: int}>  $rejectKeys
     */
    private static function rejectCaptcha(array $rejectKeys, LoginChallengeException $e): never
    {
        foreach ($rejectKeys as [$key]) {
            RateLimiter::hit($key, 60);
        }

        throw $e;
    }

    /** Sau lượt sai này (đã tính vào `$count`), lần đăng nhập sau có phải kèm captcha không. */
    public static function captchaNeededAfter(int $count, ?int $threshold = null): bool
    {
        return $count >= ($threshold ?? (int) config('auth.login.captcha_threshold'));
    }

    /**
     * Giữ chỗ 1 lượt ở MỌI khoá, nguyên tử và tất-cả-hoặc-không (`AtomicCounter::hitAll`): khoá nào đã đạt trần → 429 và
     * KHÔNG khoá nào bị cộng. Request đồng thời mỗi cái nhận số thứ tự riêng nên không vượt trần.
     *
     * @param  list<array{0: string, 1: int}>  $limits  [khoá, trần số lượt sai]; khoá "rộng" (IP) đặt trước
     * @return list<int> số lượt (đã gồm lượt này) của từng khoá, theo thứ tự `$limits`
     *
     * @throws ThrottleRequestsException
     */
    public static function reserveAttempts(array $limits): array
    {
        return self::reserve($limits)['counts'];
    }

    /**
     * @param  list<array{0: string, 1: int}>  $limits
     * @return array{counts: list<int>}
     */
    private static function reserve(array $limits): array
    {
        $result = AtomicCounter::hitAll($limits, self::DECAY_SECONDS);

        if ($result['blocked'] !== null) {
            throw self::throttled($result['blocked']);
        }

        return ['counts' => $result['counts']];
    }

    private static function throttled(string $key, ?int $retryAfter = null): ThrottleRequestsException
    {
        return new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => (string) max(1, $retryAfter ?? AtomicCounter::availableIn($key))]);
    }

    /** Hoàn lượt đã giữ chỗ khi mật khẩu đúng (chỉ đếm lượt SAI). DECR nguyên tử, không xuống dưới 0. */
    public static function releaseAttempts(string ...$keys): void
    {
        foreach ($keys as $key) {
            AtomicCounter::release($key, self::DECAY_SECONDS);
        }
    }

    /**
     * Khoá theo tài khoản phải chuẩn hoá: `0912…`, `+84912…`, `84 912…` là cùng 1 SĐT,
     * email không phân biệt hoa/thường (nếu không, đổi cách viết là né được giới hạn).
     */
    public static function accountKey(string $login): string
    {
        $login = trim($login);

        if (! str_contains($login, '@') && ($phone = PhoneNumber::normalize($login)) !== null) {
            return $phone;
        }

        // Bỏ dấu (khớp collation `utf8mb4_0900_ai_ci` của DB) rồi hạ chữ.
        return mb_strtolower(Str::ascii(mb_substr($login, 0, 254)));
    }

    /** Khoá đếm theo tài khoản: user id nếu tìm thấy (mọi cách viết cùng 1 bộ đếm), không thì khoá chuẩn hoá. */
    public static function throttleSubject(string $login, ?User $user): string
    {
        return $user !== null ? 'u:'.$user->getKey() : 'a:'.self::accountKey($login);
    }

    /**
     * Đăng nhập session (KHÔNG remember-me) + đổi session id chống session fixation,
     * rồi bind phiên 1 thiết bị (ADR-003): huỷ session cũ của học sinh + tombstone.
     */
    public function startSession(Request $request, User $user): void
    {
        $previous = Auth::guard('web')->user();

        if ($previous instanceof User && $previous->isNot($user)) {
            // Trình duyệt đang giữ phiên của học sinh KHÁC: nhả phiên đó, không để nó còn là "phiên hiện hành".
            $this->sessions->release($previous, $request->session()->getId());
        }

        // Laravel: `Guard::login()` tự `migrate(true)` — xoá payload session cũ khỏi store NGAY. Nếu chính học
        // sinh này đang giữ phiên hợp lệ và bind() lỗi, phải khôi phục payload đó (ADR-003 bước 5: phiên cũ vẫn
        // là phiên duy nhất, không mất phiên oan).
        $oldId = $request->session()->getId();
        $snapshot = ($previous instanceof User && $previous->is($user))
            ? Session::getHandler()->read($oldId)
            : '';

        try {
            Auth::guard('web')->login($user, remember: false);
            $request->session()->regenerate();

            if ($user->role === UserRole::Student) {
                // Ghi cả last_login_at; lỗi ghi → đăng xuất phiên mới và ném lại (không có 2 phiên hợp lệ).
                $this->sessions->bind($user, $request);

                return;
            }
        } catch (Throwable $e) {
            if ($snapshot !== '') {
                Session::getHandler()->write($oldId, $snapshot);
            }

            throw $e;
        }

        $user->forceFill(['last_login_at' => now()])->save();
    }

    public function logout(Request $request): void
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            // AC3: đặt `logged_out` TRƯỚC khi huỷ session; chỉ khi phiên này còn là phiên hiện hành.
            $this->sessions->release($user, $request->session()->getId());
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** Dùng chung với đăng nhập quản trị (T28). */
    public static function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return User::query()->where('email', mb_strtolower($login))->first();
        }

        $phone = PhoneNumber::normalize($login);

        return $phone === null ? null : User::query()->where('phone', $phone)->first();
    }

    /** Dùng chung với đăng nhập quản trị (T28). */
    public static function dummyHash(): string
    {
        // PHP-FPM khởi tạo lại biến static mỗi request nên PHẢI cache liên request, nếu không mỗi lần gọi tốn thêm
        // 1 lần băm (nhánh "không tồn tại" chậm gấp đôi → lộ tài khoản). Khoá theo cost hiện hành để cùng cost hash thật.
        if (self::$dummyHash !== null && self::$dummyHashRounds === self::currentRounds()) {
            return self::$dummyHash;
        }

        $rounds = self::currentRounds();
        $hash = Cache::rememberForever('auth.dummy_hash:'.$rounds, fn () => Hash::make('vv-dummy-password-for-timing'));
        self::$dummyHashRounds = $rounds;

        return self::$dummyHash = $hash;
    }

    private static function currentRounds(): string
    {
        return config('hashing.driver', 'bcrypt').':'.config('hashing.bcrypt.rounds').':'.config('hashing.argon.time', '');
    }
}
