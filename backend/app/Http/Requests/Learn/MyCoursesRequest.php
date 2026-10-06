<?php

namespace App\Http\Requests\Learn;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phân trang "Khóa học của tôi". Tham số sai → 422 (không bỏ qua im lặng).
 */
class MyCoursesRequest extends FormRequest
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
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('learning.my_courses.max_per_page')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.*' => 'Số trang không hợp lệ.',
            'per_page.*' => 'Số khóa mỗi trang không hợp lệ (tối đa '.(int) config('learning.my_courses.max_per_page').').',
        ];
    }
}
