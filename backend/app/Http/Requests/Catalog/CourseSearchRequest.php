<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bộ lọc danh mục công khai (US-002). Tham số sai → 422 (không bỏ qua im lặng, để response cache được
 * luôn khớp đúng với query string).
 */
class CourseSearchRequest extends FormRequest
{
    public const SORTS = ['newest', 'popular', 'featured'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'grade' => ['bail', 'nullable', 'integer', 'between:6,12'],
            'subject_ids' => ['nullable', 'array', 'max:20'],
            'subject_ids.*' => ['integer', 'min:1', 'max:4294967295'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grade.integer' => 'Lớp học không hợp lệ.',
            'grade.between' => 'Lớp học phải từ 6 đến 12.',
            'subject_ids.array' => 'Chuyên đề không hợp lệ.',
            'subject_ids.max' => 'Chỉ được lọc tối đa 20 chuyên đề.',
            'subject_ids.*.integer' => 'Chuyên đề không hợp lệ.',
            'subject_ids.*.min' => 'Chuyên đề không hợp lệ.',
            'subject_ids.*.max' => 'Chuyên đề không hợp lệ.',
            'q.max' => 'Từ khóa tối đa 100 ký tự.',
            'q.string' => 'Từ khóa không hợp lệ.',
            'sort.in' => 'Kiểu sắp xếp không hợp lệ.',
            'page.integer' => 'Số trang không hợp lệ.',
            'page.min' => 'Số trang không hợp lệ.',
            'page.max' => 'Số trang không hợp lệ.',
        ];
    }
}
