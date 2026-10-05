<?php

namespace App\Http\Requests\Admin\Quiz;

use App\Models\Course;
use App\Rules\QuizText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Tạo/sửa câu hỏi: nội dung/giải thích văn bản thuần (≤ 5.000), đúng 4 lựa chọn (≤ 1.000) và đúng 1 đáp án đúng.
 * Thứ tự lựa chọn = thứ tự mảng (A–D). `id` của lựa chọn gửi lên bị bỏ qua.
 */
class QuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return Gate::allows('manageContent', $course);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:'.(int) config('quiz.max_question_chars'), new QuizText],
            'explanation' => ['nullable', 'string', 'max:'.(int) config('quiz.max_question_chars'), new QuizText],
            'options' => ['required', 'array', 'size:'.(int) config('quiz.options_per_question'), 'list'],
            'options.*' => ['required', 'array'],
            'options.*.content' => ['required', 'string', 'max:'.(int) config('quiz.max_option_chars'), new QuizText],
            'options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $options = $this->input('options');

                if (! is_array($options) || $validator->errors()->has('options') || $validator->errors()->has('options.*')) {
                    return;
                }

                $correct = count(array_filter($options, fn ($o) => is_array($o) && filter_var($o['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)));

                if ($correct !== 1) {
                    $validator->errors()->add('options', 'Phải có đúng 1 đáp án đúng.');
                }
            },
        ];
    }

    /**
     * Dữ liệu đã chuẩn hoá cho service (is_correct ép bool thật, bỏ field lạ như id).
     *
     * @return array{content: string, explanation: string|null, options: list<array{content: string, is_correct: bool}>}
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        return [
            'content' => (string) $v['content'],
            'explanation' => $v['explanation'] ?? null,
            'options' => array_map(fn (array $o): array => [
                'content' => (string) $o['content'],
                'is_correct' => filter_var($o['is_correct'], FILTER_VALIDATE_BOOLEAN),
            ], array_values($v['options'])),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Vui lòng nhập nội dung câu hỏi.',
            'content.max' => 'Nội dung câu hỏi tối đa 5.000 ký tự.',
            'explanation.max' => 'Lời giải tối đa 5.000 ký tự.',
            'options.required' => 'Vui lòng nhập 4 lựa chọn.',
            'options.size' => 'Mỗi câu hỏi phải có đúng 4 lựa chọn.',
            'options.*.content.required' => 'Vui lòng nhập nội dung cho cả 4 lựa chọn.',
            'options.*.content.max' => 'Mỗi lựa chọn tối đa 1.000 ký tự.',
            'options.*.is_correct.required' => 'Thiếu thông tin đáp án đúng.',
        ];
    }
}
