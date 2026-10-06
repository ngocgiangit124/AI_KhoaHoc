<?php

namespace App\Services\Auth\Staff;

use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginService;
use App\Services\Auth\Otp\OtpService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Đăng nhập quản trị ở host admin-api (T28, US-016, ADR-004 §2.2/§3). Phiên staff chỉ tồn tại ở
 * host này (cookie `vv_admin_session`); không dùng cơ chế một-thiết-bị của học sinh.
 */
class StaffAuthService
{
    public const GENERIC_FAILURE = LoginService::GENERIC_FAILURE;

    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
        private readonly StaffDeviceService $devices,
    ) {}

    /**
     * @return array{user: User, mfa_required: bool, resend_available_at: CarbonImmutable|null}
     *
     * @throws ValidationException sai thông tin (thông điệp chung)
     * @throws DomainException WRONG_PORTAL / ACCOUNT_LOCKED (chỉ sau khi mật khẩu ĐÚNG — S20), OTP_DELIVERY_FAILED
     * @throws ThrottleRequestsException
     */
    public function login(string $login, #[\SensitiveParameter] string $password, Request $request): array
    {
        // M2/M3 (như LoginService::attempt): khoá theo user id, đếm nguyên tử trước khi so mật khẩu.
        $user = LoginService::findByLogin($login);
        $accountKey = 'staff-login-fail:'.LoginService::throttleSubject($login, $user);
        $ipKey = 'staff-login-fail-ip:'.$request->ip();

        LoginService::reserveAttempts([
            [$accountKey, (int) config('auth.staff.login_max_failures_per_account')],
            [$ipKey, (int) config('auth.staff.login_max_failures_per_ip')],
        ]);

        // Luôn băm 1 lần dù không có tài khoản: thời gian phản hồi không lộ tài khoản tồn tại.
        $passwordOk = Hash::check($password, $user !== null ? $user->password : LoginService::dummyHash());

        if ($user === null || ! $passwordOk) {
            $this->audit->log('staff.login_failed', $user, ['reason' => 'bad_credentials']);

            throw ValidationException::withMessages(['login' => self::GENERIC_FAILURE]);
        }

        // Chỉ đếm lượt SAI: mật khẩu đúng thì hoàn lượt đã giữ chỗ.
        LoginService::releaseAttempts($accountKey, $ipKey);

        if ($user->role === UserRole::Student) {
            $this->audit->log('staff.login_failed', $user, ['reason' => 'wrong_portal']);

            throw new DomainException(
                code: 'WRONG_PORTAL',
                message: 'Vui lòng đăng nhập tại trang dành cho bạn.',
                status: 403,
            );
        }

        if ($user->status === UserStatus::Locked) {
            $this->audit->log('staff.login_failed', $user, ['reason' => 'locked']);

            throw new DomainException(
                code: 'ACCOUNT_LOCKED',
                message: 'Tài khoản của bạn đã bị khoá.',
                status: 403,
            );
        }

        RateLimiter::clear($accountKey);

        $mfaRequired = StaffSession::requiresMfa($user);
        $resendAt = null;

        if ($mfaRequired) {
            // Gửi mã TRƯỚC khi cấp phiên: gửi lỗi/vượt trần (429, 503) thì không để lại phiên dở dang.
            $resendAt = $this->issueMfaCode($user);
        }

        // `login()` tự đổi session id (chống fixation) nhưng giữ `_token` CSRF; phiên cũ của người khác
        // trên cùng trình duyệt (nếu có) bị thay thế. Chưa có `staff_mfa_passed` thì phiên chỉ dùng được
        // cho /admin/auth/mfa/*, /admin/auth/logout (middleware `staff.mfa_passed`).
        Auth::guard('web')->login($user, remember: false);
        StaffSession::start($request->session(), mfaPassed: ! $mfaRequired, userId: $user->getKey());

        if ($mfaRequired) {
            $this->audit->log('staff.login_mfa_sent', $user);
        } else {
            $this->completeLogin($user, $request, mfa: false);
        }

        return ['user' => $user, 'mfa_required' => $mfaRequired, 'resend_available_at' => $resendAt];
    }

    /**
     * @throws ValidationException mã sai/hết hạn (field `code`)
     * @throws DomainException TOO_MANY_ATTEMPTS khi mã đã hết lượt
     */
    public function verifyMfa(User $user, #[\SensitiveParameter] string $code, Request $request): void
    {
        if (! StaffSession::requiresMfa($user) || StaffSession::mfaPassed($request->session())) {
            return; // idempotent: bấm hai lần không báo lỗi
        }

        try {
            $this->otp->consume(
                $user,
                OtpPurpose::StaffLoginMfa,
                $code,
                static fn (OtpCode $otp, User $u): bool => hash_equals($otp->destination, (string) $u->email),
            );
        } catch (ValidationException $e) {
            $reason = ($e->errors()['code'][0] ?? null) === OtpService::MESSAGE_WRONG ? 'wrong_code' : 'expired';
            $this->audit->log('staff.mfa_failed', $user, ['reason' => $reason]);

            throw $e;
        } catch (DomainException $e) {
            $this->audit->log('staff.mfa_failed', $user, ['reason' => 'too_many_attempts']);

            throw $e;
        }

        $request->session()->regenerate();
        $request->session()->put(StaffSession::MFA_PASSED, true);
        $request->session()->put(StaffSession::LAST_ACTIVITY, now()->getTimestamp());

        $this->completeLogin($user, $request, mfa: true);
    }

    /**
     * Gửi lại mã MFA cho phiên đang chờ MFA (cooldown 60s, ≤ 5/giờ, ≤ 10/ngày — OtpService).
     *
     * @throws DomainException ALREADY_PROCESSED nếu phiên không ở trạng thái chờ MFA
     */
    public function resendMfa(User $user, Request $request): CarbonImmutable
    {
        if (! StaffSession::requiresMfa($user) || StaffSession::mfaPassed($request->session())) {
            throw new DomainException(
                code: 'ALREADY_PROCESSED',
                message: 'Phiên đăng nhập này không cần xác thực thêm.',
                status: 409,
            );
        }

        return $this->issueMfaCode($user);
    }

    public function logout(Request $request): void
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $this->audit->log('staff.logout', $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Đổi mật khẩu: huỷ MỌI phiên khác (Sanctum AuthenticateSession so băm mật khẩu), giữ phiên hiện tại.
     *
     * @throws ValidationException sai mật khẩu hiện tại
     */
    public function changePassword(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, Request $request): void
    {
        if (! Hash::check($current, $user->password)) {
            $this->audit->log('staff.password_change_failed', $user, ['reason' => 'wrong_current_password']);

            throw ValidationException::withMessages(['current_password' => 'Mật khẩu hiện tại không đúng.']);
        }

        $forced = (bool) $user->must_change_password;

        DB::transaction(function () use ($user, $new): void {
            $user->forceFill([
                'password' => $new, // cast `hashed`
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            // Băm lại + cập nhật để mọi session khác (băm cũ trong session) bị AuthenticateSession huỷ ở
            // request kế tiếp; phiên hiện tại được AuthenticateSession ghi băm mới sau response.
            /** @var SessionGuard $guard */
            $guard = Auth::guard('web');
            $guard->logoutOtherDevices($new);
        });

        $request->session()->regenerate();
        $this->audit->log('staff.password_changed', $user, ['forced' => $forced]);
    }

    private function issueMfaCode(User $user): CarbonImmutable
    {
        return $this->otp->issue($user, OtpPurpose::StaffLoginMfa, 'email', (string) $user->email);
    }

    private function completeLogin(User $user, Request $request, bool $mfa): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('staff.login', $user, ['mfa' => $mfa]);

        if ($user->role === UserRole::Teacher) {
            $this->devices->recordAndWarnIfNew($user, $request);
        }
    }
}
