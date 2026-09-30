<?php

namespace App\Http\Requests\Cart;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /cart/coupon (api-contract §2.3): `code` ≤ 50. Chỉ kiểm hình dạng chung
 * — mã có tồn tại/dùng được hay không do `CouponEvaluator` quyết định (cùng
 * mã lỗi `COUPON_INVALID`, S18); định dạng lạ KHÔNG trả 422 riêng ở đây để
 * không thành kênh phân biệt.
 */
class ApplyCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
        ];
    }
}
