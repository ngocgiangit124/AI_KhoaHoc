<?php

/** Tiến trình con cho race test hoàn tiền (T24-V1). Chỉ chạy trên DB `*_testing*`. In 1 dòng JSON. */

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Orders\RefundService;
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
$wait = function (string $startAt): void {
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
};

$out = match ($mode) {
    // setup <n_courses>: 1 HS, 1 staff, đơn manual ĐÃ paid (markPaid thật) với n khóa → n enrollment active gắn đơn.
    'setup' => (function () use ($args) {
        $student = User::factory()->student()->verified()->create();
        $staff = User::factory()->pageManager()->create();
        $order = Order::factory()->manual()->create(['user_id' => $student->id, 'subtotal_amount' => 100000 * (int) $args[0], 'total_amount' => 100000 * (int) $args[0]]);
        $courses = [];
        for ($i = 0; $i < (int) $args[0]; $i++) {
            $c = Course::factory()->published()->paid(100000)->create();
            $courses[] = $c->id;
            DB::table('order_items')->insert(['order_id' => $order->id, 'course_id' => $c->id, 'course_title' => $c->title, 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000]);
        }
        app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

        return ['student' => $student->id, 'staff' => $staff->id, 'order' => $order->id, 'courses' => $courses, 'creator' => $courses ? Course::find($courses[0])->created_by : 0];
    })(),
    // refund <order> <staff> <startAt>
    'refund' => (function () use ($args, $wait) {
        [$orderId, $staffId, $startAt] = $args;
        $order = Order::findOrFail($orderId);
        $staff = User::findOrFail($staffId);
        $wait($startAt);

        try {
            app(RefundService::class)->refund($order, $staff, null);

            return ['result' => 'ok'];
        } catch (DomainException $e) {
            return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
        } catch (Throwable $e) {
            return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 300)];
        }
    })(),
    // state <order> <courses csv>
    'state' => (function () use ($args) {
        $courses = explode(',', $args[1]);

        return [
            'status' => DB::table('orders')->where('id', $args[0])->value('status'),
            'revoked' => DB::table('enrollments')->where('order_id', $args[0])->where('status', 'revoked')->count(),
            'active' => DB::table('enrollments')->where('order_id', $args[0])->where('status', 'active')->count(),
            'counts' => DB::table('courses')->whereIn('id', $courses)->pluck('enrollments_count')->map(fn ($c) => (int) $c)->all(),
            'refund_logs' => DB::table('order_status_logs')->where('order_id', $args[0])->where('to_status', 'refunded')->count(),
            'audit_refund' => DB::table('audit_logs')->where('action', 'order.refund')->where('subject_id', $args[0])->count(),
            'audit_revoke' => DB::table('audit_logs')->where('action', 'enrollment.revoke')->whereIn('subject_id', DB::table('enrollments')->where('order_id', $args[0])->pluck('id'))->count(),
        ];
    })(),
    // cleanup <student> <staff> <order> <courses csv> <creator>
    'cleanup' => (function () use ($args) {
        DB::table('enrollments')->where('order_id', $args[2])->delete();
        DB::table('order_status_logs')->where('order_id', $args[2])->delete();
        DB::table('order_items')->where('order_id', $args[2])->delete();
        DB::table('coupon_usages')->where('order_id', $args[2])->delete();
        DB::table('orders')->where('id', $args[2])->delete();
        DB::table('carts')->where('user_id', $args[0])->delete();
        DB::table('users')->whereIn('id', [(int) $args[0], (int) $args[1]])->delete();
        DB::table('courses')->whereIn('id', explode(',', $args[3]))->delete();
        DB::table('users')->where('id', (int) $args[4])->delete();

        return ['ok' => true];
    })(),
    default => ['error' => 'mode?'],
};

echo json_encode($out), "\n";
