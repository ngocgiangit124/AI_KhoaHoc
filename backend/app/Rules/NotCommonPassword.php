<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Chặn mật khẩu phổ biến bằng danh sách CỤC BỘ (`resources/data/common-passwords.txt`, ~12k mục từ 8 ký tự, chữ thường,
 * gồm cả mẫu tiếng Việt và tên hệ thống). Cố ý KHÔNG dùng quy tắc kiểm rò rỉ của framework (gọi HIBP ở nước ngoài, PO không
 * cho chuyển dữ liệu ra ngoài nước). So khớp không phân biệt hoa/thường.
 */
class NotCommonPassword implements ValidationRule
{
    public const MESSAGE = 'Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác.';

    /** @var array<string, true>|null */
    private static ?array $list = null;

    public static function isCommon(string $password): bool
    {
        return isset(self::list()[mb_strtolower(trim($password))]);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isCommon($value)) {
            $fail(self::MESSAGE);
        }
    }

    /** @return array<string, true> */
    private static function list(): array
    {
        if (self::$list === null) {
            $lines = file(resource_path('data/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::$list = array_fill_keys($lines, true);
        }

        return self::$list;
    }
}
