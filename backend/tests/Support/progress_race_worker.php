<?php

/**
 * Tiến trình con cho race test T13 (mỗi tiến trình = 1 kết nối MySQL riêng, autocommit). Chỉ chạy trên DB `*_testing`.
 * Dùng: php progress_race_worker.php <mode> [args...] → in 1 dòng JSON.
 */

use App\Enums\VideoSource;
use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Courses\CurriculumService;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Learning\ProgressService;
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

[$script, $mode] = $argv;
$args = array_slice($argv, 2);

$wait = function (string $startAt): void {
    while (microtime(true) < (float) $startAt) {
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
    // 2 bài (xoá 1 bài không vướng "bài cuối"), 1 học sinh có enrollment active.
    'setup' => (function () {
        $student = User::factory()->student()->create();
        $course = Course::factory()->published()->create();
        $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
        $l1 = Lesson::factory()->for($course)->for($chapter)->create(['position' => 1, 'video_source' => VideoSource::None, 'duration_seconds' => 600]);
        $l2 = Lesson::factory()->for($course)->for($chapter)->create(['position' => 2, 'video_source' => VideoSource::None, 'duration_seconds' => 600]);
        Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

        return ['student' => $student->id, 'course' => $course->id, 'chapter' => $chapter->id, 'l1' => $l1->id, 'l2' => $l2->id, 'creator' => $course->created_by];
    })(),
    // args: student, lesson, position, delta, startAt
    'heartbeat' => (function () use ($args, $wait, $run) {
        [$studentId, $lessonId, $position, $delta, $startAt] = $args;
        $user = User::findOrFail($studentId);
        $lesson = Lesson::findOrFail($lessonId);
        $wait($startAt);

        return $run(fn () => app(ProgressService::class)->heartbeat($user, $lesson, (int) $position, (int) $delta));
    })(),
    // args: course, chapter, lesson, startAt
    'delete_lesson' => (function () use ($args, $wait, $run) {
        [$courseId, $chapterId, $lessonId, $startAt] = $args;
        $course = Course::findOrFail($courseId);
        $chapter = Chapter::findOrFail($chapterId);
        $lesson = Lesson::withTrashed()->findOrFail($lessonId);
        $wait($startAt);

        return $run(function () use ($course, $chapter, $lesson) {
            app(CurriculumService::class)->deleteLesson($course, $chapter, $lesson);

            return [];
        });
    })(),
    // args: student, course, startAt  (thu hồi enrollment)
    'revoke' => (function () use ($args, $wait, $run) {
        [$studentId, $courseId, $startAt] = $args;
        $e = Enrollment::query()->where('user_id', $studentId)->where('course_id', $courseId)->firstOrFail();
        $wait($startAt);

        return $run(function () use ($e) {
            app(EnrollmentService::class)->revoke($e, 'qa race');

            return [];
        });
    })(),
    // args: course, startAt  (ghi courses.enrollments_count như T14)
    'bump_count' => (function () use ($args, $wait, $run) {
        [$courseId, $startAt] = $args;
        $wait($startAt);

        return $run(function () use ($courseId) {
            DB::transaction(fn () => Course::query()->whereKey($courseId)->increment('enrollments_count'));

            return [];
        });
    })(),
    'state' => (function () use ($args) {
        return [
            'rows' => DB::table('lesson_progress')->where('user_id', $args[0])->where('lesson_id', $args[1])->count(),
            'watched' => (int) DB::table('lesson_progress')->where('user_id', $args[0])->where('lesson_id', $args[1])->value('watched_seconds'),
            'enroll_status' => (string) DB::table('enrollments')->where('user_id', $args[0])->value('status'),
            'deleted' => DB::table('lessons')->where('id', $args[1])->whereNotNull('deleted_at')->exists(),
        ];
    })(),
    // args: student, course, creator
    // Không xoá audit_logs: trigger L2 chặn DELETE dòng mới và DB test riêng nên dòng audit không ảnh hưởng assert.
    'cleanup' => (function () use ($args) {
        DB::table('lesson_progress')->where('user_id', $args[0])->delete();
        DB::table('enrollments')->where('user_id', $args[0])->delete();
        DB::table('lessons')->where('course_id', $args[1])->delete();
        DB::table('chapters')->where('course_id', $args[1])->delete();
        DB::table('courses')->where('id', $args[1])->delete();
        DB::table('users')->whereIn('id', [$args[0], $args[2]])->delete();

        return ['ok' => true];
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
