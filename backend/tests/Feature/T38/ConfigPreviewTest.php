<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Services\Orders\PaymentMethods;

require_once __DIR__.'/helpers.php';

function vvMoPublicConfig()
{
    return test()->getJson('http://'.config('app.api_host').'/api/v1/config/public')->assertOk();
}

test('(a) chỉ manual bật: payment_methods=[manual], paid_checkout_enabled=true, kênh chưa cấu hình là null', function () {
    vvMoConfig(['orders.manual.contact' => ['phone' => null, 'zalo_url' => null, 'email' => 'hotro@example.com', 'hours' => null]]);

    $r = vvMoPublicConfig();
    $r->assertJsonPath('payment_methods', ['manual'])->assertJsonPath('paid_checkout_enabled', true);
    expect($r->json('manual_payment'))->toBe([
        'label' => 'Liên hệ Quản trị viên',
        'description' => 'Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn',
        'pending_ttl_hours' => 72,
        'contact' => ['phone' => null, 'zalo_url' => null, 'email' => 'hotro@example.com', 'hours' => null],
    ]);
});

test('(a) tắt cả 2 cờ: payment_methods=[], paid_checkout_enabled=false, manual_payment=null; bật cả 2 (cổng fake): [manual, fake]', function () {
    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => false]);
    vvMoPublicConfig()->assertJsonPath('payment_methods', [])->assertJsonPath('paid_checkout_enabled', false)->assertJsonPath('manual_payment', null);

    vvMoConfig(['features.manual_payment' => true, 'features.paid_checkout' => true]);
    vvMoPublicConfig()->assertJsonPath('payment_methods', ['manual', 'fake'])->assertJsonPath('paid_checkout_enabled', true);

    // MoMo bật, manual tắt: chỉ cổng (AC30: bật lại MoMo không đụng luồng thủ công)
    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => true]);
    vvMoPublicConfig()->assertJsonPath('payment_methods', ['fake'])->assertJsonPath('manual_payment', null);
});

test('(a) /config/public không lộ khoá nội bộ (notify_emails, per_day, approval_window)', function () {
    vvMoConfig();
    $body = json_encode(vvMoPublicConfig()->json(), JSON_UNESCAPED_UNICODE);

    expect($body)->not->toContain('ops@example.com')->not->toContain('notify')->not->toContain('per_day')->not->toContain('approval');
});

test('PaymentMethods: thứ tự manual trước, default, isAvailable không phân biệt hoa thường', function () {
    vvMoConfig(['features.paid_checkout' => true]);
    $m = app(PaymentMethods::class);

    expect($m->available())->toBe(['manual', 'fake'])->and($m->default())->toBe('manual')
        ->and($m->isAvailable('MANUAL'))->toBeTrue()->and($m->isAvailable('momo'))->toBeFalse();

    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => false]);
    expect($m->available())->toBe([])->and($m->default())->toBeNull();
});

test('(b) preview: payment_methods kèm nhãn, default, can_checkout, pending_order null khi chưa có đơn', function () {
    vvMoConfig();
    $user = vvMoStudent();
    vvMoCart($user, [vvMoCourse(100000)]);

    $r = vvMoPreview()->assertOk();
    $r->assertJsonPath('can_checkout', true)->assertJsonPath('requires_payment', true)->assertJsonPath('default_payment_method', 'manual')->assertJsonPath('pending_order', null);
    expect($r->json('payment_methods'))->toBe([[
        'code' => 'manual', 'label' => 'Liên hệ Quản trị viên', 'description' => 'Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn',
    ]]);
});

test('(b) preview: pending_order phản ánh đơn chờ hiện có (mọi phương thức), thời gian +07:00', function () {
    vvMoConfig();
    $user = vvMoStudent();
    vvMoCart($user, [vvMoCourse(100000)]);
    vvMoPost(100000)->assertCreated();
    $order = Order::firstOrFail();

    $r = vvMoPreview()->assertOk();
    expect($r->json('pending_order.code'))->toBe($order->code)->and($r->json('pending_order.payment_method'))->toBe('manual')
        ->and($r->json('pending_order.total'))->toBe(100000)->and($r->json('pending_order.expires_at'))->toEndWith('+07:00');
});

test('(b) preview: tắt hết phương thức + cần trả tiền -> notice PAYMENT_DISABLED, can_checkout=false; đơn 0đ vẫn can_checkout', function () {
    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => false]);
    $user = vvMoStudent();
    $cart = vvMoCart($user, [vvMoCourse(100000)]);

    $r = vvMoPreview()->assertOk();
    $r->assertJsonPath('can_checkout', false)->assertJsonPath('payment_methods', [])->assertJsonPath('default_payment_method', null);
    expect(collect($r->json('notices'))->pluck('code')->all())->toContain('PAYMENT_DISABLED');

    $free = Coupon::factory()->percent(100)->create(['max_uses' => 5, 'valid_until' => now()->addDay()]);
    $cart->forceFill(['coupon_id' => $free->id])->save();
    vvMoPreview()->assertJsonPath('can_checkout', true)->assertJsonPath('requires_payment', false);
});

test('(b) CHECKOUT_CHANGED vẫn trả preview đầy đủ khoá mới', function () {
    vvMoConfig();
    $user = vvMoStudent();
    vvMoCart($user, [vvMoCourse(100000)]);

    $r = vvMoPost(1)->assertStatus(409);
    expect($r->json('errors.preview'))->toHaveKeys(['payment_methods', 'default_payment_method', 'pending_order']);
});
