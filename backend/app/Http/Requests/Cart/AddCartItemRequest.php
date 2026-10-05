<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Chỉ kiểm dạng dữ liệu; tồn tại/đã xuất bản/price > 0 kiểm trong CartService (trên dòng đã khoá) → 422 field `course_id`.
 */
class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['course_id' => ['required', 'integer', 'min:1', 'max:4294967295']];
    }
}
