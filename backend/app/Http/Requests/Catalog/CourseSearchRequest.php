<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /courses (US-002, api-contract §2.1). Endpoint công khai — request
 * dùng để chuẩn hoá/validate query string, không phải để chặn quyền.
 */
class CourseSearchRequest extends FormRequest
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
            'grade' => ['nullable', 'integer', 'between:6,12'],
            'subject_ids' => ['nullable', 'array', 'max:20'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')],
            // S24 — escape LIKE ở tầng Query (App\Support\Like), không ở đây.
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'popular', 'featured'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
