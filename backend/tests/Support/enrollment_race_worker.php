<?php

/**
 * Tiến trình con cho race test T14 (mỗi tiến trình = 1 kết nối MySQL riêng, autocommit). Chỉ chạy trên DB `*_testing`.
 * Dùng: php enrollment_race_worker.php <mode> [args...] → in 1 dòng JSON.
 */

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\Courses\CourseService;
use App\Services\Enrollment\EnrollmentService;
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
    'setup' => (function () {
        $user = User::factory()->student()->verified()->create();
        $course = Course::factory()->published()->create();
        $admin = User::factory()->admin()->create();
        // T18: enrollments.order_id có FK → orders.id, nên `grant` cần đơn thật.
        $order = Order::factory()->create(['user_id' => $user->id]);

        return ['user' => $user->id, 'course' => $course->id, 'creator' => $course->created_by, 'admin' => $admin->id, 'order' => $order->id];
    })(),
    'pending' => (function () use ($args) {
        // Tạo sẵn 1 yêu cầu chờ duyệt, trả id.
        $e = app(EnrollmentService::class)->requestFree(User::findOrFail($args[0]), Course::findOrFail($args[1]));

        return ['enrollment' => $e->id];
    })(),
    'state' => (function () use ($args) {
        return [
            'rows' => DB::table('enrollments')->where('user_id', $args[0])->where('course_id', $args[1])->count(),
            'live' => DB::table('enrollments')->where('user_id', $args[0])->where('course_id', $args[1])->where('live_flag', 1)->count(),
            'active' => DB::table('enrollments')->where('user_id', $args[0])->where('course_id', $args[1])->where('status', 'active')->count(),
            'count' => (int) DB::table('courses')->where('id', $args[1])->value('enrollments_count'),
        ];
    })(),
    // Không xoá audit_logs: trigger L2 chặn DELETE dòng mới và DB test riêng nên dòng audit không ảnh hưởng assert.
    'cleanup' => (function () use ($args) {
        DB::table('enrollments')->where('user_id', $args[0])->delete();
        DB::table('orders')->where('user_id', $args[0])->delete();
        DB::table('courses')->where('id', $args[1])->delete();
        DB::table('users')->whereIn('id', [$args[0], $args[2], $args[3]])->delete();

        return ['ok' => true];
    })(),
    'request' => (function () use ($args, $wait, $run) {
        [$userId, $courseId, $startAt] = $args;
        $user = User::findOrFail($userId);
        $course = Course::findOrFail($courseId);
        $wait($startAt);

        return $run(fn () => ['id' => app(EnrollmentService::class)->requestFree($user, $course)->id]);
    })(),
    'approve', 'reject' => (function () use ($args, $wait, $run, $mode) {
        [$enrollmentId, $adminId, $startAt] = $args;
        $enrollment = Enrollment::findOrFail($enrollmentId);
        $admin = User::findOrFail($adminId);
        $wait($startAt);

        return $run(function () use ($mode, $enrollment, $admin) {
            $svc = app(EnrollmentService::class);
            $mode === 'approve' ? $svc->approve($enrollment, $admin) : $svc->reject($enrollment, $admin, 'race');

            return [];
        });
    })(),
    'grant' => (function () use ($args, $wait, $run) {
        [$userId, $courseId, $orderId, $startAt] = $args;
        $user = User::findOrFail($userId);
        $course = Course::findOrFail($courseId);
        $wait($startAt);

        return $run(fn () => ['id' => app(EnrollmentService::class)->grantPurchase($user, $course, (int) $orderId)->id]);
    })(),
    'delete_course' => (function () use ($args, $wait, $run) {
        [$courseId, $startAt] = $args;
        $course = Course::findOrFail($courseId);
        $wait($startAt);

        return $run(function () use ($course) {
            app(CourseService::class)->delete($course);

            return [];
        });
    })(),
    'course_state' => (function () use ($args) {
        return [
            'trashed' => DB::table('courses')->where('id', $args[0])->whereNotNull('deleted_at')->exists(),
            'enrollments' => DB::table('enrollments')->where('course_id', $args[0])->count(),
            'count' => (int) DB::table('courses')->where('id', $args[0])->value('enrollments_count'),
        ];
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
