<?php

namespace App\Services\Content;

use Mews\Purifier\Facades\Purifier;

/**
 * Lọc HTML của người dùng bằng profile Purifier `course_description` (api-contract §4, S8). Gọi khi GHI và khi
 * TRẢ RA (CourseResource) — lớp thứ hai phòng dữ liệu cũ/ghi trực tiếp vào DB.
 */
class HtmlSanitizer
{
    public const PROFILE = 'course_description';

    public function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim((string) Purifier::clean($html, self::PROFILE));
    }

    /** Văn bản thuần (không thẻ) của đoạn HTML đã lọc — dùng để kiểm "mô tả không rỗng". */
    public function plainText(?string $html): string
    {
        $text = html_entity_decode(strip_tags($this->clean($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // &nbsp;/U+00A0/khoảng trắng Unicode và ký tự điều khiển/zero-width cũng coi là rỗng.
        return trim((string) preg_replace('/^[\p{Z}\p{Cc}\p{Cf}\s]+|[\p{Z}\p{Cc}\p{Cf}\s]+$/u', '', $text));
    }
}
