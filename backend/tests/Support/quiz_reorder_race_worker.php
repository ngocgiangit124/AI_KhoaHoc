<?php

/** Tiến trình con cho race test SLN7 (QA). Chỉ chạy trên DB `*_testing`. In 1 dòng JSON. */

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

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
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
        $fn();

        return ['result' => 'ok'];
    } catch (DomainException $e) {
        return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)];
    }
};
$payload = fn (string $c) => ['content' => $c, 'explanation' => null, 'options' => array_map(fn ($i) => ['content' => "o$i", 'is_correct' => $i === 1], [1, 2, 3, 4])];

$out = match ($mode) {
    // args: nQuestions, nStudents
    'setup' => (function () use ($args) {
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
        $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
        $qs = [];
        for ($i = 1; $i <= (int) $args[0]; $i++) {
            $qs[] = QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $quiz->id, 'position' => $i])->id;
        }
        $students = [];
        for ($i = 0; $i < (int) $args[1]; $i++) {
            $u = User::factory()->student()->create();
            Enrollment::factory()->create(['user_id' => $u->id, 'course_id' => $course->id]);
            $students[] = $u->id;
        }

        return ['course' => $course->id, 'quiz' => $quiz->id, 'questions' => $qs, 'students' => $students, 'creator' => $course->created_by];
    })(),
    // args: course, creator, students(csv)
    'cleanup' => (function () use ($args) {
        [$course, $creator, $students] = $args;
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
        DB::table('users')->whereIn('id', array_merge([(int) $creator], array_filter(array_map('intval', explode(',', $students)))))->delete();

        return ['ok' => true];
    })(),
    // args: quiz
    'state' => (function () use ($args) {
        return [
            'live' => DB::table('quiz_questions')->where('quiz_id', $args[0])->whereNull('deleted_at')->orderBy('position')->orderBy('id')->get(['id', 'position'])->map(fn ($r) => [(int) $r->id, (int) $r->position])->all(),
            'all' => DB::table('quiz_questions')->where('quiz_id', $args[0])->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'attempts' => DB::table('quiz_attempts')->where('quiz_id', $args[0])->pluck('question_ids')->map(fn ($j) => json_decode($j, true))->all(),
        ];
    })(),
    // args: course, startAt, quiz, idsCsv
    'reorder' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $ids = array_map('intval', explode(',', $args[3]));
        $wait($args[1]);

        return $run(fn () => app(QuizContentService::class)->reorder($course, $quiz, $ids));
    })(),
    // args: course, startAt, quiz, qid
    'delete' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $q = QuizQuestion::findOrFail($args[3]);
        $wait($args[1]);

        return $run(fn () => app(QuizContentService::class)->delete($course, $quiz, $q));
    })(),
    'put_q' => (function () use ($args, $wait, $run, $payload) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $q = QuizQuestion::findOrFail($args[3]);
        $wait($args[1]);

        return $run(fn () => app(QuizContentService::class)->update($course, $quiz, $q, $payload('put')));
    })(),
    'add_q' => (function () use ($args, $wait, $run, $payload) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $wait($args[1]);

        return $run(fn () => app(QuizContentService::class)->create($course, $quiz, $payload($args[3])));
    })(),
    // args: studentId, startAt, quiz
    'start' => (function () use ($args, $wait, $run) {
        $user = User::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $wait($args[1]);

        return $run(fn () => app(QuizAttemptService::class)->start($user, $quiz));
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
