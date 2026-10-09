<?php

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ManualOrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../T38/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
    $this->student = vvMoStudent();
});

function vvQaCartPending(): array
{
    return test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk()->json('pending_order') ?? [];
}

function vvQaPlaceManual(): string
{
    return vvMoPost(100000, ['payment_method' => 'manual'])->assertCreated()->json('order_code');
}

test('AC1: sau POST /checkout manual, GET /cart co pending_order dung ma, +07:00', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);
    expect(vvQaCartPending())->toBe([]);

    $code = vvQaPlaceManual();
    $p = vvQaCartPending();

    expect($p['code'])->toBe($code)->and($p['payment_method'])->toBe('manual')->and($p['total'])->toBe(100000)
        ->and($p['created_at'])->toEndWith('+07:00')->and($p['expires_at'])->toEndWith('+07:00')
        ->and(array_keys($p))->toBe(['code', 'payment_method', 'total', 'created_at', 'expires_at']);
});

test('AC2: APP_TIMEZONE UTC van tra +07:00 va cung thoi diem', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    $o = vvMoOrder($this->student, null, ['expires_at' => now()->addHours(72)]);

    $p = vvQaCartPending();

    expect($p['expires_at'])->toEndWith('+07:00')
        ->and(Carbon\Carbon::parse($p['expires_at'])->getTimestamp())->toBe($o->expires_at->getTimestamp());
});

test('AC3: hoc sinh tu huy -> null', function () {
    vvMoCart($this->student, [vvMoCourse()]);
    $code = vvQaPlaceManual();
    test()->postJson(vvApiUrl("/orders/{$code}/cancel"), [], vvWebHeaders())->assertOk();

    expect(vvQaCartPending())->toBe([]);
});

test('AC4: QTV duyet -> null', function () {
    $order = vvMoOrder($this->student);
    expect(vvQaCartPending()['code'])->toBe($order->code);

    app(ManualOrderService::class)->approve($order, User::factory()->admin()->create(), false, null, 'ok');

    expect(vvQaCartPending())->toBe([]);
});

test('AC5: QTV huy -> null', function () {
    $order = vvMoOrder($this->student);

    app(ManualOrderService::class)->cancelByStaff($order, User::factory()->admin()->create(), 'Het cho', null);

    expect(vvQaCartPending())->toBe([]);
});

test('AC6: het han qua orders:expire-manual -> null', function () {
    vvMoOrder($this->student, null, [], 'expiredManual');
    expect(vvQaCartPending())->not->toBe([]);

    Artisan::call('orders:expire-manual');

    expect(vvQaCartPending())->toBe([]);
});

test('AC7: replace_pending -> pending_order la don moi', function () {
    $cart = vvMoCart($this->student, [vvMoCourse(100000)]);
    $first = vvQaPlaceManual();
    vvMoAddToCart($cart, vvMoCourse(50000));

    $second = vvMoPost(150000, ['replace_pending' => true])->assertCreated()->json('order_code');
    $p = vvQaCartPending();

    expect($second)->not->toBe($first)->and($p['code'])->toBe($second)->and($p['total'])->toBe(150000)
        ->and(Order::where('code', $first)->first()->status->value)->toBe('cancelled');
});

test('AC8a: khach chua dang nhap -> 401', function () {
    $this->refreshApplication();
    test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertUnauthorized();
});

test('AC8b: giao vien bi chan nhu cu', function () {
    vvActAsStudent(User::factory()->teacher()->create());
    test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertForbidden();
});

test('AC9: route ghi gio khong co khoa pending_order du dang co don pending', function () {
    vvMoOrder($this->student);
    $course = vvMoCourse(100000);
    $h = vvWebHeaders();

    $add = test()->postJson(vvApiUrl('/cart/items'), ['course_id' => $course->id], $h);
    $add->assertSuccessful();
    expect($add->json())->not->toHaveKey('pending_order');

    expect(test()->deleteJson(vvApiUrl("/cart/items/{$course->id}"), [], $h)->assertOk()->json())->not->toHaveKey('pending_order');
    expect(test()->deleteJson(vvApiUrl('/cart/coupon'), [], $h)->assertOk()->json())->not->toHaveKey('pending_order');
    expect(test()->putJson(vvApiUrl('/cart/coupon'), ['code' => 'KHONGCO123'], $h)->json() ?? [])->not->toHaveKey('pending_order');
});

test('AC10: preview va gio cung gia tri pending_order', function () {
    vvMoCart($this->student, [vvMoCourse()]);
    vvQaPlaceManual();

    $cart = test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->json('pending_order');
    $prev = vvMoPreview()->assertOk()->json('pending_order');

    expect($cart)->not->toBeNull()->and($cart)->toBe($prev);
});
