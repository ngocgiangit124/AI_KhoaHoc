<?php

namespace App\Http\Requests\Admin;

use App\Enums\CourseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /admin/courses` (US-009, api-contract §2.5 "Scope `visibleTo`; q
 * escape LIKE"). Endpoint chỉ đọc, dùng để chuẩn hoá query string.
 */
class AdminCourseIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'integer', 'between:6,12'],
            'subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')],
            'status' => ['nullable', Rule::enum(CourseStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
