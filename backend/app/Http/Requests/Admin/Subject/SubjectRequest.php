<?php

namespace App\Http\Requests\Admin\Subject;

use App\Models\Subject;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tạo/sửa chuyên đề (US-011). Tên là văn bản thuần: không nhận thẻ HTML/ký tự điều khiển (S8); khoảng trắng
 * đầu/cuối bị cắt, khoảng trắng liên tiếp gộp thành 1. Quyền kiểm ở controller (`authorize` qua Policy).
 */
class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kiểm quyền TRƯỚC validate: giáo viên không dò được tên trùng qua lỗi 422.
        $subject = $this->route('subject');

        return $subject instanceof Subject
            ? Gate::allows('update', $subject)
            : Gate::allows('create', Subject::class);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', $name))]);
        }
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
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[<>\p{Cc}]/u', $value) === 1)) {
                        $fail('Tên chuyên đề chỉ được chứa văn bản thuần, không có thẻ HTML hay ký tự điều khiển.');
                    }
                },
                // Collation utf8mb4_0900_ai_ci: không phân biệt hoa/thường và dấu (US-011 BR1).
                Rule::unique('subjects', 'name')->ignore($subject?->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên chuyên đề.',
            'name.max' => 'Tên chuyên đề tối đa 100 ký tự.',
            'name.unique' => 'Chuyên đề đã tồn tại.',
        ];
    }
}
