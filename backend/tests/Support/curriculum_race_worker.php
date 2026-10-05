<?php

/** Tiến trình con cho race test T09 (1 kết nối MySQL/tiến trình). Chỉ chạy trên DB `*_testing`. */

use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Courses\CurriculumService;
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

$out = match ($mode) {
    'setup' => (function () {
        $course = Course::factory()->create();
        $c1 = Chapter::factory()->for($course)->create(['position' => 1]);
        $c2 = Chapter::factory()->for($course)->create(['position' => 2]);
        $l1 = Lesson::factory()->for($course)->for($c1)->create(['position' => 1]);
        $l2 = Lesson::factory()->for($course)->for($c2)->create(['position' => 1]);

        return ['course' => $course->id, 'c1' => $c1->id, 'c2' => $c2->id, 'l1' => $l1->id, 'l2' => $l2->id, 'creator' => $course->created_by];
    })(),
    'cleanup' => (function () use ($args) {
        DB::table('audit_logs')->where('subject_type', (new Course)->getMorphClass())->where('subject_id', $args[0])->delete();
        DB::table('lessons')->where('course_id', $args[0])->delete();
        DB::table('chapters')->where('course_id', $args[0])->delete();
        DB::table('courses')->where('id', $args[0])->delete();
        DB::table('users')->where('id', $args[1])->delete();

        return ['ok' => true];
    })(),
    'state' => (function () use ($args) {
        return [
            'chapters' => DB::table('chapters')->where('course_id', $args[0])->orderBy('position')->get(['id', 'position'])->all(),
            'lessons' => DB::table('lessons')->where('course_id', $args[0])->whereNull('deleted_at')->orderBy('chapter_id')->orderBy('position')->get(['id', 'chapter_id', 'position'])->all(),
        ];
    })(),
    // reorder: args = course, startAt, json items
    'reorder' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $items = json_decode($args[2], true);
        $wait($args[1]);

        return $run(fn () => app(CurriculumService::class)->reorder($course, $items));
    })(),
    'create_lesson' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $chapter = Chapter::findOrFail($args[2]);
        $wait($args[1]);

        return $run(fn () => app(CurriculumService::class)->createLesson($course, $chapter, ['title' => 'race']));
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
