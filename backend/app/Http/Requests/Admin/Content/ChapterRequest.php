<?php

namespace App\Http\Requests\Admin\Content;

use App\Models\Course;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Tạo/sửa chương. `position`, `course_id` không nhận từ request (thứ tự đổi qua curriculum/order). */
class ChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageContent', $course);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim((string) preg_replace('/\s+/u', ' ', $this->input('title')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255', new PlainText]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên chương.',
            'title.max' => 'Tên chương tối đa 255 ký tự.',
        ];
    }
}
