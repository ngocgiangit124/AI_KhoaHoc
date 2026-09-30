<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubjectStatus;
use App\Models\Course;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/courses/{course}` (US-009, api-contract §2.5) — 2 bộ rule
 * staff / Giáo Viên:
 * - `price`/`grade_level` chỉ nằm trong schema khi actor là staff, HOẶC khóa
 *   CHƯA TỪNG publish (`published_at === null` — GV vẫn tự do chỉnh khóa
 *   nháp của chính mình, BR8). Sau khi đã publish lần đầu, chỉ staff được
 *   đổi giá/lớp (bảo vệ học sinh đã biết giá/lớp khi mua).
 * - `status`, `manual_order`, `teacher_ids` KHÔNG BAO GIỜ được khai ở đây (dù
 *   role nào) — cố tình để `$request->validated()` tự loại các key này nếu
 *   client gửi lên (Laravel `validated()` chỉ trả field có rule khai báo),
 *   nên "GV gửi teacher_ids/status/manual_order bị bỏ qua" (S5/S17,
 *   tasks.md T08) đúng cho MỌI role — 3 field này có route/Service RIÊNG
 *   là nơi DUY NHẤT được đổi (`CoursePublicationController`,
 *   `CourseController::updateManualOrder`, `CourseTeacherController`).
 */
class UpdateCourseRequest extends FormRequest
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
        /** @var Course $course */
        $course = $this->route('course');
        $user = $this->user();

        $rules = [
            'title' => ['sometimes', 'required', 'string', 'max:255', new PlainText],
            'subject_ids' => ['sometimes', 'required', 'array', 'min:1', 'max:20'],
            'subject_ids.*' => [
                'integer',
                Rule::exists('subjects', 'id')->where('status', SubjectStatus::Active->value),
            ],
            'short_description' => ['sometimes', 'required', 'string', 'max:500', new PlainText],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'thumbnail' => [
                'sometimes', 'file', 'max:2048',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:max_width=4000,max_height=4000',
            ],
        ];

        $everPublished = $course->published_at !== null;

        if (($user?->isStaff() ?? false) || ! $everPublished) {
            $rules['price'] = ['sometimes', 'required', 'integer', 'between:0,50000000'];
            $rules['grade_level'] = ['sometimes', 'required', 'integer', 'between:6,12'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'thumbnail.mimes' => 'Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.',
            'thumbnail.mimetypes' => 'Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.',
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
