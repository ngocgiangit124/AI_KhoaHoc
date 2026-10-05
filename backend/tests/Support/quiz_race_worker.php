<?php

/** Tiến trình con cho race test T21 (1 kết nối MySQL/tiến trình). Chỉ chạy trên DB `*_testing`. */

use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\Courses\CurriculumService;
use App\Services\Quiz\QuizContentService;
use App\Services\Quiz\QuizService;
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
    'setup' => (function () use ($payload) {
        $course = Course::factory()->create();
        $c1 = Chapter::factory()->for($course)->create(['position' => 1]);
        $c2 = Chapter::factory()->for($course)->create(['position' => 2]);
        $l1 = Lesson::factory()->for($course)->for($c1)->create(['position' => 1]);
        $l2 = Lesson::factory()->for($course)->for($c2)->create(['position' => 1]);
        $l3 = Lesson::factory()->for($course)->for($c2)->create(['position' => 2]);
        $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $c1->id, 'position' => 1]);
        $q = app(QuizContentService::class)->create($course, $quiz, $payload('seed'));

        return ['course' => $course->id, 'c1' => $c1->id, 'c2' => $c2->id, 'l1' => $l1->id, 'l2' => $l2->id, 'l3' => $l3->id,
            'quiz' => $quiz->id, 'q' => $q->id, 'creator' => $course->created_by];
    })(),
    'cleanup' => (function () use ($args) {
        $qids = DB::table('quiz_questions')->whereIn('quiz_id', DB::table('quizzes')->where('course_id', $args[0])->pluck('id'))->pluck('id');
        DB::table('quiz_options')->whereIn('question_id', $qids)->delete();
        DB::table('quiz_questions')->whereIn('id', $qids)->update(['replaced_by_id' => null]);
        DB::table('quiz_questions')->whereIn('id', $qids)->delete();
        DB::table('quizzes')->where('course_id', $args[0])->delete();
        DB::table('audit_logs')->where('subject_type', (new Course)->getMorphClass())->where('subject_id', $args[0])->delete();
        DB::table('lessons')->where('course_id', $args[0])->delete();
        DB::table('chapters')->where('course_id', $args[0])->delete();
        DB::table('courses')->where('id', $args[0])->delete();
        DB::table('users')->where('id', $args[1])->delete();

        return ['ok' => true];
    })(),
    'state' => (function () use ($args) {
        $quizIds = DB::table('quizzes')->where('course_id', $args[0])->pluck('id');

        return [
            'quizzes' => DB::table('quizzes')->where('course_id', $args[0])->get(['id', 'chapter_id', 'lesson_id', 'deleted_at'])->all(),
            'questions_live' => DB::table('quiz_questions')->whereIn('quiz_id', $quizIds)->whereNull('deleted_at')->count(),
            'questions_total' => DB::table('quiz_questions')->whereIn('quiz_id', $quizIds)->count(),
            'positions' => DB::table('quiz_questions')->whereIn('quiz_id', $quizIds)->whereNull('deleted_at')->pluck('position')->all(),
        ];
    })(),
    // args: course, startAt, chapterId
    'delete_chapter' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $chapter = Chapter::findOrFail($args[2]);
        $wait($args[1]);

        return $run(fn () => app(CurriculumService::class)->deleteChapter($course, $chapter));
    })(),
    // args: course, startAt, chapterId, lessonId
    'delete_lesson' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $chapter = Chapter::findOrFail($args[2]);
        $lesson = Lesson::findOrFail($args[3]);
        $wait($args[1]);

        return $run(fn () => app(CurriculumService::class)->deleteLesson($course, $chapter, $lesson));
    })(),
    // args: course, startAt, quizId, label
    'add_q' => (function () use ($args, $wait, $run, $payload) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $wait($args[1]);

        return $run(fn () => app(QuizContentService::class)->create($course, $quiz, $payload($args[3])));
    })(),
    // args: course, startAt, quizId, questionId
    'put_q' => (function () use ($args, $wait, $run, $payload) {
        $course = Course::findOrFail($args[0]);
        $quiz = Quiz::findOrFail($args[2]);
        $q = QuizQuestion::findOrFail($args[3]);
        $wait($args[1]);

        return $run(fn () => app(QuizContentService::class)->update($course, $quiz, $q, $payload('put')));
    })(),
    // args: course, startAt, lessonId|chapterId, 'lesson'|'chapter'
    'create_quiz' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $wait($args[1]);
        $data = ['title' => 'race', $args[3].'_id' => (int) $args[2]];

        return $run(fn () => app(QuizService::class)->create($course, $data));
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
