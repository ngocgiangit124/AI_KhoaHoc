<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * api-contract §2.5 — `PATCH /admin/subjects/{subject}/status`.
 */
class UpdateSubjectStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(SubjectStatus::class)],
        ];
    }
}
