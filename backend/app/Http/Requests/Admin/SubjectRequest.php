<?php

namespace App\Http\Requests\Admin;

use App\Models\Subject;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * api-contract §2.5 — `SubjectRequest`: name (trim, 1–100, văn bản thuần).
 * KHÔNG có `status` (ngoài phạm vi hợp đồng) — tạo mới luôn `active` (US-011
 * AC1), đổi trạng thái đi qua `PATCH .../status` riêng
 * (`UpdateSubjectStatusRequest`). Dùng chung tạo (POST) và sửa (PUT
 * /admin/subjects/{subject}); phân quyền chạy TRƯỚC validate qua middleware
 * `can:...` trên route (routes/admin.php — R2 review-T06), không phải
 * `authorize()` ở đây.
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
