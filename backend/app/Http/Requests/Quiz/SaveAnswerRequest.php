<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quyền + việc option thuộc câu hỏi / câu thuộc lượt do QuizAttemptService kiểm (đúng mã lỗi, ép int).
 */
class SaveAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['option_id' => ['required', 'integer', 'min:1']];
    }
}
