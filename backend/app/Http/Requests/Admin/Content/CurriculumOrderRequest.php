<?php

namespace App\Http\Requests\Admin\Content;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * PUT curriculum/order: mảng gốc `[{chapter_id, lesson_ids[]}]`. Chỉ kiểm hình dạng; việc "tập ID phải bằng đúng tập
 * hiện có của khóa" kiểm trong CurriculumService dưới khoá dòng khóa (S5).
 */
class CurriculumOrderRequest extends FormRequest
{
    private const MAX_CHAPTERS = 500;

    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageContent', $course);
    }

    /**
     * Thân JSON là mảng gốc: bỏ qua query string để không lẫn vào dữ liệu kiểm.
     *
     * @return array<array-key, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    protected function prepareForValidation(): void
    {
        // Body không phải JSON (form, JSON hỏng) không được coi là mảng rỗng.
        if (! $this->isJson() || ! is_array(json_decode($this->getContent(), true))) {
            throw ValidationException::withMessages(['body' => 'Dữ liệu gửi lên phải là mảng JSON [{chapter_id, lesson_ids}].']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            '*' => ['array:chapter_id,lesson_ids'],
            '*.chapter_id' => ['required', 'integer', 'min:1', 'distinct'],
            '*.lesson_ids' => ['present', 'array', 'max:1000'],
            '*.lesson_ids.*' => ['integer', 'min:1', 'distinct:strict'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (count($this->json()->all()) > self::MAX_CHAPTERS) {
                $validator->errors()->add('body', 'Khóa học tối đa '.self::MAX_CHAPTERS.' chương.');
            }
        }];
    }

    /**
     * @return list<array{chapter_id: int, lesson_ids: list<int>}>
     */
    public function items(): array
    {
        return array_map(static fn (array $row): array => [
            'chapter_id' => (int) $row['chapter_id'],
            'lesson_ids' => array_map('intval', $row['lesson_ids']),
        ], array_values($this->json()->all()));
    }

    public function messages(): array
    {
        return [
            '*.chapter_id.distinct' => 'Mỗi chương chỉ xuất hiện một lần.',
            '*.lesson_ids.*.distinct' => 'Mỗi bài học chỉ xuất hiện một lần.',
        ];
    }
}
