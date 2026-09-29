<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Chặn HTML/thẻ trong các trường "văn bản thuần" (ví dụ `subjects.name` —
 * US-011 Trường hợp biên & lỗi: "Tên chuyên đề chứa ký tự đặc biệt/HTML").
 * So khớp `strip_tags()` thay vì chỉ chặn `<`/`>` để bắt cả thực thể như
 * `&lt;script&gt;` sau khi decode ở tầng khác — ở đây kiểm nguyên văn input.
 */
class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (strip_tags($value) !== $value) {
            $fail('Trường :attribute không được chứa thẻ HTML.');
        }
    }
}
