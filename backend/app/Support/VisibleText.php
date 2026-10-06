<?php

namespace App\Support;

/**
 * "Có nội dung nhìn thấy được" của văn bản người dùng nhập (US-020 L2): bỏ khoảng trắng Unicode, ký tự định dạng/ẩn
 * (`\p{Cf}`, gồm ZWJ, LRM/RLM, tag...), và các ký tự "trắng có độ rộng" (Hangul filler, Braille blank, Khmer inherent vowel)
 * rồi xem còn gì không. Một nguồn duy nhất cho PHP (`isBlank`) và SQL (`SQL_NOT_BLANK_PATTERN`, dùng với `REGEXP`),
 * để `TeacherEligibility` và `HomepageTeacherQuery` luôn khớp nhau.
 */
final class VisibleText
{
    /** Phần thân của lớp ký tự (không có dấu `[`/`]`), dùng được cho cả PCRE (`/u`) lẫn ICU (MySQL REGEXP). */
    private const INVISIBLE = '\p{Z}\s\p{Cf}\x{3164}\x{115F}\x{1160}\x{FFA0}\x{2800}\x{17B4}\x{17B5}\x{034F}';

    /** Mẫu ICU: chuỗi khớp khi có ÍT NHẤT MỘT ký tự nhìn thấy được. */
    public const SQL_NOT_BLANK_PATTERN = '[^'.self::INVISIBLE.']';

    /**
     * Chuỗi tag của cờ vùng (U+1F3F4 + U+E0020–E007E + U+E007F) được `PlainText` chấp nhận; ở đây tag là `\p{Cf}` (ẩn) nhưng U+1F3F4 nhìn
     * thấy được nên văn bản chỉ có cờ vẫn KHÔNG bị coi là trống.
     */
    public static function isBlank(?string $value): bool
    {
        return $value === null || preg_match('/^['.self::INVISIBLE.']*$/u', $value) === 1;
    }

    /** Cắt khoảng trắng Unicode ở hai đầu (PHP `trim` chỉ cắt khoảng trắng ASCII: NBSP, U+3000... sẽ lọt). */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
    }
}
