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
        if (! is_string($value)) {
            return;
        }

        // Cụm 2 L4: chặn thêm ký tự bidi (U+202A–U+202E, U+2066–U+2069: đảo chiều hiển thị/giả mạo tên) và zero-width
        // (U+200B, U+200C, U+FEFF; U+200D chỉ cho ghép emoji: tên "trống"/trùng nhìn giống nhau); UTF-8 hỏng (preg_match === false) cũng bị từ chối.
        $invisible = '/[<>\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{200C}\x{FEFF}]/u';

        // R7: ZWJ (U+200D) chỉ được dùng để ghép emoji (👨‍👩‍👧): hợp lệ khi đứng giữa hai ký tự Extended_Pictographic
        // (cho phép FE0F/tông da ngay trước). ZWJ đứng lẻ hoặc giữa chữ thì bị chặn.
        $strayJoiner = '/(?<![\p{Extended_Pictographic}\x{FE0F}\x{1F3FB}-\x{1F3FF}])\x{200D}|\x{200D}(?!\p{Extended_Pictographic})/u';

        if (strip_tags($value) !== $value || preg_match($invisible, $value) !== 0 || preg_match($strayJoiner, $value) !== 0) {
            $fail('Trường :attribute chỉ được chứa văn bản thuần, không có thẻ HTML, ký tự điều khiển hay ký tự ẩn.');
        }
    }
}
