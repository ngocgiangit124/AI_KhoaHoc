<?php

namespace App\Services\Auth\Captcha;

interface CaptchaVerifier
{
    /**
     * Phải fail-closed: mọi lỗi (mạng, phản hồi lạ, token rỗng) → false.
     */
    public function verify(?string $token, ?string $ip = null): bool;
}
