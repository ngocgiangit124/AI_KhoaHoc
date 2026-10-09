<?php

/** Tiến trình con cho race test T38 (thanh toán thủ công). Chỉ chạy trên DB `*_testing*`. In 1 dòng JSON. */

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\ManualOrderService;
use App\Services\Privacy\AccountAnonymizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    'orders.manual.notify_emails' => ['ops@example.com'], 'queue.default' => 'database',
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

$out = match ($mode) {
    // setup <n_users> <coupon 0|1> <max_uses>: n HS có giỏ 1 khóa chung 100k (mã 10% tuỳ chọn) và OTP xoá tài khoản.
    'setup' => (function () use ($args) {
        [$n, $withCoupon, $maxUses] = $args;
        $course = Course::factory()->published()->paid(100000)->create();
        $coupon = $withCoupon ? Coupon::factory()->percent(10)->create(['max_uses' => (int) $maxUses]) : null;
        $users = [];
        for ($i = 0; $i < (int) $n; $i++) {
            $u = User::factory()->student()->verified()->create(['email' => 'race38-'.uniqid('', true).'@example.test']);
            $cart = Cart::factory()->state(['user_id' => $u->id])->withCourses([$course]);
            ($coupon ? $cart->withCoupon($coupon) : $cart)->create();
            OtpCode::query()->create([
                'user_id' => $u->id, 'purpose' => OtpPurpose::DeleteAccount, 'channel' => 'email', 'destination' => $u->email,
                'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
            ]);
            $users[] = $u->id;
        }

        return ['users' => $users, 'course' => $course->id, 'coupon' => $coupon?->id, 'creator' => $course->created_by, 'jobs' => (int) DB::table('jobs')->max('id')];
    })(),
    // setup_multi <user> <n>: giỏ của HS có n khóa riêng (giá (i+1)*10000) để mỗi worker chọn 1 khóa → nội dung khác nhau.
    'setup_courses' => (function () use ($args) {
        $ids = [];
        for ($i = 1; $i <= (int) $args[0]; $i++) {
            $ids[] = Course::factory()->published()->paid($i * 10000)->create()->id;
        }

        return ['courses' => $ids];
    })(),
    // seed_today <user> <n>: n đơn manual đã huỷ tạo hôm nay (tính vào hạn mức).
    'seed_today' => (function () use ($args) {
        for ($i = 0; $i < (int) $args[1]; $i++) {
            Order::factory()->manual()->cancelled('user_cancelled')->create(['user_id' => (int) $args[0]]);
        }

        return ['ok' => true];
    })(),
    // checkout <user> <expected> <startAt> <replace 0|1> [course_id]: nếu có course_id, đặt giỏ = đúng khóa đó trước khi chạy.
    'checkout' => (function () use ($args, $wait, $run) {
        [$id, $expected, $startAt, $replace] = $args;
        $user = User::findOrFail($id);
        if (isset($args[4])) {
            $cartId = Cart::query()->where('user_id', $id)->value('id');
            DB::table('cart_items')->where('cart_id', $cartId)->delete();
            DB::table('cart_items')->insert(['cart_id' => $cartId, 'course_id' => (int) $args[4], 'created_at' => now()]);
        }
        $wait($startAt);

        return $run(function () use ($user, $expected, $replace) {
            $r = app(CheckoutService::class)->checkout($user, (int) $expected, 'manual', null, $replace === '1');

            return ['order' => $r->order->code, 'reused' => $r->reused];
        });
    })(),
    // expired_order <user>: đơn manual pending đã quá hạn kèm dòng đơn.
    'expired_order' => (function () use ($args) {
        $order = Order::factory()->expiredManual()->create(['user_id' => (int) $args[0]]);
        DB::table('order_items')->insert(['order_id' => $order->id, 'course_id' => (int) $args[1], 'course_title' => 'T', 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000]);

        return ['order' => $order->id, 'code' => $order->code];
    })(),
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
    'expire' => (function () use ($args, $wait, $run) {
        $wait($args[0]);

        return $run(fn () => ['cancelled' => app(ManualOrderService::class)->expireDue()]);
    })(),
    'delete' => (function () use ($args, $wait, $run) {
        [$id, $startAt] = $args;
        $user = User::findOrFail($id);
        Auth::setUser($user);
        $wait($startAt);

        return $run(function () use ($user) {
            app(AccountAnonymizer::class)->confirm($user, '123456');

            return [];
        });
    })(),
    // state <user_ids csv> <coupon|-> <jobs_baseline>
    'state' => (function () use ($args) {
        $ids = explode(',', $args[0]);
        $base = (int) ($args[2] ?? 0);
        $orders = DB::table('orders')->whereIn('user_id', $ids);
        $orderIds = (clone $orders)->pluck('id');

        return [
            'orders' => (clone $orders)->count(),
            'manual' => (clone $orders)->where('payment_method', 'manual')->count(),
            'pending' => (clone $orders)->where('status', 'pending')->count(),
            'pending_manual' => (clone $orders)->where('status', 'pending')->where('payment_method', 'manual')->count(),
            'with_coupon' => $args[1] !== '-' ? (clone $orders)->where('status', 'pending')->where('coupon_id', $args[1])->count() : 0,
            'cancel_logs' => DB::table('order_status_logs')->whereIn('order_id', $orderIds)->where('to_status', 'cancelled')->count(),
            'attempts' => DB::table('payment_attempts')->whereIn('order_id', $orderIds)->count(),
            'jobs' => DB::table('jobs')->where('id', '>', $base)->count(),
            'anonymized' => DB::table('users')->whereIn('id', $ids)->whereNotNull('anonymized_at')->count(),
        ];
    })(),
    // cleanup <user_ids csv> <course_ids csv> <coupon|-> <creator> <jobs_baseline>
    'cleanup' => (function () use ($args) {
        $ids = explode(',', $args[0]);
        $orderIds = DB::table('orders')->whereIn('user_id', $ids)->pluck('id')->all();
        DB::table('payment_attempts')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_status_logs')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        $cartIds = DB::table('carts')->whereIn('user_id', $ids)->pluck('id')->all();
        DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
        DB::table('carts')->whereIn('user_id', $ids)->delete();
        DB::table('otp_codes')->whereIn('user_id', $ids)->delete();
        DB::table('consents')->whereIn('user_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();
        if ($args[2] !== '-') {
            DB::table('coupons')->where('id', $args[2])->delete();
        }
        DB::table('courses')->whereIn('id', explode(',', $args[1]))->delete();
        DB::table('users')->where('id', (int) $args[3])->delete();
        DB::table('jobs')->where('id', '>', (int) $args[4])->delete();

        return ['ok' => true];
    })(),
    default => ['error' => 'mode?'],
};

echo json_encode($out), "\n";
