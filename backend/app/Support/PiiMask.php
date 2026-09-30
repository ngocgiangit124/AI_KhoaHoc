<?php

namespace App\Support;

/**
 * Che email/SĐT dùng chung — rút từ `App\Http\Resources\Auth\MeResource`
 * (T03, review L4) sang đây để T14 (`EnrollmentRequestResource`, "Tên HS
 * hiển thị, email/SĐT che" — api-contract §2.5) dùng lại đúng 1 thuật toán,
 * không viết lại lần 2 (T24 sau này có `PiiMasker` riêng cho đơn hàng, phạm
 * vi khác — không dùng chung file này để tránh 2 task khác nhau cùng sửa 1
 * chỗ).
 *
 * L4 (review docs/security/review-T03-FW1.md) — độ dài phần che CỐ ĐỊNH,
 * KHÔNG tỉ lệ với độ dài chuỗi gốc: chuỗi ngắn không bị lộ gần hết, và không
 * ai suy ngược được độ dài thật của giá trị gốc từ bản đã che.
 */
class PiiMask
{
    public static function email(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
        // Tối đa 1 ký tự đầu + che cố định 3 dấu `*` (không lộ độ dài phần
        // local thật, kể cả khi chỉ có 1 ký tự).
        $visible = mb_substr($local, 0, 1);

        return $visible.'***'.($domain !== '' ? '@'.$domain : '');
    }

    public static function phone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // 2 đầu + che cố định 5 dấu `*` + 3 cuối (SĐT đã chuẩn hoá 10 số) —
        // vẫn áp dụng nhất quán cho chuỗi ngắn hơn, chỉ hiện ít hơn khi không
        // đủ 2+3 ký tự.
        $length = strlen($value);

        if ($length <= 5) {
            return str_repeat('*', 5);
        }

        $prefixLength = min(2, $length - 3);
        $suffixLength = min(3, $length - $prefixLength);

        return substr($value, 0, $prefixLength).str_repeat('*', 5).substr($value, -$suffixLength);
    }
}
