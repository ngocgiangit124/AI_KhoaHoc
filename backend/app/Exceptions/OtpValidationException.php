<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Lỗi mã OTP: vẫn là 422 + `errors.code[]` như ValidationException (FE cũ vẫn hiển thị được) nhưng
 * `code` của envelope là mã riêng `OTP_INVALID` / `OTP_EXPIRED` (api-contract §1.7).
 */
class OtpValidationException extends ValidationException
{
    public const INVALID = 'OTP_INVALID';

    public const EXPIRED = 'OTP_EXPIRED';

    public string $errorCode = self::INVALID;

    public static function invalid(string $message): static
    {
        return self::make(self::INVALID, $message);
    }

    public static function expired(string $message): static
    {
        return self::make(self::EXPIRED, $message);
    }

    private static function make(string $errorCode, string $message): static
    {
        $e = static::withMessages(['code' => $message]);
        $e->errorCode = $errorCode;

        return $e;
    }
}
