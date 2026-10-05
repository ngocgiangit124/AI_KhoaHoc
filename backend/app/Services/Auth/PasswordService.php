<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Otp\OtpService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Quên / đặt lại / đổi mật khẩu học sinh (US-015, T27).
 *
 * - Mọi lần huỷ phiên đi qua `StudentSessionService::revoke()` (tombstone `password_changed`, ADR-003).
 * - OTP dùng lại `OtpService` (purpose `reset_password`), không viết lại logic OTP.
 */
class PasswordService
{
    public const MESSAGE_FORGOT = 'Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.';

    public const MESSAGE_RESET_DONE = 'Mật khẩu đã được đặt lại. Vui lòng đăng nhập bằng mật khẩu mới.';

    public const MESSAGE_CHANGED = 'Đã đổi mật khẩu. Các thiết bị khác đã bị đăng xuất.';

    public const MESSAGE_WRONG_CURRENT = 'Mật khẩu hiện tại không đúng.';

    public function __construct(
        private readonly OtpService $otp,
        private readonly StudentSessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /** Tài khoản được phép đặt lại mật khẩu: học sinh, không khoá, chưa ẩn danh hoá (BR8). */
    public static function isEligible(?User $user): bool
    {
        return $user !== null
            && $user->role === UserRole::Student
            && $user->status === UserStatus::Active
            && $user->anonymized_at === null
            && is_string($user->email) && $user->email !== '';
    }

    /**
     * Danh tính cho hạn mức quên/đặt lại mật khẩu (guest): tài khoản có thật → theo id (email và SĐT của cùng
     * 1 người dùng chung hạn mức); không có → khoá chuẩn hoá `LoginService::accountKey`. Hai nhánh bị giới hạn
     * y hệt nhau nên 429 không lộ tài khoản tồn tại.
     */
    public static function throttleIdentity(string $login): string
    {
        $user = $login === '' ? null : LoginService::findByLogin($login);

        return $user !== null ? 'u:'.$user->getKey() : 'acct:'.LoginService::accountKey($login);
    }

    /**
     * Hạn mức theo tài khoản của `forgot` (cooldown 60s + 5/giờ). Gọi SAU captcha (R2 review T27).
     *
     * @throws ThrottleRequestsException
     */
    public function enforceForgotLimits(string $login): void
    {
        $identity = self::throttleIdentity($login);
        $limits = [
            ['password-reset-cooldown:'.$identity, 1, 60],
            ['password-reset:'.$identity, 5, 3600],
        ];

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
            }
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /**
     * Gửi OTP `reset_password` qua email (kể cả email chưa xác thực). Chạy SAU khi đã trả response
     * (controller dùng `defer`) nên thời gian phản hồi và mã trạng thái không lộ tài khoản có tồn tại;
     * mọi lỗi (trần OTP, gửi mail) bị nuốt và chỉ ghi log không chứa mã/PII.
     */
    public function sendResetCode(string $login): void
    {
        try {
            $user = LoginService::findByLogin($login);

            if (! self::isEligible($user)) {
                return;
            }

            $this->otp->issue($user, OtpPurpose::ResetPassword, 'email', (string) $user->email);
        } catch (ThrottleRequestsException) {
            // Vượt trần OTP của tài khoản: không báo ra ngoài (không phân biệt tồn tại/không tồn tại).
        } catch (Throwable $e) {
            Log::warning('password_reset.send_failed', ['exception' => $e::class]);
        }
    }

    /**
     * Đặt lại mật khẩu bằng OTP. Mọi trường hợp tài khoản không tồn tại / bị khoá / không có mã đều trả
     * cùng lỗi "mã hết hạn" như khi tài khoản thật không có mã (không lộ tồn tại, BR2/BR8).
     *
     * @throws ValidationException mã sai / hết hạn (field `code`)
     */
    public function reset(string $login, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $newPassword): void
    {
        $user = LoginService::findByLogin($login);

        if (! self::isEligible($user)) {
            // Cân bằng thời gian với nhánh có tài khoản (so hash).
            Hash::check($code, LoginService::dummyHash());

            throw ValidationException::withMessages(['code' => OtpService::MESSAGE_EXPIRED]);
        }

        // Cân bằng thời gian: tài khoản có thật nhưng không có mã hiệu lực cũng tốn 1 lần băm (R1 review T27).
        $current = OtpCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', OtpPurpose::ResetPassword->value)
            ->whereNull('consumed_at')->whereNull('invalidated_at')
            ->orderByDesc('id')
            ->first();
        $active = $current !== null && $current->expires_at->isFuture()
            && $user->email !== null && hash_equals($current->destination, $user->email)
            && $current->attempts < (int) config('auth.otp.max_attempts_per_code');

        if (! $active) {
            Hash::check($code, LoginService::dummyHash());
        }

        $destinationValid = static fn (OtpCode $otp, User $u): bool => $u->email !== null && hash_equals($otp->destination, $u->email);

        $this->otp->consume(
            $user,
            OtpPurpose::ResetPassword,
            $code,
            $destinationValid,
            function (OtpCode $otp, User $locked) use ($newPassword): void {
                // Bị khoá/ẩn danh sau khi mã được gửi → không hoàn tất (BR8); rollback cả việc tiêu thụ mã.
                if (! self::isEligible($locked)) {
                    throw ValidationException::withMessages(['code' => OtpService::MESSAGE_EXPIRED]);
                }

                $this->applyNewPassword($locked, $newPassword);
            },
        );

        $this->audit->log('account.password_reset', $user, []);
    }

    /**
     * Đổi mật khẩu khi đang đăng nhập (AC4/AC5): huỷ phiên khác, rồi bind lại phiên hiện tại để người đổi
     * không bị văng (BR5). Nếu bind lỗi: mật khẩu đã đổi và mọi phiên đã huỷ (fail-safe), request vẫn 200
     * nhưng không còn phiên (FE gọi `/auth/me` → 401 → `/dang-nhap`) — cùng nguyên tắc R2 của T05.
     *
     * @throws ValidationException sai mật khẩu hiện tại
     */
    public function change(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, Request $request): bool
    {
        if (! Hash::check($current, $user->password)) {
            $this->audit->log('account.password_change_failed', $user, ['reason' => 'wrong_current_password']);

            throw ValidationException::withMessages(['current_password' => self::MESSAGE_WRONG_CURRENT]);
        }

        $deviceId = $user->current_device_id;

        DB::transaction(function () use ($user, $new): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->applyNewPassword($locked, $new);
        });

        $user->refresh();
        $this->audit->log('account.password_changed', $user, []);

        try {
            // Session fixation: id mới, rồi bind (ghi current_session_id mới; old = logged_out nên không tombstone).
            $request->session()->regenerate();
            $this->sessions->bind($user, $request, $deviceId);
        } catch (Throwable $e) {
            Log::warning('password_change.rebind_failed', ['exception' => $e::class]);

            return false;
        }

        return true;
    }

    /**
     * Gọi TRONG transaction đã khoá hàng user: đổi mật khẩu và huỷ phiên hiện hành cùng lúc, nên không có
     * lúc nào 2 mật khẩu cùng hợp lệ hay phiên cũ còn sống với mật khẩu mới.
     */
    private function applyNewPassword(User $locked, #[\SensitiveParameter] string $newPassword): void
    {
        $locked->forceFill([
            'password' => $newPassword, // cast `hashed`
            'password_changed_at' => now(),
        ])->save();

        $this->sessions->revoke($locked, StudentSessionService::REASON_PASSWORD_CHANGED);
    }
}
