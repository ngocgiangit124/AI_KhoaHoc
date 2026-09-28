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

    private static function mask(?string $value, bool $isEmail): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($isEmail) {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
            $visible = mb_substr($local, 0, 2);
            $hiddenLength = max(mb_strlen($local) - mb_strlen($visible), 1);

            return $visible.str_repeat('*', $hiddenLength).($domain !== '' ? '@'.$domain : '');
        }

        $length = strlen($value);

        if ($length <= 5) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2).str_repeat('*', $length - 5).substr($value, -3);
    }
}
