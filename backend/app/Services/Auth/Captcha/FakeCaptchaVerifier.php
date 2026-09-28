<?php

namespace App\Services\Auth\Captcha;

/**
 * Chỉ bind ở local/testing (`CAPTCHA_DRIVER` khác `turnstile`) — production cấm
 * `fake` qua `ProductionConfigGuard` (M4, docs/security/review-T01-T02.md).
 *
 * `INVALID_TOKEN` là giá trị quy ước dùng trong test Pest để mô phỏng captcha
 * sai (422 `CAPTCHA_FAILED`) — không phải hành vi thật của Turnstile.
 */
class FakeCaptchaVerifier implements CaptchaVerifier
{
    public const INVALID_TOKEN = 'test-invalid-captcha';

    public function verify(string $token, ?string $ip = null): bool
    {
        return $token !== '' && $token !== self::INVALID_TOKEN;
    }
}
