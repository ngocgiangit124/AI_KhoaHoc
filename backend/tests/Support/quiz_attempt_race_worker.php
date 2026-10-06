<?php

/**
 * Tiến trình con cho race test T22 (1 kết nối MySQL/tiến trình, autocommit). Chỉ chạy trên DB `*_testing`.
 * Dùng: php quiz_attempt_race_worker.php <mode> [args...] → in 1 dòng JSON.
 */

use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Quiz\QuizAttemptService;
use App\Services\Quiz\QuizContentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) DB::connection()->getDatabaseName();
if (! preg_match('/_testing(_[a-z])?$/', $db)) {
    fwrite(STDERR, "REFUSE: DB '$db' khong phai DB test\n");
    exit(9);
}

$mode = $argv[1];
$args = array_slice($argv, 2);
$wait = function (string $t): void {
    while (microtime(true) < (float) $t) {
        usleep(200);
    }
};
$run = function (callable $fn): array {
    try {
        $r = $fn();

        return ['result' => 'ok'] + (is_array($r) ? $r : []);
    } catch (DomainException $e) {
        return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)];
    }
};

$out = match ($mode) {
    // 1 học sinh có enrollment, quiz $args[0] câu (đáp án đúng = lựa chọn 1).
    'setup' => (function () use ($args) {
        $student = User::factory()->student()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
        $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
        $qs = [];
        for ($i = 1; $i <= (int) ($args[0] ?? 2); $i++) {
            $qs[] = QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $quiz->id, 'position' => $i])->id;
        }
        Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

        return ['student' => $student->id, 'course' => $course->id, 'quiz' => $quiz->id, 'questions' => $qs, 'creator' => $course->created_by];
    })(),
    // Không xoá audit_logs: trigger L2 chặn DELETE dòng mới và DB test riêng nên dòng audit không ảnh hưởng assert.
    'cleanup' => (function () use ($args) {
        [$student, $course, $creator] = $args;
        $quizIds = DB::table('quizzes')->where('course_id', $course)->pluck('id');
        $qids = DB::table('quiz_questions')->whereIn('quiz_id', $quizIds)->pluck('id');
        DB::table('quiz_attempts')->where('course_id', $course)->delete();
        DB::table('quiz_options')->whereIn('question_id', $qids)->delete();
        DB::table('quiz_questions')->whereIn('id', $qids)->update(['replaced_by_id' => null]);
        DB::table('quiz_questions')->whereIn('id', $qids)->delete();
        DB::table('quizzes')->where('course_id', $course)->delete();
        DB::table('enrollments')->where('course_id', $course)->delete();
        DB::table('chapters')->where('course_id', $course)->delete();
        DB::table('courses')->where('id', $course)->delete();
        DB::table('users')->whereIn('id', [$student, $creator])->delete();

        return ['ok' => true];
    })(),
    'state' => (function () use ($args) {
        $rows = DB::table('quiz_attempts')->where('quiz_id', $args[0])->get(['id', 'question_ids', 'answers', 'submitted_at', 'correct_count', 'score', 'auto_submitted']);

        return [
            'attempts' => $rows->count(),
            'open' => $rows->whereNull('submitted_at')->count(),
            'rows' => $rows->map(fn ($r) => (array) $r)->all(),
            'questions' => DB::table('quiz_questions')->where('quiz_id', $args[0])->get(['id', 'content', 'deleted_at', 'replaced_by_id'])->map(fn ($r) => (array) $r)->all(),
        ];
    })(),
    // args: student, quiz, startAt
    'start' => (function () use ($args, $wait, $run) {
        $user = User::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[1]);
        $wait($args[2]);

        return $run(function () use ($user, $quiz) {
            $r = app(QuizAttemptService::class)->start($user, $quiz);

            return ['attempt' => $r['attempt']->id, 'created' => $r['created']];
        });
    })(),
    // args: student, attemptId, startAt
    'submit' => (function () use ($args, $wait, $run) {
        $user = User::findOrFail($args[0]);
        $wait($args[2]);

        return $run(function () use ($user, $args) {
            $a = app(QuizAttemptService::class)->submit($user, (int) $args[1]);

            return ['correct' => $a->correct_count, 'submitted_at' => (string) $a->submitted_at];
        });
    })(),
    // args: student, attemptId, questionId, optionId, startAt
    'answer' => (function () use ($args, $wait, $run) {
        $user = User::findOrFail($args[0]);
        $wait($args[4]);

        return $run(fn () => app(QuizAttemptService::class)->saveAnswer($user, (int) $args[1], (int) $args[2], (int) $args[3]));
    })(),
    // args: course, quiz, questionId, startAt
    'put_q' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[1]);
        $q = QuizQuestion::findOrFail($args[2]);
        $wait($args[3]);
        $payload = ['content' => 'put', 'explanation' => null, 'options' => array_map(fn ($i) => ['content' => "o$i", 'is_correct' => $i === 1], [1, 2, 3, 4])];

        return $run(fn () => app(QuizContentService::class)->update($course, $quiz, $q, $payload));
    })(),
    'option' => (function () use ($args) {
        return ['id' => (int) DB::table('quiz_options')->where('question_id', $args[0])->where('position', $args[1])->value('id')];
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
