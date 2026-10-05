<?php

/** Tiến trình con cho race test T11 (1 kết nối MySQL/tiến trình). Chỉ chạy trên DB `*_testing`. */

use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use App\Services\Courses\CurriculumService;
use App\Services\Video\VideoUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

config(['video.provider' => 'fake', 'video.enabled_providers' => ['fake']]);

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
    // 2 bài ở cùng khoá; actor có sẵn $args[0] GB đã dùng trong ngày
    'setup' => (function () use ($args) {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
        $l1 = Lesson::factory()->for($course)->for($chapter)->create(['position' => 1]);
        $l2 = Lesson::factory()->for($course)->for($chapter)->create(['position' => 2]);
        $actor = User::factory()->teacher()->create();
        if ((int) $args[0] > 0) {
            VideoAsset::factory()->create(['provider' => 'fake', 'lesson_id' => $l2->id, 'created_by' => $actor->id, 'declared_size_bytes' => (int) $args[0] * 1024 * 1024 * 1024]);
        }

        return ['course' => $course->id, 'chapter' => $chapter->id, 'l1' => $l1->id, 'l2' => $l2->id, 'actor' => $actor->id, 'creator' => $course->created_by];
    })(),
    'cleanup' => (function () use ($args) {
        DB::table('audit_logs')->where('subject_type', (new Lesson)->getMorphClass())->whereIn('subject_id', [$args[1], $args[2]])->delete();
        DB::table('audit_logs')->where('subject_type', (new Course)->getMorphClass())->where('subject_id', $args[0])->delete();
        DB::table('lessons')->where('course_id', $args[0])->update(['video_asset_id' => null]);
        DB::table('video_assets')->where('created_by', $args[3])->delete();
        DB::table('lessons')->where('course_id', $args[0])->delete();
        DB::table('chapters')->where('course_id', $args[0])->delete();
        DB::table('courses')->where('id', $args[0])->delete();
        DB::table('users')->whereIn('id', [$args[3], $args[4]])->delete();

        return ['ok' => true];
    })(),
    'state' => (function () use ($args) {
        return [
            'assets' => DB::table('video_assets')->where('created_by', $args[0])->orderBy('id')->get(['id', 'status', 'lesson_id', 'declared_size_bytes'])->all(),
            'lessons' => DB::table('lessons')->where('course_id', $args[1])->whereNull('deleted_at')->orderBy('position')->get(['id', 'position', 'video_asset_id'])->all(),
        ];
    })(),
    // upload: args = course, lesson, actor, startAt, sizeMB
    'upload' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $lesson = Lesson::findOrFail($args[1]);
        $actor = User::findOrFail($args[2]);
        $wait($args[3]);

        return $run(fn () => app(VideoUploadService::class)->createUpload($actor, $course, $lesson, 'a.mp4', (int) $args[4] * 1024 * 1024));
    })(),
    'create_lesson' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $chapter = Chapter::findOrFail($args[2]);
        $wait($args[1]);

        return $run(fn () => app(CurriculumService::class)->createLesson($course, $chapter, ['title' => 'race']));
    })(),
    'reorder' => (function () use ($args, $wait, $run) {
        $course = Course::findOrFail($args[0]);
        $wait($args[1]);

        return $run(fn () => app(CurriculumService::class)->reorder($course, json_decode($args[2], true)));
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
