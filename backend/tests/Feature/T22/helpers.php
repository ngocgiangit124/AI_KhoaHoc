<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T04/helpers.php';
require_once __DIR__.'/../T03/helpers.php';

/**
 * Khóa published + quiz gắn chương với $questions câu (đáp án đúng luôn là lựa chọn vị trí 1), học sinh đã đăng nhập,
 * có enrollment active nếu $owned.
 *
 * @return array{student: User, course: Course, quiz: Quiz, questions: list<QuizQuestion>}
 */
function vvAtSet(bool $owned = true, int $questions = 3, ?int $timeLimit = null): array
{
    $student = vvActAsStudent(User::factory()->student()->create());
    $course = Course::factory()->published()->create();
    $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1, 'time_limit_minutes' => $timeLimit]);
    $qs = [];

    for ($i = 1; $i <= $questions; $i++) {
        $qs[] = QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $quiz->id, 'position' => $i]);
    }

    if ($owned) {
        Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    }

    return ['student' => $student, 'course' => $course, 'quiz' => $quiz, 'questions' => $qs];
}

function vvAtStart(Quiz|int $quiz): TestResponse
{
    $id = $quiz instanceof Quiz ? $quiz->id : $quiz;

    return test()->postJson(vvApiUrl("/learn/quizzes/{$id}/attempts"), [], vvWebHeaders());
}

function vvAtAnswer(int $attempt, QuizQuestion|int $question, int $option): TestResponse
{
    $qid = $question instanceof QuizQuestion ? $question->id : $question;

    return test()->putJson(vvApiUrl("/learn/quiz-attempts/{$attempt}/answers/{$qid}"), ['option_id' => $option], vvWebHeaders());
}

function vvAtSubmit(int $attempt): TestResponse
{
    return test()->postJson(vvApiUrl("/learn/quiz-attempts/{$attempt}/submit"), [], vvWebHeaders());
}

function vvAtShow(int $attempt): TestResponse
{
    return test()->getJson(vvApiUrl("/learn/quiz-attempts/{$attempt}"), vvWebHeaders());
}

/** Id lựa chọn của câu theo vị trí 1–4 (1 = đúng). */
function vvAtOpt(QuizQuestion $question, int $position): int
{
    return (int) $question->options()->where('position', $position)->value('id');
}
