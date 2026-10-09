<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * GL-A2: lỗi đăng nhập 422 kèm cờ `captcha_required` (top-level trong envelope) để FE biết có cần hiện widget Turnstile
 * cho lần gửi sau. Ba biến thể: sai thông tin (`VALIDATION_ERROR`, `errors.login`), thiếu captcha (`CAPTCHA_REQUIRED`),
 * captcha sai (`CAPTCHA_INVALID`; cả hai đặt `errors.captcha_token`). Cờ chỉ phụ thuộc bộ đếm theo định danh đã chuẩn hoá
 * nên tài khoản không tồn tại hành xử y hệt tài khoản thật.
 */
class LoginChallengeException extends ValidationException
{
    public const REQUIRED = 'CAPTCHA_REQUIRED';

    public const INVALID = 'CAPTCHA_INVALID';

    public string $errorCode = 'VALIDATION_ERROR';

    public bool $captchaRequired = false;

    public static function badCredentials(string $message, bool $captchaRequired): static
    {
        $e = static::withMessages(['login' => $message]);
        $e->captchaRequired = $captchaRequired;

        return $e;
    }

    public static function captchaRequired(): static
    {
        return self::captcha(self::REQUIRED, 'Vui lòng xác minh captcha để tiếp tục đăng nhập.');
    }

    public static function captchaInvalid(): static
    {
        return self::captcha(self::INVALID, 'Xác minh captcha không thành công. Vui lòng thử lại.');
    }

    private static function captcha(string $errorCode, string $message): static
    {
        $e = static::withMessages(['captcha_token' => $message]);
        $e->errorCode = $errorCode;
        $e->captchaRequired = true;

        return $e;
    }
}
