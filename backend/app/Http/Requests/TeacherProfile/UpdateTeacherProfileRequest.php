<?php

namespace App\Http\Requests\TeacherProfile;

use App\Rules\PlainText;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Validation\Validator;

/**
 * Sửa headline/bio (AC21: chỉ gửi trường đã đổi). Văn bản thuần: `headline` 1 dòng ≤ 120, `bio` nhiều dòng ≤ 600 ký tự
 * sau khi chuẩn hoá `\r\n` → `\n` và trim; chuỗi rỗng = xoá (lưu NULL). Phải có ít nhất 1 trong 2 trường.
 */
class UpdateTeacherProfileRequest extends TeacherProfileRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['headline', 'bio'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $normalized[$field] = TeacherProfileService::normalizeText($this->input($field));
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'headline' => ['sometimes', 'nullable', 'string', 'max:'.config('teacher_profile.headline_max'), new PlainText],
            'bio' => ['sometimes', 'nullable', 'string', 'max:'.config('teacher_profile.bio_max'), new PlainText(allowNewlines: true)],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('headline') && ! $this->has('bio')) {
                    $validator->errors()->add('headline', 'Cần gửi ít nhất một trong hai trường: dòng chuyên môn hoặc phần giới thiệu.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'headline.max' => 'Dòng chuyên môn tối đa '.config('teacher_profile.headline_max').' ký tự.',
            'bio.max' => 'Phần giới thiệu tối đa '.config('teacher_profile.bio_max').' ký tự.',
            'headline.string' => 'Dòng chuyên môn không hợp lệ.',
            'bio.string' => 'Phần giới thiệu không hợp lệ.',
        ];
    }
}
