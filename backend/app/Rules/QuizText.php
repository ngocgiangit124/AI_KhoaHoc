<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Văn bản quiz (S8): văn bản thuần có thể chứa công thức `$...$`. Cho phép `<`/`>` đứng riêng (toán học: `a < b`,
 * `x>2`) nhưng từ chối mọi thứ trông như thẻ HTML/comment/PI và ký tự điều khiển (trừ xuống dòng, tab). Frontend
 * hiển thị bằng text của React + KaTeX (trust: false), không bao giờ dùng innerHTML.
 */
class QuizText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $fail('Trường :attribute không phải UTF-8 hợp lệ.');

            return;
        }

        // Ký tự điều khiển, ký tự đảo chiều hiển thị (bidi) và ký tự độ rộng bằng 0 (che nội dung) bị từ chối.
        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', $value) === 1
            || preg_match('/[\p{Cc}]/u', str_replace(["\n", "\r", "\t"], '', $value)) === 1
            || preg_match('/<[a-zA-Z\/!?]/', $value) === 1) {
            $fail('Trường :attribute chỉ được chứa văn bản thuần (công thức viết trong $...$), không có thẻ HTML. Dùng \lt, \gt cho dấu so sánh nếu cần.');
        }
    }
}
