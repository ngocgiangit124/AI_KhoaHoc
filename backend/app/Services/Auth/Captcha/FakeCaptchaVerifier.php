<?php

namespace App\Services\Auth\Captcha;

/**
 * Chỉ dùng ở local/testing (ProductionConfigGuard cấm CAPTCHA_DRIVER=fake ở
 * production). Mọi token KHÔNG RỖNG đều qua, trừ token đúng bằng `invalid` (để test đường lỗi).
 */
class FakeCaptchaVerifier implements CaptchaVerifier
{
    public function verify(?string $token, ?string $ip = null): bool
    {
        return $token !== null && $token !== '' && $token !== 'invalid';
    }
}
