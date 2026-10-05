<?php

/** Tiến trình con cho race test T16. Chỉ chạy trên DB `*_testing`. In 1 dòng JSON. */

use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\User;
use App\Services\Cart\CartService;
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

$out = match ($mode) {
    'setup' => (function () {
        $user = User::factory()->student()->create();
        $course = Course::factory()->published()->paid(100000)->create();

        return ['user' => $user->id, 'course' => $course->id, 'creator' => $course->created_by];
    })(),
    'cleanup' => (function () use ($args) {
        DB::table('carts')->where('user_id', $args[0])->delete();
        DB::table('courses')->where('id', $args[1])->delete();
        DB::table('users')->whereIn('id', [$args[0], $args[2]])->delete();

        return ['ok' => true];
    })(),
    'count' => ['rows' => DB::table('cart_items')->join('carts', 'carts.id', '=', 'cart_items.cart_id')->where('carts.user_id', $args[0])->count(),
        'carts' => DB::table('carts')->where('user_id', $args[0])->count()],
    'setup_scoped' => (function () {
        $user = User::factory()->student()->create();
        $course = Course::factory()->published()->paid(100000)->create();
        $other = Course::factory()->published()->paid(50000)->create();
        $coupon = Coupon::factory()->restricted()->percent(50)->create();
        $coupon->courses()->attach($course->id);
        app(CartService::class)->addItem($user, $course->id);
        app(CartService::class)->addItem($user, $other->id);

        return ['user' => $user->id, 'course' => $course->id, 'other' => $other->id, 'coupon' => $coupon->id, 'code' => $coupon->code, 'creator' => $course->created_by];
    })(),
    'cleanup_scoped' => (function () use ($args) {
        DB::table('carts')->where('user_id', $args[0])->delete();
        DB::table('coupons')->where('id', $args[3])->delete();
        DB::table('courses')->whereIn('id', [$args[1], $args[2]])->delete();
        DB::table('users')->whereIn('id', [$args[0], $args[4]])->delete();

        return ['ok' => true];
    })(),
    'cart_state' => (function () use ($args) {
        $cart = DB::table('carts')->where('user_id', $args[0])->first();

        return ['coupon_id' => $cart->coupon_id, 'items' => DB::table('cart_items')->where('cart_id', $cart->id)->pluck('course_id')->all()];
    })(),
    'apply' => (function () use ($args) {
        [$userId, $code, $startAt] = $args;
        $user = User::findOrFail($userId);
        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        try {
            app(CartService::class)->applyCoupon($user, $code);

            return ['result' => 'ok'];
        } catch (DomainException $e) {
            return ['result' => 'domain', 'code' => $e->code()];
        } catch (Throwable $e) {
            return ['result' => 'error', 'message' => $e::class.': '.$e->getMessage()];
        }
    })(),
    'remove' => (function () use ($args) {
        [$userId, $courseId, $startAt] = $args;
        $user = User::findOrFail($userId);
        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        try {
            app(CartService::class)->removeItem($user, (int) $courseId);

            return ['result' => 'ok'];
        } catch (Throwable $e) {
            return ['result' => 'error', 'message' => $e::class.': '.$e->getMessage()];
        }
    })(),
    'add' => (function () use ($args) {
        [$userId, $courseId, $startAt] = $args;
        $user = User::findOrFail($userId);
        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        try {
            app(CartService::class)->addItem($user, (int) $courseId);

            return ['result' => 'ok'];
        } catch (DomainException $e) {
            return ['result' => 'domain', 'code' => $e->code()];
        } catch (Throwable $e) {
            return ['result' => 'error', 'message' => $e::class.': '.$e->getMessage()];
        }
    })(),
};

echo json_encode($out);
