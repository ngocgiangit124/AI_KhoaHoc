<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Đăng nhập học sinh ở host api (US-001). Bind phiên 1 thiết bị (ADR-003) là T05:
 * mọi nơi bắt đầu phiên học sinh phải đi qua `startSession()` để T05 gắn `bind()` vào MỘT chỗ.
 */
class LoginService
{
    private static ?string $dummyHash = null;

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
        $accountKey = 'login-fail:'.self::accountKey($login);
        $ipKey = 'login-fail-ip:'.$request->ip();

        // Kiểm TRƯỚC khi so mật khẩu: bị khoá thì mật khẩu đúng cũng không vào được (S10).
        foreach ([[$accountKey, self::ACCOUNT_MAX_FAILURES], [$ipKey, self::IP_MAX_FAILURES]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException(
                    'Too Many Attempts.',
                    null,
                    ['Retry-After' => (string) RateLimiter::availableIn($key)],
                );
            }
        }

        $user = $this->findByLogin($login);

        // Luôn băm 1 lần dù không có tài khoản, để thời gian phản hồi không lộ tài khoản tồn tại.
        $hash = $user !== null ? $user->password : self::dummyHash();
        $passwordOk = Hash::check($password, $hash);

        if ($user === null || ! $passwordOk) {
            // Chỉ đếm lượt SAI (contract §1.6); đăng nhập đúng không tiêu hao hạn mức.
            RateLimiter::hit($accountKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['login' => self::GENERIC_FAILURE]);
        }

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
     * Khoá theo tài khoản phải chuẩn hoá: `0912…`, `+84912…`, `84 912…` là cùng 1 SĐT,
     * email không phân biệt hoa/thường (nếu không, đổi cách viết là né được giới hạn).
     */
    public static function accountKey(string $login): string
    {
        $login = trim($login);

        if (! str_contains($login, '@') && ($phone = PhoneNumber::normalize($login)) !== null) {
            return $phone;
        }

        return mb_strtolower(mb_substr($login, 0, 254));
    }

    /**
     * Đăng nhập session (KHÔNG remember-me) + đổi session id chống session fixation.
     * TODO(T05): gọi StudentSessionService::bind() tại đây (ADR-003).
     */
    public function startSession(Request $request, User $user): void
    {
        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return User::query()->where('email', mb_strtolower($login))->first();
        }

        $phone = PhoneNumber::normalize($login);

        return $phone === null ? null : User::query()->where('phone', $phone)->first();
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('vv-dummy-password-for-timing');
    }
}
