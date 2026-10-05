<?php

namespace App\Services\Auth;

/**
 * Chuẩn hoá số điện thoại Việt Nam về dạng `0xxxxxxxxx` (10 số) trước khi
 * lưu/tra cứu (data-model §3.1). Chấp nhận `+84`/`84` đầu số, khoảng trắng,
 * dấu chấm, gạch ngang, ngoặc.
 */
final class PhoneNumber
{
    /** Đầu số di động VN hợp lệ: 03x, 05x, 07x, 08x, 09x + 8 số. */
    private const PATTERN = '/^0[35789]\d{8}$/';

    /**
     * @return string|null `null` nếu không phải số di động Việt Nam hợp lệ.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Chỉ cho phép chữ số và ký tự định dạng thường gặp; chặn mọi thứ khác.
        if ($value === '' || preg_match('/^\+?[\d\s().\-]+$/', $value) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match(self::PATTERN, $digits) === 1 ? $digits : null;
    }
}
