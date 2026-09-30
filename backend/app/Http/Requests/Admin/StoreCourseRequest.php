<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubjectStatus;
use App\Enums\UserRole;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/courses` (US-009 AC1/AC9, api-contract §2.5). Phân quyền chạy
 * TRƯỚC validate qua middleware `can:create,...` của route (mẫu R2
 * review-T06) — `authorize()` giữ `true` theo quy ước dự án.
 *
 * `teacher_ids` CÓ mặt trong schema cho staff (bắt buộc, ≥1, role
 * `giao_vien`) nhưng KHÔNG bắt buộc cho Giáo Viên — dù GV có gửi gì đi nữa,
 * `CourseService::create()` LUÔN bỏ qua và tự thêm chính GV đó (BR8,
 * api-contract §2.5 "GV gửi thì bị bỏ qua, tự thêm chính mình").
 */
class StoreCourseRequest extends FormRequest
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
        $isStaff = (bool) $this->user()?->isStaff();

        return [
            'title' => ['required', 'string', 'max:255', new PlainText],
            'grade_level' => ['required', 'integer', 'between:6,12'],
            'subject_ids' => ['required', 'array', 'min:1', 'max:20'],
            'subject_ids.*' => [
                'integer',
                Rule::exists('subjects', 'id')->where('status', SubjectStatus::Active->value),
            ],
            'short_description' => ['required', 'string', 'max:500', new PlainText],
            // HTML thô — sanitize (`HtmlSanitizer`, profile `course_description`,
            // api-contract §4) diễn ra ở `CourseService`, KHÔNG ở đây.
            'description' => ['nullable', 'string', 'max:20000'],
            'price' => ['required', 'integer', 'between:0,50000000'],
            'thumbnail' => [
                'required', 'file', 'max:2048',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:max_width=4000,max_height=4000',
            ],
            'teacher_ids' => $isStaff ? ['required', 'array', 'min:1'] : ['sometimes', 'array'],
            'teacher_ids.*' => [
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
            'thumbnail.required' => 'Vui lòng tải lên ảnh đại diện khóa học.',
            'thumbnail.mimes' => 'Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.',
            'thumbnail.mimetypes' => 'Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.',
            'teacher_ids.required' => 'Vui lòng chọn ít nhất 1 giáo viên phụ trách.',
            'teacher_ids.*.exists' => 'Chỉ có thể chọn tài khoản có vai trò Giáo viên.',
            'subject_ids.required' => 'Vui lòng chọn ít nhất 1 chuyên đề.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'short_description'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
