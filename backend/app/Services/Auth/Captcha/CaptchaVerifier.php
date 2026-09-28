<?php

namespace App\Services\Auth\Captcha;

/**
 * Xác minh captcha chống bot (US-001 — đăng ký; US-015/T27 — quên mật khẩu).
 * Bind theo `config('captcha.driver')` — xem `AppServiceProvider::register()`.
 */
interface CaptchaVerifier
{
    public function verify(string $token, ?string $ip = null): bool;
}
