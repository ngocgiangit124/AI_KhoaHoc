<?php

namespace App\Http\Requests\Order;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Phân trang "Đơn của tôi". Tham số sai → 422 (không bỏ qua im lặng). */
class MyOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return ['page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['page.*' => 'Số trang không hợp lệ.'];
    }
}
