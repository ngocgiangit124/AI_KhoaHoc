<?php

/**
 * Tiến trình con cho test race thật (DBA checklist §5 mục 2): mỗi tiến trình = 1 kết nối MySQL riêng, autocommit.
 * Chỉ chạy trên DB tên `*_testing` (chặn nhầm DB dev). Dùng: php race_worker.php <mode> [args...]
 * Xuất ra 1 dòng JSON.
 */

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Subjects\SubjectService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) DB::connection()->getDatabaseName();
if (! str_ends_with($db, '_testing')) {
    fwrite(STDERR, "REFUSE: DB '$db' khong phai DB test\n");
    exit(9);
}

[$script, $mode] = $argv;
$args = array_slice($argv, 2);

$out = match ($mode) {
    'setup' => (function () {
        $user = User::factory()->student()->create();
        $course = Course::factory()->create();

        return ['user' => $user->id, 'course' => $course->id, 'creator' => $course->created_by];
    })(),
    'cleanup' => (function () use ($args) {
        DB::table('enrollments')->where('user_id', $args[0])->delete();
        DB::table('course_subject')->where('course_id', $args[1])->delete();
        DB::table('courses')->where('id', $args[1])->delete();
        DB::table('subjects')->where('name', 'like', 'RACE-%')->delete();
        DB::table('audit_logs')->where('action', 'like', 'subject.%')->where('changes', 'like', '%RACE-%')->delete();
        DB::table('users')->whereIn('id', [$args[0], $args[2]])->delete();

        return ['ok' => true];
    })(),
    'enroll' => (function () use ($args) {
        [$userId, $courseId, $status, $startAt] = $args;
        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        try {
            $e = Enrollment::factory()->create([
                'user_id' => $userId,
                'course_id' => $courseId,
                'status' => EnrollmentStatus::from($status),
            ]);

            return ['result' => 'ok', 'id' => $e->id];
        } catch (QueryException $e) {
            return ['result' => 'error', 'code' => (int) $e->errorInfo[1]];
        }
    })(),
    'create_subject' => (function () use ($args) {
        [$name, $startAt] = $args;
        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        try {
            $s = app(SubjectService::class)->create($name);

            return ['result' => 'ok', 'id' => $s->id, 'slug' => $s->slug];
        } catch (ValidationException $e) {
            return ['result' => 'validation', 'errors' => $e->errors()];
        } catch (Throwable $e) {
            return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)];
        }
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
