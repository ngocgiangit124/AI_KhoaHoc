<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Normalizer;

/**
 * Xác thực đăng nhập quản trị (host admin-api — T28, api-contract §2.5).
 *
 * Cố ý KHÔNG tái dùng `App\Services\Auth\LoginService` (học sinh, T03/T05
 * đang phát triển song song ở worktree khác) — logic gần giống nhưng vai trò
 * chấp nhận và khoá throttle khác nhau; tách file riêng để không đụng vào
 * code đang được task khác sửa.
 *
 * Thứ tự bắt buộc theo api-contract §1.7/§2.5 (không được đảo — cùng lý do
 * với `LoginService`, xem docblock ở đó):
 * 0. Vượt "10 lần SAI/giờ" theo tài khoản → 429 `TOO_MANY_ATTEMPTS`.
 * 1. Sai định danh/mật khẩu → 422 thông điệp CHUNG (BR5, S20).
 * 2. Mật khẩu đúng nhưng tài khoản bị khoá → 403 `ACCOUNT_LOCKED`.
 * 3. Mật khẩu đúng, vai trò là `hoc_sinh` (không phải staff/GV) → 403
 *    `WRONG_PORTAL` — host admin-api chỉ dành cho admin/quản lý trang/GV.
 */
class StaffAuthService
{
    private const ACCOUNT_MAX_ATTEMPTS = 10;

    private const ACCOUNT_DECAY_SECONDS = 3600;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function authenticate(string $login, string $password): User
    {
        $throttleKey = 'staff-login:'.self::normalizeIdentity($login);

        if (RateLimiter::tooManyAttempts($throttleKey, self::ACCOUNT_MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(
                'Bạn thao tác quá nhanh, vui lòng thử lại sau.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)],
            );
        }

        $user = $this->findByLogin($login);

        // M1 (LoginService) — Hash::check() LUÔN chạy dù tìm thấy tài khoản
        // hay không, cùng cost với cấu hình thật, để thời gian phản hồi không
        // tiết lộ tài khoản có tồn tại hay không (S20, BR5).
        $passwordOk = Hash::check($password, $user !== null ? $user->password : self::dummyHash());

        if ($user === null || ! $passwordOk) {
            RateLimiter::hit($throttleKey, self::ACCOUNT_DECAY_SECONDS);

            // Chỉ ghi audit khi XÁC ĐỊNH được tài khoản (sai mật khẩu của 1
            // tài khoản staff/GV có thật) — bỏ qua khi định danh không khớp
            // ai (không có gì hữu ích để tham chiếu, và tránh tạo hiệu ứng
            // phụ có thể quan sát được theo định danh gõ vào — BR5).
            if ($user !== null) {
                $this->auditLogger->log('staff.login_failed', $user, ['reason' => 'invalid_credentials']);
            }

            throw self::genericFailure();
        }

        if ($user->status === UserStatus::Locked) {
            // README §3.2/api-contract §2.5 — ghi audit `staff.login_failed`
            // (chỉ khi ĐÃ xác định được tài khoản — mật khẩu đúng — không ghi
            // cho định danh không khớp ai để không tạo bản ghi vô nghĩa).
            $this->auditLogger->log('staff.login_failed', $user, ['reason' => 'account_locked']);

            throw new DomainException(
                code: 'ACCOUNT_LOCKED',
                message: 'Tài khoản của bạn đã bị khoá.',
                status: 403,
            );
        }

        if ($user->role === UserRole::Student) {
            $this->auditLogger->log('staff.login_failed', $user, ['reason' => 'wrong_portal']);

            throw new DomainException(
                code: 'WRONG_PORTAL',
                message: 'Vui lòng đăng nhập đúng cổng dành cho vai trò của bạn.',
                status: 403,
            );
        }

        RateLimiter::clear($throttleKey);

        return $user;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }

    private static function genericFailure(): ValidationException
    {
        return ValidationException::withMessages([
            'login' => ['Thông tin đăng nhập hoặc mật khẩu không đúng.'],
        ]);
    }

    private function findByLogin(string $login): ?User
    {
        $normalized = self::normalizeIdentity($login);

        if ($normalized === '' || preg_match('/[^\x21-\x7E]/', $normalized) === 1) {
            return null;
        }

        if (str_contains($normalized, '@')) {
            return User::query()->where('email', $normalized)->first();
        }

        return User::query()->where('phone', $normalized)->first();
    }

    /**
     * Cùng cách chuẩn hoá với `LoginService::normalizeIdentity()` (email
     * hoặc SĐT) — xem docblock ở đó cho lý do NFKC + `PhoneNumber`.
     */
    private static function normalizeIdentity(string $login): string
    {
        $normalizedForm = Normalizer::normalize(trim($login), Normalizer::FORM_KC);
        $trimmed = mb_strtolower($normalizedForm !== false ? $normalizedForm : trim($login));

        if ($trimmed === '' || str_contains($trimmed, '@')) {
            return $trimmed;
        }

        try {
            return PhoneNumber::fromInput($trimmed)->value();
        } catch (InvalidArgumentException) {
            return $trimmed;
        }
    }
}
