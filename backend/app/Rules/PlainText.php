<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Văn bản thuần (S8): không thẻ HTML, `<`/`>` hay ký tự điều khiển. Dùng cho tên/mô tả ngắn render bằng text.
 */
class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[<>\p{Cc}]/u', $value) === 1)) {
            $fail('Trường :attribute chỉ được chứa văn bản thuần, không có thẻ HTML hay ký tự điều khiển.');
        }
    }
}
