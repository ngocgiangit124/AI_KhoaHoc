<?php

use App\Mail\ManualOrderCancelledMail;
use App\Mail\ManualOrderReceivedMail;
use App\Mail\NewManualOrderStaffMail;
use App\Models\Coupon;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Orders\CouponCapacity;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
    $this->student = vvMoStudent();
});

test('(c) checkout manual: 201, hạn 72 giờ, giữ chỗ mã tới hạn đơn, ghi chú chuẩn hoá, không attempt/enrollment, giỏ nguyên, 2 thư', function () {
    $coupon = Coupon::factory()->percent(10)->create(['max_uses' => 5, 'valid_until' => now()->addDays(10)]);
    $course = vvMoCourse(100000);
    $cart = vvMoCart($this->student, [$course], $coupon);

    $r = vvMoPost(90000, ['payment_method' => 'manual', 'customer_note' => "  Gọi sau 18h giúp em\r\nCảm ơn  "])->assertCreated();

    $r->assertJsonPath('status', 'pending')->assertJsonPath('payment_method', 'manual')->assertJsonPath('total', 90000)
        ->assertJsonPath('reused', false)->assertJsonPath('payment', null)->assertJsonPath('link_expired', false);
    expect($r->json('expires_at'))->toEndWith('+07:00');

    $order = Order::firstOrFail();
    expect($order->payment_method)->toBe('manual')
        ->and($order->expires_at->equalTo(now()->addHours(72)->startOfSecond()))->toBeTrue()
        ->and($order->coupon_hold_until->equalTo($order->expires_at))->toBeTrue()
        ->and($order->customer_note)->toBe("Gọi sau 18h giúp em\nCảm ơn")
        ->and(PaymentAttempt::count())->toBe(0)
        ->and(Enrollment::count())->toBe(0)
        ->and($cart->items()->count())->toBe(1)
        ->and($cart->fresh()->coupon_id)->toBe($coupon->id);

    Mail::assertQueued(ManualOrderReceivedMail::class, 1);
    Mail::assertQueued(ManualOrderReceivedMail::class, fn ($m) => $m->hasTo($this->student->email) && $m->orderCode === $order->code);
    Mail::assertQueued(NewManualOrderStaffMail::class, 1);
    Mail::assertQueued(NewManualOrderStaffMail::class, fn ($m) => $m->hasTo('ops@example.com'));
});

test('(c) thư hộp thư quản trị không chứa PII (tên, email, SĐT, ghi chú học sinh); thư học sinh dẫn tới trang đơn', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);
    vvMoPost(100000, ['payment_method' => 'manual', 'customer_note' => 'GHICHUBIMAT-xyz'])->assertCreated();
    $code = Order::firstOrFail()->code;

    Mail::assertQueued(NewManualOrderStaffMail::class, function (NewManualOrderStaffMail $m) use ($code) {
        $html = $m->render();
        foreach ([$this->student->name, $this->student->email, $this->student->phone, 'GHICHUBIMAT-xyz'] as $pii) {
            expect($html)->not->toContain((string) $pii);
        }
        expect($html)->toContain($code)->toContain('/quan-tri/don-hang/'.$code);

        return true;
    });

    Mail::assertQueued(ManualOrderReceivedMail::class, function (ManualOrderReceivedMail $m) use ($code) {
        expect($m->render())->toContain('/thanh-toan/da-gui/'.$code)->toContain('0901 234 567');

        return true;
    });
});

test('(c) thư là ShouldQueue + ShouldBeEncrypted + afterCommit', function (string $class) {
    $ref = new ReflectionClass($class);
    expect($ref->implementsInterface(ShouldQueue::class))->toBeTrue()
        ->and($ref->implementsInterface(ShouldBeEncrypted::class))->toBeTrue();
})->with([ManualOrderReceivedMail::class, NewManualOrderStaffMail::class, ManualOrderCancelledMail::class]);

test('(c) BR11: tổng 500đ và 60.000.000đ đều tạo được đơn manual (không áp min/max cổng)', function (int $price) {
    vvMoCart($this->student, [vvMoCourse($price)]);

    vvMoPost($price, ['payment_method' => 'manual'])->assertCreated()->assertJsonPath('total', $price);
})->with([500, 60000000]);

test('(c) không gửi payment_method: dùng mặc định manual; thiếu email xác thực thì không có thư học sinh nhưng vẫn có thư quản trị', function () {
    $user = vvMoStudent(['email' => null, 'email_verified_at' => null]);
    vvMoCart($user, [vvMoCourse()]);

    vvMoPost(100000)->assertCreated()->assertJsonPath('payment_method', 'manual');

    Mail::assertNotQueued(ManualOrderReceivedMail::class);
    Mail::assertQueued(NewManualOrderStaffMail::class, 1);
});

test('(d) gửi lại cùng giỏ: 200 reused, không đơn/thư mới, ghi chú mới bị bỏ qua', function () {
    vvMoCart($this->student, [vvMoCourse()]);
    $first = vvMoPost(100000, ['customer_note' => 'ghi chu 1'])->assertCreated();

    $again = vvMoPost(100000, ['customer_note' => 'ghi chu 2', 'payment_method' => 'manual'])->assertOk()->assertJsonPath('reused', true);

    expect($again->json('order_code'))->toBe($first->json('order_code'))
        ->and(Order::count())->toBe(1)
        ->and(Order::first()->customer_note)->toBe('ghi chu 1');
    Mail::assertQueued(ManualOrderReceivedMail::class, 1);
    Mail::assertQueued(NewManualOrderStaffMail::class, 1);
});

test('(e) đơn manual chờ khác nội dung: không replace_pending -> 409 PENDING_ORDER_EXISTS, DB không đổi', function () {
    $a = vvMoCourse(100000);
    $b = vvMoCourse(50000);
    $cart = vvMoCart($this->student, [$a]);
    $first = vvMoPost(100000)->assertCreated();

    vvMoAddToCart($cart, $b);

    $r = vvMoPost(150000)->assertStatus(409)->assertJsonPath('code', 'PENDING_ORDER_EXISTS');
    expect($r->json('errors'))->toHaveKeys(['order_code', 'payment_method', 'items_count', 'total', 'created_at', 'expires_at'])
        ->and($r->json('errors.order_code'))->toBe($first->json('order_code'))
        ->and($r->json('errors.total'))->toBe(100000)->and($r->json('errors.items_count'))->toBe(1)
        ->and($r->json('errors.expires_at'))->toEndWith('+07:00');

    expect(Order::count())->toBe(1)->and(Order::first()->status->value)->toBe('pending');
    Mail::assertQueued(ManualOrderReceivedMail::class, 1);
});

test('(e) replace_pending=true: đơn cũ cancelled/superseded, đơn mới 201, chỉ 1 đơn pending', function () {
    $cart = vvMoCart($this->student, [vvMoCourse(100000)]);
    $first = vvMoPost(100000)->assertCreated();
    vvMoAddToCart($cart, vvMoCourse(50000));

    $second = vvMoPost(150000, ['replace_pending' => true])->assertCreated();

    $old = Order::where('code', $first->json('order_code'))->firstOrFail();
    expect($old->status->value)->toBe('cancelled')->and($old->status_reason)->toBe('superseded')
        ->and(Order::where('code', $second->json('order_code'))->firstOrFail()->status->value)->toBe('pending')
        ->and(Order::where('status', 'pending')->count())->toBe(1);
    Mail::assertQueued(ManualOrderReceivedMail::class, 2);
});

test('(e) đơn chờ MoMo (fake) khác nội dung vẫn tự thay như cũ; đơn manual đã quá expires_at tự thay không hỏi', function () {
    vvMoConfig(['features.paid_checkout' => true]);
    $cart = vvMoCart($this->student, [vvMoCourse(100000)]);
    $momo = vvMoPost(100000, ['payment_method' => 'fake'])->assertCreated();
    vvMoAddToCart($cart, vvMoCourse(50000));

    $new = vvMoPost(150000, ['payment_method' => 'manual'])->assertCreated();
    expect(Order::where('code', $momo->json('order_code'))->first()->status_reason)->toBe('superseded')
        ->and($new->json('payment_method'))->toBe('manual');

    // đơn manual đã quá hạn (job chưa chạy) -> tự thay
    Order::where('code', $new->json('order_code'))->update(['expires_at' => now()->subMinute()]);
    vvMoAddToCart($cart, vvMoCourse(20000));
    vvMoPost(170000, ['payment_method' => 'manual'])->assertCreated();
    expect(Order::where('code', $new->json('order_code'))->first()->status_reason)->toBe('superseded');
});

test('(e) đơn manual chờ + đơn mới 0đ khác nội dung -> vẫn 409 PENDING_ORDER_EXISTS (không tự huỷ đơn có thể đã chuyển khoản)', function () {
    $course = vvMoCourse(100000);
    $cart = vvMoCart($this->student, [$course]);
    vvMoPost(100000)->assertCreated();

    $free = Coupon::factory()->percent(100)->create(['max_uses' => 5, 'valid_until' => now()->addDay()]);
    $cart->forceFill(['coupon_id' => $free->id])->save();

    vvMoPost(0)->assertStatus(409)->assertJsonPath('code', 'PENDING_ORDER_EXISTS');
    expect(Order::count())->toBe(1);
});

test('(f) giá/giỏ đổi -> 409 CHECKOUT_CHANGED; customer_note có <b>, ký tự bidi, 501 ký tự -> 422', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);
    vvMoPost(99999)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');

    foreach (['<b>x</b>', "abc\u{202E}def", str_repeat('a', 501), "tab\there"] as $bad) {
        vvMoPost(100000, ['customer_note' => $bad])->assertStatus(422)->assertJsonValidationErrors(['customer_note']);
    }
    expect(Order::count())->toBe(0);

    // rỗng sau trim -> null; chuỗi đúng 500 ký tự qua
    vvMoPost(100000, ['customer_note' => " \r\n "])->assertCreated();
    expect(Order::first()->customer_note)->toBeNull();
});

test('(g) hạn mức: đủ 5 đơn manual trong ngày VN -> đơn mới 429 MANUAL_ORDER_LIMIT + Retry-After + resets_at 00:00 VN; qua nửa đêm VN lại tạo được', function () {
    $this->travelTo(vvMoVn('2026-10-08 10:00:00'));
    vvMoCart($this->student, [vvMoCourse(100000)]);
    for ($i = 0; $i < 5; $i++) {
        vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now(), 'created_at' => vvMoVn('2026-10-08 00:00:30')->addMinutes($i)]);
    }

    $r = vvMoPost(100000)->assertStatus(429)->assertJsonPath('code', 'MANUAL_ORDER_LIMIT');
    expect($r->json('errors.limit'))->toBe(5)
        ->and($r->json('errors.resets_at'))->toBe('2026-10-09T00:00:00+07:00')
        ->and((int) $r->headers->get('Retry-After'))->toBe(14 * 3600);
    expect(Order::where('status', 'pending')->count())->toBe(0);

    $this->travelTo(vvMoVn('2026-10-09 00:00:01'));
    vvMoPost(100000)->assertCreated();
});

test('(g) đơn dùng lại không tính vào hạn mức; đơn manual cũ chiếm chỗ không bị huỷ khi vượt hạn mức', function () {
    $this->travelTo(vvMoVn('2026-10-08 10:00:00'));
    $cart = vvMoCart($this->student, [vvMoCourse(100000)]);
    for ($i = 0; $i < 4; $i++) {
        vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now()]);
    }

    vvMoPost(100000)->assertCreated(); // đơn thứ 5
    vvMoPost(100000)->assertOk()->assertJsonPath('reused', true); // dùng lại, không bị 429

    // đổi nội dung + replace_pending khi đã đủ hạn mức -> 429 và đơn chờ cũ KHÔNG bị huỷ
    vvMoAddToCart($cart, vvMoCourse(30000));
    vvMoPost(130000, ['replace_pending' => true])->assertStatus(429);
    expect(Order::where('status', 'pending')->count())->toBe(1)->and(Order::where('status_reason', 'superseded')->count())->toBe(0);
});

test('(h) payment_method lạ -> 422; gateway khác payment_method -> 422; manual tắt + MoMo bật: mặc định cổng, manual -> 422', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);

    vvMoPost(100000, ['payment_method' => 'paypal'])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    vvMoPost(100000, ['payment_method' => 'manual', 'gateway' => 'fake'])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    expect(Order::count())->toBe(0);

    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => true]);
    vvMoPost(100000, ['payment_method' => 'manual'])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    vvMoPost(100000)->assertCreated()->assertJsonPath('payment_method', 'fake')->assertJsonPath('payment.gateway', 'fake');
});

test('(h) cả 2 phương thức tắt -> 503 PAYMENT_DISABLED (AC29), không tạo đơn; đơn 0đ không ảnh hưởng', function () {
    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => false]);
    vvMoCart($this->student, [vvMoCourse(100000)]);

    vvMoPost(100000)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_DISABLED');
    vvMoPost(100000, ['payment_method' => 'manual'])->assertStatus(503);
    expect(Order::count())->toBe(0);
});

test('(c) giữ chỗ mã suốt thời gian chờ: sau 31 phút (khác MoMo) và 71 giờ đơn manual vẫn giữ lượt mã; sau 73 giờ hết giữ', function () {
    $coupon = Coupon::factory()->percent(10)->create(['max_uses' => 1, 'valid_until' => now()->addDays(10)]);
    vvMoCart($this->student, [vvMoCourse(100000)], $coupon);
    vvMoPost(90000)->assertCreated();

    $capacity = app(CouponCapacity::class);
    expect($capacity->hasRoom($coupon->fresh()))->toBeFalse();
    $this->travel(31)->minutes();
    expect($capacity->hasRoom($coupon->fresh()))->toBeFalse();
    $this->travelTo(now()->addHours(71));
    expect($capacity->hasRoom($coupon->fresh()))->toBeFalse();
    $this->travelTo(now()->addHours(2));
    expect($capacity->hasRoom($coupon->fresh()))->toBeTrue();
});

test('đơn manual của người khác không bị ảnh hưởng: HS khác đặt đơn riêng, mỗi người 1 đơn pending', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);
    vvMoPost(100000)->assertCreated();

    $other = vvMoStudent();
    vvMoCart($other, [vvMoCourse(100000)]);
    vvMoPost(100000, ['replace_pending' => true])->assertCreated();

    expect(Order::where('status', 'pending')->count())->toBe(2)
        ->and(Order::where('user_id', $this->student->id)->first()->status->value)->toBe('pending');
});

test('đơn 0đ không bị đổi: payment_method none, status paid, response có payment_method/expires_at', function () {
    $coupon = Coupon::factory()->percent(100)->create(['max_uses' => 5, 'valid_until' => now()->addDay()]);
    vvMoCart($this->student, [vvMoCourse(100000)], $coupon);

    $r = vvMoPost(0)->assertCreated()->assertJsonPath('status', 'paid')->assertJsonPath('payment_method', 'none')->assertJsonPath('payment', null);
    expect($r->json('expires_at'))->not->toBeNull();
    Mail::assertNotQueued(ManualOrderReceivedMail::class);
    Mail::assertNotQueued(NewManualOrderStaffMail::class);
});

test('(h) R2: manual bật + MoMo tắt + payment_method=fake -> 422 errors.payment_method, không tạo đơn', function () {
    vvMoConfig(['features.manual_payment' => true, 'features.paid_checkout' => false]);
    vvMoCart($this->student, [vvMoCourse(100000)]);

    vvMoPost(100000, ['payment_method' => 'fake'])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    expect(Order::count())->toBe(0)->and(PaymentAttempt::count())->toBe(0);
});

test('(h) R2: manual tắt + MoMo bật (PAYMENT_GATEWAYS=fake) + không gửi method -> đơn MoMo như cũ', function () {
    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => true]);
    vvMoCart($this->student, [vvMoCourse(100000)]);

    $r = vvMoPost(100000)->assertCreated();
    expect($r->json('payment_method'))->toBe('fake')->and($r->json('payment.gateway'))->toBe('fake')
        ->and(Order::first()->payment_method)->toBe('fake')->and(PaymentAttempt::count())->toBe(1);
    Mail::assertNothingQueued();
});

test('R4: gateway=manual (bí danh cũ) luôn 422 ở khoá gateway, dù PAYMENT_GATEWAYS rỗng hay không', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);

    foreach ([['fake'], []] as $gateways) {
        vvMoConfig(['payments.enabled_gateways' => $gateways]);
        vvMoPost(100000, ['gateway' => 'manual'])->assertStatus(422)->assertJsonValidationErrors(['gateway']);
    }
    expect(Order::count())->toBe(0);
});
