<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubjectStatus;
use App\Models\Subject;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * api-contract §2.5 — `SubjectRequest`: name (trim, 1–100, văn bản thuần).
 * Dùng chung tạo (POST) và sửa (PUT /admin/subjects/{subject}); phân quyền
 * đã được `$this->authorize()` gọi trong controller (SubjectPolicy).
 */
class SubjectRequest extends FormRequest
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
        /** @var Subject|null $subject */
        $subject = $this->route('subject');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                new PlainText,
                Rule::unique('subjects', 'name')->ignore($subject?->getKey()),
            ],
            // Cho phép đặt trạng thái khi tạo mới (mặc định `active` — US-011
            // AC1); đổi trạng thái sau đó dùng PATCH .../status.
            'status' => ['sometimes', Rule::enum(SubjectStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên chuyên đề.',
            'name.unique' => 'Chuyên đề đã tồn tại.',
        ];
    }
}
