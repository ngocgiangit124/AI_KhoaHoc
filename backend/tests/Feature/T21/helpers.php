<?php

use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;

require_once __DIR__.'/../T09/helpers.php';

function vvQuizPath(Course $course, ?Quiz $quiz = null, ?QuizQuestion $question = null): string
{
    $p = "/admin/courses/{$course->id}/quizzes";

    if ($quiz) {
        $p .= "/{$quiz->id}";
    }

    if ($quiz && $question) {
        $p .= "/questions/{$question->id}";
    }

    return $p;
}

function vvQuestionsPath(Course $course, Quiz $quiz): string
{
    return vvQuizPath($course, $quiz).'/questions';
}

/** @return array<string, mixed> */
function vvQuestionPayload(array $over = [], int $correct = 2): array
{
    return array_merge([
        'content' => 'Nghiệm của phương trình $x^2 - 4 = 0$ là gì?',
        'explanation' => 'Phân tích $(x-2)(x+2)=0$.',
        'options' => array_map(fn ($i) => ['content' => '$x = '.$i.'$', 'is_correct' => $i === $correct], [1, 2, 3, 4]),
    ], $over);
}

/** Bảng `quiz_attempts` thật đã có từ T22 (migration); giữ hàm để các test T21 cũ không phải sửa. */
function vvFakeAttemptTable(): void
{
    // Không làm gì.
}

/** Tạo lượt làm thật cho quiz (mỗi lần 1 học sinh mới nên không đụng unique "1 lượt đang làm"). */
function vvFakeAttempt(Quiz $quiz, array $questionIds): void
{
    QuizAttempt::factory()->create([
        'quiz_id' => $quiz->id,
        'question_ids' => array_map('intval', $questionIds),
    ]);
}
