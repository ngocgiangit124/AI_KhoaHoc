<?php

use App\Models\Cart;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T04/helpers.php';

beforeEach(function () {
    config(['payments.enabled_gateways' => ['fake'], 'features.paid_checkout' => true]);
    $this->student = vvActAsStudent(User::factory()->student()->verified()->create());
});

function vvCpGet()
{
    return test()->getJson(vvApiUrl('/cart'), vvWebHeaders());
}

test('khong co don pending: pending_order = null', function () {
    vvCpGet()->assertOk()->assertJsonPath('pending_order', null)->assertJsonStructure(['items', 'coupon', 'pricing', 'notices', 'pending_order']);
});

test('don cancelled khong tinh la pending', function () {
    Order::factory()->manual()->cancelled('user_cancelled')->create(['user_id' => $this->student->id]);

    vvCpGet()->assertOk()->assertJsonPath('pending_order', null);
});

test('co don manual pending: tra du shape, gio +07:00', function () {
    $o = Order::factory()->manual()->create(['user_id' => $this->student->id, 'expires_at' => now()->addDays(3)]);

    $res = vvCpGet()->assertOk();
    $res->assertJsonPath('pending_order.code', $o->code)
        ->assertJsonPath('pending_order.payment_method', 'manual')
        ->assertJsonPath('pending_order.total', $o->total_amount);
    expect(array_keys($res->json('pending_order')))->toBe(['code', 'payment_method', 'total', 'created_at', 'expires_at']);
    expect($res->json('pending_order.created_at'))->toEndWith('+07:00')
        ->and($res->json('pending_order.expires_at'))->toEndWith('+07:00');
});

test('co don MoMo pending cung hien', function () {
    $o = Order::factory()->create(['user_id' => $this->student->id]);

    vvCpGet()->assertOk()
        ->assertJsonPath('pending_order.code', $o->code)
        ->assertJsonPath('pending_order.payment_method', $o->payment_method);
});

test('don cua nguoi khac khong lo', function () {
    Order::factory()->manual()->create();
    Order::factory()->create();

    vvCpGet()->assertOk()->assertJsonPath('pending_order', null);
});

test('don manual qua han nhung job chua chay: van hien nhu preview', function () {
    $o = Order::factory()->manual()->create(['user_id' => $this->student->id, 'expires_at' => now()->subHours(2)]);

    vvCpGet()->assertOk()->assertJsonPath('pending_order.code', $o->code);
});

test('shape pending_order khop checkout preview', function () {
    Order::factory()->manual()->create(['user_id' => $this->student->id]);
    $course = Course::factory()->published()->paid(100000)->create();
    Cart::factory()->state(['user_id' => $this->student->id])->withCourses([$course])->create();

    $cart = vvCpGet()->assertOk()->json('pending_order');
    $preview = test()->getJson(vvApiUrl('/checkout/preview'), vvWebHeaders())->assertOk()->json('pending_order');

    expect($cart)->not->toBeNull()->and($cart)->toBe($preview);
});

test('them pending_order chi them dung 1 truy van vao orders', function () {
    Order::factory()->manual()->create(['user_id' => $this->student->id]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    vvCpGet()->assertOk();
    $orderQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `orders`'))->count();
    DB::disableQueryLog();

    expect($orderQueries)->toBe(1);
});
