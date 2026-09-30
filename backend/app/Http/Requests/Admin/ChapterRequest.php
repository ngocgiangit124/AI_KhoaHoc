<?php

namespace App\Http\Requests\Admin;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST/PUT /admin/courses/{course}/chapters[/{chapter}]` (US-009, api-contract
 * §2.5). Chỉ nhận `title` — `course_id` lấy từ route (đã `scopeBindings`),
 * `position` do server gán (thêm cuối) hoặc đổi qua `curriculum/order` (S5).
 * Phân quyền chạy TRƯỚC ở middleware `can:manageContent,course` của route.
 */
class ChapterRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255', new PlainText],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên chương.',
        ];
    }
}
