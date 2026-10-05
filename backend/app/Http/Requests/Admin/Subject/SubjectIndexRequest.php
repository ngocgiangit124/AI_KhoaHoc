<?php

namespace App\Http\Requests\Admin\Subject;

use App\Enums\SubjectStatus;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Subject::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::enum(SubjectStatus::class)],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50])],
            // all=1: danh sách đầy đủ không phân trang (cho ô chọn chuyên đề trong form khóa học).
            'all' => ['nullable', 'boolean'],
        ];
    }
}
