<?php

namespace App\Http\Requests\Admin\Quiz;

use App\Models\Course;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tạo/sửa quiz. `course_id` suy ra từ route (không nhận từ request); chương/bài phải thuộc đúng khóa này, chỉ một
 * trong hai. `time_limit_minutes` bị bỏ qua khi cờ FEATURE_QUIZ_TIME_LIMIT tắt (xử lý ở service).
 */
class QuizRequest extends FormRequest
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
        /** @var Course $course */
        $course = $this->route('course');

        return [
            'title' => ['required', 'string', 'max:255', new PlainText],
            'chapter_id' => [
                'nullable', 'integer', 'required_without:lesson_id', 'prohibits:lesson_id',
                Rule::exists('chapters', 'id')->where('course_id', $course->getKey())->whereNull('deleted_at'),
            ],
            'lesson_id' => [
                'nullable', 'integer', 'required_without:chapter_id', 'prohibits:chapter_id',
                Rule::exists('lessons', 'id')->where('course_id', $course->getKey())->whereNull('deleted_at'),
            ],
            // Cờ tắt: bỏ qua hẳn trường này (không validate, không vào validated()).
            'time_limit_minutes' => config('features.quiz_time_limit')
                ? ['nullable', 'integer', 'min:1', 'max:'.(int) config('quiz.max_time_limit_minutes')]
                : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên quiz.',
            'title.max' => 'Tên quiz tối đa 255 ký tự.',
            'chapter_id.required_without' => 'Quiz phải gắn với một chương hoặc một bài học.',
            'lesson_id.required_without' => 'Quiz phải gắn với một chương hoặc một bài học.',
            'chapter_id.prohibits' => 'Chỉ chọn một nơi gắn: chương hoặc bài học.',
            'lesson_id.prohibits' => 'Chỉ chọn một nơi gắn: chương hoặc bài học.',
            'chapter_id.exists' => 'Chương không tồn tại trong khóa học này.',
            'lesson_id.exists' => 'Bài học không tồn tại trong khóa học này.',
            'time_limit_minutes.min' => 'Thời gian làm bài từ 1 đến 300 phút.',
            'time_limit_minutes.max' => 'Thời gian làm bài từ 1 đến 300 phút.',
        ];
    }
}
