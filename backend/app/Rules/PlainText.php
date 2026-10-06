<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Văn bản thuần (S8): không thẻ HTML, `<`/`>`, ký tự điều khiển, ký tự định dạng/ẩn hay dấu kết hợp chồng quá nhiều. Dùng cho
 * tên/mô tả ngắn render bằng text.
 *
 * Chặn theo LỚP ký tự thay vì liệt kê (US-020 L2):
 * - `\p{Cc}` (điều khiển), `\p{Cf}` (định dạng: bidi, LRM/RLM/ALM, zero-width, U+2060–2064, soft hyphen, tag U+E0000–E007F,
 *   BOM...) trừ ZWJ U+200D (kiểm riêng, chỉ để ghép emoji), U+2028/U+2029;
 * - ký tự "trắng có độ rộng": U+3164, U+115F/U+1160, U+FFA0, U+2800, U+17B4/U+17B5, U+034F;
 * - ngoại lệ: chuỗi tag của cờ vùng (U+1F3F4 + tag U+E0020–E007E + U+E007F, ví dụ cờ England) được chấp nhận; tag đứng lẻ thì chặn;
 * - bộ chọn biến thể (U+FE00–FE0F, U+E0100–E01EF) chỉ hợp lệ ngay sau emoji (hoặc trong chuỗi keycap `1️⃣`);
 * - quá 3 dấu kết hợp (`\p{M}`) liên tiếp (Zalgo). Tiếng Việt NFC hoặc NFD chỉ cần tối đa 2 dấu trên một chữ.
 *
 * `allowNewlines` (US-020, bio giáo viên): cho phép đúng ký tự `\n` (người gọi phải chuẩn hoá `\r\n` → `\n` trước); vẫn chặn
 * mọi ký tự điều khiển khác (kể cả `\r`, tab).
 */
class PlainText implements ValidationRule
{
    /** Số dấu kết hợp liên tiếp tối đa. */
    public const MAX_COMBINING_MARKS = 3;

    public function __construct(private readonly bool $allowNewlines = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // UTF-8 hỏng (preg_match === false) cũng bị từ chối. Cf trừ ZWJ; U+2028/2029 là Zl/Zp (không phải Cc) nhưng trình duyệt vẽ
        // thành xuống dòng nên cũng chặn.
        $invisible = '/[<>\p{Cc}\x{2028}\x{2029}\x{3164}\x{115F}\x{1160}\x{FFA0}\x{2800}\x{17B4}\x{17B5}\x{034F}]|(?!\x{200D})\p{Cf}/u';

        // R7: ZWJ (U+200D) chỉ được dùng để ghép emoji (👨‍👩‍👧): hợp lệ khi đứng giữa hai ký tự Extended_Pictographic
        // (cho phép FE0F/tông da ngay trước). ZWJ đứng lẻ hoặc giữa chữ thì bị chặn.
        $strayJoiner = '/(?<![\p{Extended_Pictographic}\x{FE0F}\x{1F3FB}-\x{1F3FF}])\x{200D}|\x{200D}(?!\p{Extended_Pictographic})/u';

        // Bộ chọn biến thể lạc: chỉ hợp lệ sau emoji (hoặc tông da) — chuỗi keycap (`1️⃣`) được gỡ trước khi kiểm.
        $straySelector = '/(?<![\p{Extended_Pictographic}\x{1F3FB}-\x{1F3FF}])[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u';

        $zalgo = '/\p{M}{'.(self::MAX_COMBINING_MARKS + 1).',}/u';

        $checked = $this->allowNewlines ? str_replace("\n", ' ', $value) : $value;

        // Cờ có tag sequence (🏴 England/Scotland/Wales): CHỈ chấp nhận chuỗi tag U+E0020–E007E đứng ngay sau U+1F3F4 và kết thúc
        // bằng U+E007F. Gỡ chuỗi hợp lệ trước khi kiểm; tag đứng lẻ hoặc chuỗi thiếu ký tự kết thúc vẫn còn lại và bị chặn.
        $checked = preg_replace('/(\x{1F3F4})[\x{E0020}-\x{E007E}]+\x{E007F}/u', '$1', $checked);

        if ($checked === null) {
            $fail('Trường :attribute chỉ được chứa văn bản thuần, không có thẻ HTML, ký tự điều khiển, ký tự ẩn hay dấu kết hợp quá nhiều.');

            return;
        }
        $withoutKeycaps = preg_replace('/[0-9#*]\x{FE0F}\x{20E3}/u', '', $checked);

        if (
            strip_tags($value) !== $value
            || preg_match($invisible, $checked) !== 0
            || preg_match($strayJoiner, $checked) !== 0
            || $withoutKeycaps === null
            || preg_match($straySelector, $withoutKeycaps) !== 0
            || preg_match($zalgo, $withoutKeycaps) !== 0
        ) {
            $fail('Trường :attribute chỉ được chứa văn bản thuần, không có thẻ HTML, ký tự điều khiển, ký tự ẩn hay dấu kết hợp quá nhiều.');
        }
    }
}
