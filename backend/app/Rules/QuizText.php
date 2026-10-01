<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "Văn bản thuần có đoạn LaTeX `$...$`" của quiz (api-contract §4, S8): cấm
 * HTML nhưng KHÔNG cấm dấu `<` trong toán học.
 *
 * Không dùng `PlainText` (so `strip_tags`) vì `strip_tags` coi mọi `<` không
 * theo sau khoảng trắng là mở thẻ — `$x<3$` bị cắt còn `$x`, `1<2 và 3>2`
 * còn `12` nên chặn nhầm nội dung Toán hợp lệ. Ở đây chặn `<` theo sau là
 * chữ cái, `/`, `!`, `?` (bắt đầu thẻ/comment/khai báo — kể cả thẻ chưa
 * đóng), cho phép `<` theo sau là số/khoảng trắng/ký hiệu (`x<3`, `a < b`).
 * `x<y` (chữ cái ngay sau `<`) bị chặn có chủ đích — dùng `x < y` hoặc `\lt`.
 * Đồng thời cấm ký tự điều khiển (trừ xuống dòng/tab).
 *
 * Đây là lớp phòng thủ chiều sâu: frontend vẫn render text của React và KaTeX
 * `trust: false`, tuyệt đối không `dangerouslySetInnerHTML`.
 */
class QuizText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('/<[A-Za-z\/!?]/', $value) === 1) {
            $fail('Trường :attribute không được chứa thẻ HTML (dùng "x < y" hoặc \lt cho dấu nhỏ hơn trong công thức).');

            return;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $fail('Trường :attribute chứa ký tự điều khiển không hợp lệ.');
        }
    }
}
