<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;

/**
 * GET /auth/me (host api, nhóm `student` — api-contract §2.2): "user +
 * `is_verified` + `parent_consent_status` + `cart_count` + thông tin phụ
 * huynh đã che".
 *
 * Trường chưa có dữ liệu thật ở T03 (chưa có OTP — T04, chưa có giỏ hàng —
 * T16) dùng giá trị mặc định an toàn, KHÔNG bịa field ngoài api-contract:
 * - `is_verified`: suy từ `email_verified_at`/`phone_verified_at` sẵn có ở
 *   data-model — luôn `false` cho tới khi T04 gửi/xác thực OTP.
 * - `cart_count`: `0` cho tới khi bảng `carts` tồn tại (T16).
 */
class MeResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'is_verified' => $this->email_verified_at !== null || $this->phone_verified_at !== null,
            'parent_consent_status' => $this->parent_consent_status->value,
            // TODO(T16): thay bằng số thật khi bảng `carts` tồn tại.
            'cart_count' => 0,
            'parent_phone_masked' => self::mask($this->parent_phone, isEmail: false),
            'parent_email_masked' => self::mask($this->parent_email, isEmail: true),
        ]);
    }

    /**
     * L4 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY độ dài phần che
     * tỉ lệ với độ dài chuỗi gốc, nên chuỗi NGẮN lộ gần hết ("ab@x.com" →
     *
     * "ab*@x.com" — chỉ che 1 ký tự; SĐT 8 số lộ 5/8 chữ số). Giờ dùng độ dài
     * CỐ ĐỊNH cho phần che — không còn cách suy ra độ dài thật của chuỗi gốc
     * từ bản đã che.
     */
    private static function mask(?string $value, bool $isEmail): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($isEmail) {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
            // Tối đa 1 ký tự đầu + che cố định 3 dấu `*` (không lộ độ dài
            // phần local thật, kể cả khi chỉ có 1 ký tự).
            $visible = mb_substr($local, 0, 1);

            return $visible.'***'.($domain !== '' ? '@'.$domain : '');
        }

        // 2 đầu + che cố định 5 dấu `*` + 3 cuối (SĐT đã chuẩn hoá 10 số —
        // `PhoneNumber`/`normalizeParentPhoneOrNull` — vẫn áp dụng nhất quán
        // cho chuỗi ngắn hơn, chỉ hiện ít hơn khi không đủ 2+3 ký tự).
        $length = strlen($value);

        if ($length <= 5) {
            return str_repeat('*', 5);
        }

        $prefixLength = min(2, $length - 3);
        $suffixLength = min(3, $length - $prefixLength);

        return substr($value, 0, $prefixLength).str_repeat('*', 5).substr($value, -$suffixLength);
    }
}
