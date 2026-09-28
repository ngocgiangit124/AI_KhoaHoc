<?php

namespace App\Services\Payments\Support;

/**
 * Parse số tiền nghiêm ngặt (S12.3, ADR-001 §2 mục "Kiểm IPN"): chỉ nhận
 * số nguyên (`int`) hoặc chuỗi toàn chữ số. Từ chối chuỗi thập phân
 * (`"100000.0"`), ký hiệu khoa học (`"1e5"`), số âm, rỗng, hoặc kiểu dữ
 * liệu khác (float, bool, null, array...).
 */
final class StrictAmountParser
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
