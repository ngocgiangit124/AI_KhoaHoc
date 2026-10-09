<?php

/** Tiến trình con cho race test T18. Chỉ chạy trên DB `*_testing*`. In 1 dòng JSON. */

use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\Courses\CourseService;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

config(['payments.enabled_gateways' => ['fake'], 'features.paid_checkout' => true]);

$mode = $argv[1];
$args = array_slice($argv, 2);
$wait = function (string $startAt): void {
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
};
$run = function (callable $fn): array {
    try {
        return ['result' => 'ok'] + $fn();
    } catch (DomainException $e) {
        return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 300)];
    }
};

$out = match ($mode) {
    // setup <n_users> <coupon: 0|1> <max_uses>: n HS (có giỏ 1 khóa chung 100k), tuỳ chọn mã 10% gắn giỏ.
    'setup' => (function () use ($args) {
        [$n, $withCoupon, $maxUses] = $args;
        $course = Course::factory()->published()->paid(100000)->create();
        $coupon = $withCoupon ? Coupon::factory()->percent(10)->create(['max_uses' => (int) $maxUses]) : null;
        $users = [];
        for ($i = 0; $i < (int) $n; $i++) {
            $u = User::factory()->student()->verified()->create();
            $cart = Cart::factory()->state(['user_id' => $u->id])->withCourses([$course]);
            $cart = $coupon ? $cart->withCoupon($coupon) : $cart;
            $cart->create();
            $users[] = $u->id;
        }

        return ['users' => $users, 'course' => $course->id, 'coupon' => $coupon?->id, 'creator' => $course->created_by];
    })(),
    'checkout' => (function () use ($args, $wait, $run) {
        [$userId, $expected, $startAt] = $args;
        $user = User::findOrFail($userId);
        $wait($startAt);

        return $run(function () use ($user, $expected) {
            $r = app(CheckoutService::class)->checkout($user, (int) $expected, 'fake');

            return ['order' => $r->order->code, 'reused' => $r->reused];
        });
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
    // setup_multi <n_users> <max_uses>: n HS, giỏ 3 khóa theo thứ tự id đảo (HS chẵn xuôi, lẻ ngược), mã 10% max_uses.
    'setup_multi' => (function () use ($args) {
        [$n, $maxUses] = $args;
        $courses = [Course::factory()->published()->paid(100000)->create(), Course::factory()->published()->paid(80000)->create(), Course::factory()->published()->paid(60000)->create()];
        $coupon = Coupon::factory()->percent(10)->create(['max_uses' => (int) $maxUses]);
        $users = [];
        for ($i = 0; $i < (int) $n; $i++) {
            $u = User::factory()->student()->verified()->create();
            $order = $i % 2 === 0 ? $courses : array_reverse($courses);
            Cart::factory()->state(['user_id' => $u->id])->withCourses($order)->withCoupon($coupon)->create();
            $users[] = $u->id;
        }

        return ['users' => $users, 'courses' => array_map(fn ($c) => $c->id, $courses), 'coupon' => $coupon->id, 'creator' => $courses[0]->created_by];
    })(),
    // checkout_then_pay <user> <expected> <startAt>: checkout rồi markPaid ngay (mô phỏng IPN) trong cùng tiến trình.
    'prepare_order' => (function () use ($args, $run) {
        $user = User::findOrFail($args[0]);

        return $run(function () use ($user, $args) {
            $r = app(CheckoutService::class)->checkout($user, (int) $args[1], 'fake');

            return ['order_id' => $r->order->id];
        });
    })(),
    'mark_paid' => (function () use ($args, $wait, $run) {
        $order = Order::findOrFail($args[0]);
        $wait($args[1]);

        return $run(function () use ($order) {
            app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

            return [];
        });
    })(),
    'state_multi' => (function () use ($args) {
        $userIds = explode(',', $args[0]);

        return [
            'enrollments' => DB::table('enrollments')->whereIn('user_id', $userIds)->count(),
            'usages' => DB::table('coupon_usages')->where('coupon_id', $args[1])->count(),
            'used_count' => (int) DB::table('coupons')->where('id', $args[1])->value('used_count'),
            'paid' => DB::table('orders')->whereIn('user_id', $userIds)->where('status', 'paid')->count(),
            'review' => DB::table('orders')->whereIn('user_id', $userIds)->where('needs_review', 1)->count(),
            'pending_hold' => DB::table('orders')->whereIn('user_id', $userIds)->where('status', 'pending')->where('coupon_id', $args[1])->count(),
        ];
    })(),
    'state' => (function () use ($args) {
        [$userIds, $courseId, $couponId] = [explode(',', $args[0]), (int) $args[1], $args[2] ?? null];
        $pending = DB::table('orders')->whereIn('user_id', $userIds)->where('status', 'pending');

        return [
            'orders' => DB::table('orders')->whereIn('user_id', $userIds)->count(),
            'pending' => (clone $pending)->count(),
            'pending_with_coupon' => $couponId ? (clone $pending)->where('coupon_id', $couponId)->count() : 0,
            'attempts' => DB::table('payment_attempts')->whereIn('order_id', DB::table('orders')->whereIn('user_id', $userIds)->select('id'))->count(),
            'course_trashed' => DB::table('courses')->where('id', $courseId)->whereNotNull('deleted_at')->exists(),
            'pending_items_on_course' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.course_id', $courseId)->where('orders.status', 'pending')->count(),
        ];
    })(),
    'cleanup' => (function () use ($args) {
        $userIds = explode(',', $args[0]);
        $orderIds = DB::table('orders')->whereIn('user_id', $userIds)->pluck('id');
        DB::table('coupon_usages')->whereIn('order_id', $orderIds)->delete();
        DB::table('enrollments')->whereIn('user_id', $userIds)->delete();
        DB::table('payment_attempts')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_status_logs')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        DB::table('carts')->whereIn('user_id', $userIds)->delete();

        // BE-backlog-1: người tạo khóa/mã do factory sinh (giáo viên cho MỖI khóa, admin cho mã) cũng phải dọn, nếu không
        // chúng rò sang DB test và làm các test đếm/liệt kê giáo viên (T36/AdminTeacherProfilesTest) đỏ khi chạy sau T18.
        // Lấy từ DB (không tin `creator` do test truyền) vì `setup_multi` tạo 3 khóa/3 giáo viên và mã có `created_by` riêng.
        $courseIds = array_values(array_filter(explode(',', (string) $args[1])));
        $creatorIds = DB::table('courses')->whereIn('id', $courseIds)->pluck('created_by')->all();
        if ($args[2] ?? null) {
            $creatorIds = [...$creatorIds, ...DB::table('coupons')->where('id', $args[2])->pluck('created_by')->all()];
            DB::table('coupon_usages')->where('coupon_id', $args[2])->delete();
            DB::table('coupons')->where('id', $args[2])->delete();
        }
        if ($args[3] ?? null) {
            $creatorIds[] = $args[3];
        }
        DB::table('course_teacher')->whereIn('course_id', $courseIds)->delete();
        DB::table('courses')->whereIn('id', $courseIds)->delete();

        $doomed = array_values(array_unique(array_map('intval', array_filter([...$userIds, ...$creatorIds]))));
        foreach (['course_teacher', 'teacher_profiles'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('user_id', $doomed)->delete();
            }
        }
        DB::table('users')->whereIn('id', $doomed)->delete();

        return ['ok' => true];
    })(),
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
