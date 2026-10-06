<?php

namespace App\Http\Requests\TeacherProfile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Danh sách hồ sơ giáo viên cho Admin/QLT. Tham số sai → 422. */
class TeacherProfileIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'homepage' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max' => 'Từ khoá tối đa 100 ký tự.',
            'homepage.in' => 'Bộ lọc trang chủ chỉ nhận 1 (đang bật) hoặc 0.',
            'per_page.in' => 'Số dòng mỗi trang chỉ nhận 25 hoặc 50.',
            'page.integer' => 'Số trang không hợp lệ.',
            'page.min' => 'Số trang không hợp lệ.',
            'page.max' => 'Số trang không hợp lệ.',
        ];
    }
}
