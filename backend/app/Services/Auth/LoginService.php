<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\AtomicCounter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    private const ACCOUNT_MAX_FAILURES = 10;

    private const IP_MAX_FAILURES = 50;

    private const DECAY_SECONDS = 3600;

    public const GENERIC_FAILURE = 'Thông tin đăng nhập hoặc mật khẩu không đúng.';

    /**
     * @throws ValidationException sai thông tin (thông điệp chung, BR5)
     * @throws DomainException ACCOUNT_LOCKED / WRONG_PORTAL (chỉ sau khi mật khẩu ĐÚNG — S20)
     */
    public function attempt(string $login, string $password, Request $request): User
    {
        // M2: tìm tài khoản TRƯỚC để khoá đếm theo user id (DB so khớp email không phân biệt dấu/hoa thường, nên mọi
        // cách viết của 1 email phải dùng chung 1 bộ đếm). Không có tài khoản → khoá chuẩn hoá (bỏ dấu, hạ chữ).
        $user = self::findByLogin($login);
        $accountKey = 'login-fail:'.self::throttleSubject($login, $user);
        $ipKey = 'login-fail-ip:'.$request->ip();

        // M3: đếm NGUYÊN TỬ trước khi so mật khẩu (`hit` = INCR). Request đồng thời mỗi cái nhận 1 số thứ tự riêng, chỉ
        // ngưỡng đầu tiên được so mật khẩu; vượt ngưỡng thì mật khẩu đúng cũng bị chặn (S10).
        self::reserveAttempts([[$accountKey, self::ACCOUNT_MAX_FAILURES], [$ipKey, self::IP_MAX_FAILURES]]);

        // Luôn băm 1 lần dù không có tài khoản, để thời gian phản hồi không lộ tài khoản tồn tại.
        $hash = $user !== null ? $user->password : self::dummyHash();
        $passwordOk = Hash::check($password, $hash);

        if ($user === null || ! $passwordOk) {
            // Lượt sai: giữ nguyên số đã đếm ở trên.
            throw ValidationException::withMessages(['login' => self::GENERIC_FAILURE]);
        }

        // Chỉ đếm lượt SAI (contract §1.6): mật khẩu đúng thì hoàn lượt đã giữ chỗ (kể cả khi sau đó bị WRONG_PORTAL/LOCKED).
        self::releaseAttempts($accountKey, $ipKey);

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
     * Giữ chỗ 1 lượt ở MỌI khoá (tài khoản + IP) trước khi so mật khẩu.
     * 1) Kiểm chỉ-đọc: có khoá nào đã đạt ngưỡng → 429 ngay, KHÔNG hit gì (IP bị chặn không lan sang tài khoản
     *    vô tội).
     * 2) Không khoá nào đạt → `AtomicCounter::hit` (INCR nguyên tử, Redis/Lua). Giá trị trả về > ngưỡng (đua) → 429 và
     *    KHÔNG so mật khẩu; không hoàn lượt ở đường chặn (bộ đếm tối đa ngưỡng + số request đồng thời).
     *
     * @param  list<array{0: string, 1: int}>  $limits  [khoá, ngưỡng tối đa số lượt sai]
     *
     * @throws ThrottleRequestsException
     */
    public static function reserveAttempts(array $limits): void
    {
        foreach ($limits as [$key, $max]) {
            if (AtomicCounter::attempts($key) >= $max) {
                throw self::throttled($key);
            }
        }

        foreach ($limits as [$key, $max]) {
            if (AtomicCounter::hit($key, self::DECAY_SECONDS) > $max) {
                throw self::throttled($key);
            }
        }
    }

    private static function throttled(string $key): ThrottleRequestsException
    {
        return new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => (string) AtomicCounter::availableIn($key)]);
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
