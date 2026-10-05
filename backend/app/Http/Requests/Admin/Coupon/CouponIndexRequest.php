<?php

namespace App\Http\Requests\Admin\Coupon;

use App\Enums\CouponState;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CouponIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Coupon::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::enum(CouponState::class)],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'state.enum' => 'Trạng thái lọc không hợp lệ (active, inactive, expired, exhausted, upcoming).',
            'per_page.in' => 'Số dòng mỗi trang chỉ nhận 25 hoặc 50.',
            'q.max' => 'Từ khoá tối đa 100 ký tự.',
        ];
    }
}
