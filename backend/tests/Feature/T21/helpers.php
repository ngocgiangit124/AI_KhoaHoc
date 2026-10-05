<?php

use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;

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

/** Tạo bảng tạm quiz_attempts (tối thiểu) để mô phỏng T22; bảng tạm không gây commit ngầm nên RefreshDatabase vẫn dọn được. */
function vvFakeAttemptTable(): void
{
    DB::statement('CREATE TEMPORARY TABLE quiz_attempts (id BIGINT AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT NOT NULL, question_ids JSON NOT NULL)');
}

function vvFakeAttempt(Quiz $quiz, array $questionIds): void
{
    DB::table('quiz_attempts')->insert(['quiz_id' => $quiz->id, 'question_ids' => json_encode($questionIds)]);
}
