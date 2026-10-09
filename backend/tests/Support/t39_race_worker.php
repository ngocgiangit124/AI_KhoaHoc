<?php

/**
 * Tiến trình con cho race test T39 (duyệt / huỷ / ghi chú đơn thủ công). Chỉ chạy trên DB `*_testing*`. In 1 dòng JSON.
 * Thư đếm qua bảng `jobs` (queue database): `displayName` của job mã hoá vẫn đọc được.
 */

use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\Courses\CourseService;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\ManualOrderService;
use App\Services\Orders\RefundService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

config([
    'payments.enabled_gateways' => ['fake'], 'features.paid_checkout' => false, 'features.manual_payment' => true,
    'features.parent_notices' => true, 'orders.manual.notify_emails' => ['ops@example.com'], 'orders.manual.approval_window_days' => 30,
    'queue.default' => 'database',
]);

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
    } catch (ValidationException $e) {
        return ['result' => 'validation', 'keys' => array_keys($e->errors())];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 300)];
    }
};
$staffLogin = function (int $staffId): User {
    $staff = User::findOrFail($staffId);
    Auth::setUser($staff);

    return $staff;
};

$out = match ($mode) {
    // setup <kind pending|expired|cancelled> <coupon 0|1>: 1 HS (có email phụ huynh), 2 staff, 1 khóa 100k, đơn manual, giỏ chứa khóa.
    'setup' => (function () use ($args) {
        [$kind, $withCoupon] = $args;
        $course = Course::factory()->published()->paid(100000)->create();
        $coupon = $withCoupon ? Coupon::factory()->percent(10)->create(['max_uses' => 5, 'used_count' => 0]) : null;
        $student = User::factory()->student()->verified()->create(['email' => 'race39-'.uniqid('', true).'@example.test', 'parent_email' => 'ph-'.uniqid('', true).'@example.test']);
        $staff = [User::factory()->admin()->create()->id, User::factory()->pageManager()->create()->id];

        $factory = Order::factory()->manual();
        $factory = match ($kind) {
            'expired' => Order::factory()->expiredManual(),
            'cancelled' => $factory->cancelled('expired'),
            default => $factory,
        };
        $order = $factory->create(array_filter([
            'user_id' => $student->id, 'subtotal_amount' => 100000, 'total_amount' => 100000,
            'coupon_id' => $coupon?->id, 'coupon_code' => $coupon?->code,
            'cancelled_at' => $kind === 'cancelled' ? now()->subDay() : null,
        ], fn ($v) => $v !== null));
        DB::table('order_items')->insert(['order_id' => $order->id, 'course_id' => $course->id, 'course_title' => 'Khoa race', 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000]);
        Cart::factory()->state(['user_id' => $student->id])->withCourses([$course])->create();
        $extra = Course::factory()->published()->paid(100000)->create();

        return [
            'student' => $student->id, 'staff' => $staff, 'order' => $order->id, 'code' => $order->code, 'course' => $course->id,
            'extra_course' => $extra->id, 'coupon' => $coupon?->id, 'creator' => $course->created_by, 'jobs' => (int) DB::table('jobs')->max('id'),
        ];
    })(),
    // approve <order> <staff> <late 0|1> <startAt>
    'approve' => (function () use ($args, $wait, $run, $staffLogin) {
        [$orderId, $staffId, $late, $startAt] = $args;
        $staff = $staffLogin((int) $staffId);
        $order = Order::findOrFail($orderId);
        $wait($startAt);

        return $run(function () use ($order, $staff, $late) {
            app(ManualOrderService::class)->approve($order, $staff, $late === '1', 'REF1', null);

            return [];
        });
    })(),
    // cancel_staff <order> <staff> <startAt>
    'cancel_staff' => (function () use ($args, $wait, $run, $staffLogin) {
        [$orderId, $staffId, $startAt] = $args;
        $staff = $staffLogin((int) $staffId);
        $order = Order::findOrFail($orderId);
        $wait($startAt);

        return $run(function () use ($order, $staff) {
            app(ManualOrderService::class)->cancelByStaff($order, $staff, 'Lý do huỷ race', null);

            return [];
        });
    })(),
    // cancel_student <user> <order> <startAt>
    'cancel_student' => (function () use ($args, $wait, $run) {
        [$userId, $orderId, $startAt] = $args;
        $user = User::findOrFail($userId);
        $order = Order::findOrFail($orderId);
        $wait($startAt);

        return $run(function () use ($order, $user) {
            app(ManualOrderService::class)->cancelByStudent($order, $user);

            return [];
        });
    })(),
    // expire <startAt>  (qua lệnh artisan thật)
    'expire' => (function () use ($args, $wait, $run) {
        $wait($args[0]);

        return $run(function () {
            Artisan::call('orders:expire-manual');

            return [];
        });
    })(),
    // checkout_replace <user> <course_id thêm vào giỏ> <expected> <startAt>: giỏ += khóa phụ rồi POST checkout replace_pending
    'checkout_replace' => (function () use ($args, $wait, $run) {
        [$userId, $extraCourse, $expected, $startAt] = $args;
        $user = User::findOrFail($userId);
        $cartId = Cart::query()->where('user_id', $userId)->value('id');
        DB::table('cart_items')->insertOrIgnore(['cart_id' => $cartId, 'course_id' => (int) $extraCourse, 'created_at' => now()]);
        $wait($startAt);

        return $run(function () use ($user, $expected) {
            $r = app(CheckoutService::class)->checkout($user, (int) $expected, 'manual', null, true);

            return ['order' => $r->order->code, 'reused' => $r->reused];
        });
    })(),
    // delete_course <course> <startAt>
    'delete_course' => (function () use ($args, $wait, $run) {
        [$courseId, $startAt] = $args;
        $course = Course::findOrFail($courseId);
        $wait($startAt);

        return $run(function () use ($course) {
            app(CourseService::class)->delete($course);

            return [];
        });
    })(),
    // refund <order> <staff> <startAt>
    'refund' => (function () use ($args, $wait, $run, $staffLogin) {
        [$orderId, $staffId, $startAt] = $args;
        $staff = $staffLogin((int) $staffId);
        $order = Order::findOrFail($orderId);
        $wait($startAt);

        return $run(function () use ($order, $staff) {
            app(RefundService::class)->refund($order, $staff, null);

            return [];
        });
    })(),
    // state <order> <course> <coupon|-> <student> <jobs_baseline>
    'state' => (function () use ($args) {
        [$orderId, $courseId, $coupon, $studentId, $base] = $args;
        $mails = fn (string $name) => DB::table('jobs')->where('id', '>', (int) $base)->where('payload', 'like', '%'.$name.'%')->count();

        return [
            'status' => DB::table('orders')->where('id', $orderId)->value('status'),
            'reason' => DB::table('orders')->where('id', $orderId)->value('status_reason'),
            'confirmed_by' => DB::table('orders')->where('id', $orderId)->value('confirmed_by'),
            'enroll_active' => DB::table('enrollments')->where('user_id', $studentId)->where('course_id', $courseId)->where('status', 'active')->count(),
            'enroll_total' => DB::table('enrollments')->where('user_id', $studentId)->where('course_id', $courseId)->count(),
            'enroll_count' => (int) DB::table('courses')->where('id', $courseId)->value('enrollments_count'),
            'usages' => DB::table('coupon_usages')->where('order_id', $orderId)->count(),
            'used_count' => $coupon !== '-' ? (int) DB::table('coupons')->where('id', $coupon)->value('used_count') : 0,
            'paid_logs' => DB::table('order_status_logs')->where('order_id', $orderId)->where('to_status', 'paid')->count(),
            'cancel_logs' => DB::table('order_status_logs')->where('order_id', $orderId)->where('to_status', 'cancelled')->count(),
            'refund_logs' => DB::table('order_status_logs')->where('order_id', $orderId)->where('to_status', 'refunded')->count(),
            'audit_approve' => DB::table('audit_logs')->where('action', 'order.manual_approve')->where('subject_id', $orderId)->count(),
            'audit_cancel' => DB::table('audit_logs')->where('action', 'order.manual_cancel')->where('subject_id', $orderId)->count(),
            'mail_paid' => $mails('OrderPaidMail'),
            'mail_parent' => $mails('ParentNoticeMail'),
            'mail_cancelled' => $mails('ManualOrderCancelledMail'),
            'orders_of_student' => DB::table('orders')->where('user_id', $studentId)->count(),
            'new_order_has_course' => DB::table('orders')->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.user_id', $studentId)->where('orders.id', '!=', $orderId)->where('order_items.course_id', $courseId)->count(),
            'course_deleted' => DB::table('courses')->where('id', $courseId)->whereNotNull('deleted_at')->count(),
        ];
    })(),
    // cleanup <student> <staff csv> <order> <courses csv> <coupon|-> <creator> <jobs_baseline>
    'cleanup' => (function () use ($args) {
        [$student, $staff, , $courses, $coupon, $creator, $base] = $args;
        $orderIds = DB::table('orders')->where('user_id', $student)->pluck('id')->all();
        DB::table('enrollments')->where('user_id', $student)->delete();
        DB::table('coupon_usages')->where('user_id', $student)->delete();
        DB::table('payment_attempts')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_notes')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_status_logs')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        $cartIds = DB::table('carts')->where('user_id', $student)->pluck('id')->all();
        DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
        DB::table('carts')->where('user_id', $student)->delete();
        DB::table('consents')->where('user_id', $student)->delete();
        DB::table('users')->whereIn('id', [(int) $student, ...array_map('intval', explode(',', $staff))])->delete();
        if ($coupon !== '-') {
            DB::table('coupons')->where('id', $coupon)->delete();
        }
        $courseIds = array_map('intval', explode(',', $courses));
        DB::table('chapters')->whereIn('course_id', $courseIds)->delete();
        DB::table('courses')->whereIn('id', $courseIds)->delete();
        DB::table('users')->where('id', (int) $creator)->delete();
        DB::table('jobs')->where('id', '>', (int) $base)->delete();

        return ['ok' => true];
    })(),
    default => ['error' => 'mode?'],
};

echo json_encode($out), "\n";
