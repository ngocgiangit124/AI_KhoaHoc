<?php

namespace App\Http\Resources\Auth;

use App\Services\Cart\CartService;
use App\Support\PiiMask;
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
 * - `cart_count`: số dòng trong giỏ (T16).
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
            // T16 — số dòng trong giỏ (1 truy vấn COUNT, không tạo giỏ).
            'cart_count' => app(CartService::class)->count($this->resource),
            // T14 — thuật toán che rút sang `App\Support\PiiMask` (dùng chung
            // với `EnrollmentRequestResource`), hành vi giữ NGUYÊN như trước.
            'parent_phone_masked' => PiiMask::phone($this->parent_phone),
            'parent_email_masked' => PiiMask::email($this->parent_email),
        ]);
    }
}
