<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/courses/{course}/teachers` (US-009 BR6, api-contract §2.5).
 * Phân quyền staff-only chạy TRƯỚC ở middleware `can:manageTeachers,course`.
 */
class SyncCourseTeachersRequest extends FormRequest
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
        return [
            'teacher_ids' => ['required', 'array', 'min:1'],
            'teacher_ids.*' => [
                'distinct',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Teacher->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'teacher_ids.required' => 'Khóa học cần có ít nhất 1 giáo viên phụ trách.',
            'teacher_ids.min' => 'Khóa học cần có ít nhất 1 giáo viên phụ trách.',
            'teacher_ids.*.exists' => 'Chỉ có thể chọn tài khoản có vai trò Giáo viên.',
        ];
    }
}
