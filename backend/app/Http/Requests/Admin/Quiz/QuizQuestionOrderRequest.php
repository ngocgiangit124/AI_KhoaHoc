<?php

namespace App\Http\Requests\Admin\Quiz;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** PUT questions/order: chỉ kiểm hình dạng; "đúng toàn bộ câu của quiz" kiểm trong service dưới khoá quiz. */
class QuizQuestionOrderRequest extends FormRequest
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
            'question_ids' => ['present', 'array', 'max:'.(int) config('quiz.max_questions')],
            'question_ids.*' => ['integer', 'min:1', 'distinct:strict'],
        ];
    }

    /**
     * @return list<int>
     */
    public function questionIds(): array
    {
        return array_map('intval', array_values((array) $this->validated('question_ids')));
    }

    public function messages(): array
    {
        return ['question_ids.*.distinct' => 'Mỗi câu hỏi chỉ xuất hiện một lần.'];
    }
}
