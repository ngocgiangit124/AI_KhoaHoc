<?php

namespace App\Http\Requests\TeacherProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Bật/tắt hiển thị trang chủ và đặt thứ tự (chỉ Admin/QLT). Có ít nhất 1 trường; `homepage_order` null = bỏ thứ tự.
 */
class UpdateTeacherHomepageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-teacher-profiles');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'show_on_homepage' => ['sometimes', 'boolean'],
            'homepage_order' => ['sometimes', 'nullable', 'integer', 'between:1,'.config('teacher_profile.order_max')],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('show_on_homepage') && ! $this->has('homepage_order')) {
                    $validator->errors()->add('show_on_homepage', 'Cần gửi ít nhất một trong hai trường: hiển thị trang chủ hoặc thứ tự.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'show_on_homepage.boolean' => 'Giá trị hiển thị trang chủ phải là true hoặc false.',
            'homepage_order.integer' => 'Thứ tự trang chủ phải là số nguyên.',
            'homepage_order.between' => 'Thứ tự trang chủ phải từ 1 đến '.config('teacher_profile.order_max').'.',
        ];
    }
}
