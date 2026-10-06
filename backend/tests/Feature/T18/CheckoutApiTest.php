<?php

use App\Enums\EnrollmentStatus;
use App\Enums\ParentConsentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Gateways\Fake\FakeGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T04/helpers.php';

function vvCoPreview()
{
    return test()->getJson(vvApiUrl('/checkout/preview'), vvWebHeaders());
}

function vvCoPost(int $expected, array $extra = [])
{
    return test()->postJson(vvApiUrl('/checkout'), array_merge(['expected_total' => $expected, 'gateway' => 'fake'], $extra), vvWebHeaders());
}

function vvCoCart(User $user, array $courses, ?Coupon $coupon = null): Cart
{
    $f = Cart::factory()->state(['user_id' => $user->id])->withCourses($courses);
    if ($coupon !== null) {
        $f = $f->withCoupon($coupon);
    }

    return $f->create();
}

function vvCoCourse(int $price = 100000): Course
{
    return Course::factory()->published()->paid($price)->create();
}

class VvFailingGateway extends FakeGateway
{
    public static bool $fail = true;

    public function createPayment(PaymentRequest $request): PaymentInitResult
    {
        if (self::$fail) {
            throw new GatewayUnavailableException('timeout');
        }

        return parent::createPayment($request);
    }
}

class VvFlakyFulfillment extends OrderFulfillmentService
{
    public static bool $fail = true;

    public function markPaid(Order $order, string $source, ?string $paymentReference = null): Order
    {
        if (self::$fail) {
            throw new RuntimeException('boom');
        }

        return parent::markPaid($order, $source, $paymentReference);
    }
}

beforeEach(function () {
    config(['payments.enabled_gateways' => ['fake'], 'features.paid_checkout' => true]);
    $this->student = vvActAsStudent(User::factory()->student()->verified()->create());
});

test('xac thuc: khach 401, chua xac thuc OTP 403, phu huynh chua dong y 403, giao vien 403', function () {
    config(['features.parent_consent_enforced' => true]);
    auth()->logout();
    $this->app['auth']->forgetGuards();
    test()->flushSession();
    $this->getJson(vvApiUrl('/checkout/preview'), vvWebHeaders())->assertUnauthorized();

    $unverified = vvActAsStudent(User::factory()->student()->create());
    vvCoPreview()->assertForbidden()->assertJsonPath('code', 'ACCOUNT_NOT_VERIFIED');
    vvCoPost(0)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_NOT_VERIFIED');

    vvActAsStudent(User::factory()->student()->verified()->create(['parent_consent_status' => ParentConsentStatus::Pending]));
    vvCoPost(0)->assertForbidden()->assertJsonPath('code', 'PARENT_CONSENT_REQUIRED');

    vvActAsStudent(User::factory()->student()->verified()->create(['parent_consent_status' => ParentConsentStatus::Granted]));
    vvCoPreview()->assertOk();

    vvActAsStudent(User::factory()->teacher()->create());
    vvCoPreview()->assertForbidden();
    expect(Order::count())->toBe(0);
});

test('AC3: gio trong -> preview rong, POST /checkout 422 CART_EMPTY', function () {
    vvCoPreview()->assertOk()->assertJsonPath('items', [])->assertJsonPath('can_checkout', false);
    vvCoPost(0)->assertStatus(422)->assertJsonPath('code', 'CART_EMPTY');
    expect(Order::count())->toBe(0);
});

test('preview: items hop le + removed_items (khoa ngung ban), pricing khong tinh khoa bi loai', function () {
    $a = vvCoCourse(100000);
    $b = Course::factory()->unpublished()->paid(50000)->create();
    vvCoCart($this->student, [$a, $b]);

    $r = vvCoPreview()->assertOk();
    expect($r->json('items'))->toHaveCount(1)->and($r->json('removed_items'))->toHaveCount(1)
        ->and($r->json('pricing.total'))->toBe(100000)
        ->and($r->json('requires_payment'))->toBeTrue();
    expect($r->json('notices.0.code'))->toBe('ITEMS_UNAVAILABLE');
});

test('AC1: checkout 2 khoa -> 201, don pending, items chot gia, attempt pending co pay_url, gio con nguyen', function () {
    $a = vvCoCourse(300000);
    $b = vvCoCourse(200000);
    vvCoCart($this->student, [$a, $b]);

    $r = vvCoPost(500000)->assertCreated()
        ->assertJsonPath('status', 'pending')->assertJsonPath('total', 500000)->assertJsonPath('reused', false)
        ->assertJsonPath('payment.gateway', 'fake')->assertJsonMissingPath('data');
    expect($r->json('payment.pay_url'))->toStartWith('https://fake-pay.vitaminvui.test/pay/'.$r->json('order_code'));

    $order = Order::with('items')->firstOrFail();
    expect($order->code)->toMatch('/^VV\d{6}[0-9A-HJKMNP-TV-Z]{6}$/')
        ->and($order->user_id)->toBe($this->student->id)
        ->and($order->subtotal_amount)->toBe(500000)->and($order->discount_amount)->toBe(0)->and($order->total_amount)->toBe(500000)
        ->and($order->expires_at->between(now()->addHours(12)->subMinute(), now()->addHours(12)->addMinute()))->toBeTrue()
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->sum('final_amount'))->toBe(500000);

    $attempt = PaymentAttempt::firstOrFail();
    expect($attempt->status->value)->toBe('pending')->and($attempt->amount)->toBe(500000)
        ->and($attempt->gateway_order_id)->toBe($order->code.'-1')->and($attempt->next_check_at)->not->toBeNull();

    expect(DB::table('order_status_logs')->where('order_id', $order->id)->count())->toBe(1)
        ->and(Enrollment::count())->toBe(0)
        ->and(CartItem::count())->toBe(2); // BR6: chua thanh toan thi gio nguyen
});

test('AC9: ap ma -> discount/coupon luu dung, order_items phan bo giam, coupon_hold_until dat', function () {
    $a = vvCoCourse(100000);
    $b = vvCoCourse(50000);
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'GIAM10AB']);
    vvCoCart($this->student, [$a, $b], $coupon);

    vvCoPost(135000)->assertCreated()->assertJsonPath('total', 135000);

    $order = Order::with('items')->firstOrFail();
    expect($order->coupon_id)->toBe($coupon->id)->and($order->coupon_code)->toBe('GIAM10AB')
        ->and($order->discount_amount)->toBe(15000)->and($order->total_amount)->toBe(135000)
        ->and($order->items->sum('discount_amount'))->toBe(15000)
        ->and($order->coupon_hold_until)->not->toBeNull()
        ->and($order->coupon_hold_until->lte(now()->addMinutes(30)->addSecond()))->toBeTrue();
    // Chua paid: khong ghi luot dung (chi T19/markPaid)
    expect($coupon->fresh()->used_count)->toBe(0)->and(DB::table('coupon_usages')->count())->toBe(0);
});

test('expected_total lech -> 409 CHECKOUT_CHANGED kem preview moi, khong tao don', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);

    $r = vvCoPost(90000)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect($r->json('errors.preview.pricing.total'))->toBe(100000)->and($r->json('errors.reasons'))->toBe(['PRICE_CHANGED']);
    expect(Order::count())->toBe(0);
});

test('gia doi giua luc them vao gio va luc thanh toan -> dung gia hien tai, chot vao order_items', function () {
    $c = vvCoCourse(100000);
    vvCoCart($this->student, [$c]);
    $c->forceFill(['price' => 120000])->save();

    vvCoPost(100000)->assertStatus(409);
    vvCoPost(120000)->assertCreated();
    expect(Order::first()->items->first()->unit_price)->toBe(120000);

    $c->forceFill(['price' => 999000])->save(); // doi gia sau khi chot khong anh huong don
    expect(Order::first()->items->first()->unit_price)->toBe(120000);
});

test('AC4: khoa bi go khoi gio luc checkout -> bi loai khoi don, thanh toan phan con lai', function () {
    $a = vvCoCourse(100000);
    $b = Course::factory()->unpublished()->paid(70000)->create();
    vvCoCart($this->student, [$a, $b]);

    vvCoPost(100000)->assertCreated();
    expect(Order::first()->items->pluck('course_id')->all())->toBe([$a->id]);
});

test('gio chi con khoa khong hop le -> CART_EMPTY', function () {
    vvCoCart($this->student, [Course::factory()->unpublished()->paid(70000)->create()]);
    vvCoPost(0)->assertStatus(422)->assertJsonPath('code', 'CART_EMPTY');
});

test('ma het han giua luc xem va thanh toan -> go ma, 409 CHECKOUT_CHANGED, gio khong con ma', function () {
    $coupon = Coupon::factory()->percent(50)->create(['code' => 'HETHAN01']);
    $cart = vvCoCart($this->student, [vvCoCourse(100000)], $coupon);
    $coupon->forceFill(['valid_until' => now()->subMinute()])->save();

    $r = vvCoPost(50000)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect($r->json('errors.preview.pricing.total'))->toBe(100000)->and($cart->fresh()->coupon_id)->toBeNull()
        ->and(Order::count())->toBe(0);
});

test('dung lai don pending cung noi dung (200, reused, khong tao don/attempt moi)', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    $first = vvCoPost(100000)->assertCreated();

    $second = vvCoPost(100000)->assertOk()->assertJsonPath('reused', true);
    expect($second->json('order_code'))->toBe($first->json('order_code'))
        ->and($second->json('payment.pay_url'))->toBe($first->json('payment.pay_url'))
        ->and(Order::count())->toBe(1)->and(PaymentAttempt::count())->toBe(1);
});

test('noi dung gio doi -> don cu bi huy (superseded), don moi tao, chi 1 don pending', function () {
    $a = vvCoCourse(100000);
    $b = vvCoCourse(50000);
    vvCoCart($this->student, [$a]);
    $first = vvCoPost(100000)->assertCreated()->json('order_code');

    CartItem::query()->insert(['cart_id' => Cart::first()->id, 'course_id' => $b->id, 'created_at' => now()]);
    $second = vvCoPost(150000)->assertCreated()->json('order_code');

    expect($second)->not->toBe($first);
    $old = Order::where('code', $first)->first();
    expect($old->status->value)->toBe('cancelled')->and($old->status_reason)->toBe('superseded')->and($old->cancelled_at)->not->toBeNull()
        ->and(Order::where('status', 'pending')->count())->toBe(1)
        ->and(DB::table('order_status_logs')->where('order_id', $old->id)->count())->toBe(2);
});

test('don pending het han (qua expires_at) bi thay boi don moi', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    $first = vvCoPost(100000)->assertCreated()->json('order_code');
    Order::query()->update(['expires_at' => now()->subMinute()]);

    $second = vvCoPost(100000)->assertCreated()->json('order_code');
    expect($second)->not->toBe($first)->and(Order::where('code', $first)->first()->status->value)->toBe('cancelled');
});

test('AC6: cong loi -> 502 PAYMENT_GATEWAY_UNAVAILABLE, don van pending, attempt error, khong enrollment; thu lai tao attempt moi', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    VvFailingGateway::$fail = true;
    app(PaymentGatewayManager::class)->extend('fake', fn () => new VvFailingGateway);

    $r = vvCoPost(100000)->assertStatus(502)->assertJsonPath('code', 'PAYMENT_GATEWAY_UNAVAILABLE');
    $order = Order::firstOrFail();
    expect($r->json('errors.order_code'))->toBe($order->code)->and($order->status->value)->toBe('pending')
        ->and(PaymentAttempt::first()->status->value)->toBe('error')->and(Enrollment::count())->toBe(0);

    // Thu lai (cong da on): dung lai don, tao attempt thu 2 voi gateway_order_id moi
    VvFailingGateway::$fail = false;
    vvCoPost(100000)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('payment.gateway', 'fake');
    expect(PaymentAttempt::count())->toBe(2)->and(PaymentAttempt::orderBy('id')->get()->pluck('gateway_order_id')->all())->toBe([$order->code.'-1', $order->code.'-2']);
});

test('attempt created dang bay (<45s) -> 409 PAYMENT_IN_PROGRESS; qua 45s -> danh dau error va tao moi', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    vvCoPost(100000)->assertCreated();
    PaymentAttempt::query()->update(['status' => 'created', 'pay_url' => null]);

    vvCoPost(100000)->assertStatus(409)->assertJsonPath('code', 'PAYMENT_IN_PROGRESS');

    PaymentAttempt::query()->update(['created_at' => now()->subMinutes(2)]);
    vvCoPost(100000)->assertOk()->assertJsonPath('payment.gateway', 'fake');
    expect(PaymentAttempt::orderBy('id')->first()->status->value)->toBe('error')->and(PaymentAttempt::count())->toBe(2);
});

test('link cu het han chua duoc cong xac nhan -> khong tao link moi, link_expired=true, payment null', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    vvCoPost(100000)->assertCreated();
    PaymentAttempt::query()->update(['expires_at' => now()->subMinute()]);

    vvCoPost(100000)->assertOk()->assertJsonPath('link_expired', true)->assertJsonPath('payment', null);
    expect(PaymentAttempt::count())->toBe(1);
});

test('so tien duoi muc toi thieu cua cong -> 422 AMOUNT_BELOW_GATEWAY_MIN, khong tao don', function () {
    config(['payments.gateways.fake.min_amount' => 1000]);
    $c = vvCoCourse(1500);
    $coupon = Coupon::factory()->fixed(1000)->create(['code' => 'FIX1000A']);
    vvCoCart($this->student, [$c], $coupon);

    vvCoPost(500)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_BELOW_GATEWAY_MIN');
    expect(Order::count())->toBe(0);
});

test('gateway ngoai allowlist -> 422 VALIDATION_ERROR', function () {
    vvCoCart($this->student, [vvCoCourse()]);
    vvCoPost(100000, ['gateway' => 'vnpay'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    vvCoPost(100000, ['gateway' => 'momo'])->assertStatus(422);
    expect(Order::count())->toBe(0);
});

test('N-2: don 0d (ma 100% co gioi han) -> paid ngay, enrollment active, coupon_usages + used_count, gio duoc don, khong goi cong', function () {
    $a = vvCoCourse(100000);
    $b = vvCoCourse(50000);
    $other = vvCoCourse(30000);
    $coupon = Coupon::factory()->percent(100)->create(['code' => 'FREE100A', 'max_uses' => 5, 'valid_until' => now()->addDay()]);
    vvCoCart($this->student, [$a, $b, $other], $coupon);
    CartItem::query()->where('course_id', $other->id)->delete(); // gio chi con a, b

    vvCoPost(0)->assertCreated()->assertJsonPath('status', 'paid')->assertJsonPath('total', 0)->assertJsonPath('payment', null);

    $order = Order::firstOrFail();
    expect($order->status->value)->toBe('paid')->and($order->payment_method)->toBe('none')->and($order->status_reason)->toBe('zero_amount')
        ->and($order->paid_at)->not->toBeNull()->and($order->needs_review)->toBeFalse()
        ->and(PaymentAttempt::count())->toBe(0)
        ->and(Enrollment::where('user_id', $this->student->id)->where('status', EnrollmentStatus::Active->value)->where('order_id', $order->id)->count())->toBe(2)
        ->and($a->fresh()->enrollments_count)->toBe(1)
        ->and($coupon->fresh()->used_count)->toBe(1)
        ->and(DB::table('coupon_usages')->where('order_id', $order->id)->count())->toBe(1)
        ->and(CartItem::count())->toBe(0)->and(Cart::first()->coupon_id)->toBeNull();

    // Gui lai (IPN-like idempotent): khong cap them
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');
    expect(Enrollment::count())->toBe(2)->and($coupon->fresh()->used_count)->toBe(1);
});

test('don 0d khi tat flag zero_total_checkout -> 422 ZERO_TOTAL_DISABLED, khong tao don', function () {
    config(['features.zero_total_checkout' => false]);
    $coupon = Coupon::factory()->percent(100)->create(['code' => 'FREE100B', 'max_uses' => 5, 'valid_until' => now()->addDay()]);
    vvCoCart($this->student, [vvCoCourse()], $coupon);

    vvCoPost(0)->assertStatus(422)->assertJsonPath('code', 'ZERO_TOTAL_DISABLED');
    expect(Order::count())->toBe(0);
});

test('COUPON_ALREADY_USED voi bang coupon_usages that (go ma khi checkout)', function () {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'ONCE0001']);
    $c = vvCoCourse(100000);
    $cart = vvCoCart($this->student, [$c], $coupon);
    $old = Order::factory()->paid()->create(['user_id' => $this->student->id, 'coupon_id' => $coupon->id]);
    DB::table('coupon_usages')->insert(['coupon_id' => $coupon->id, 'user_id' => $this->student->id, 'order_id' => $old->id, 'used_at' => now()]);

    test()->putJson(vvApiUrl('/cart/coupon'), ['code' => 'ONCE0001'], vvWebHeaders())->assertStatus(422)->assertJsonPath('code', 'COUPON_ALREADY_USED');

    // Ma da gan san trong gio: checkout go ma + CHECKOUT_CHANGED
    $cart->forceFill(['coupon_id' => $coupon->id])->save();
    vvCoPost(90000)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect($cart->fresh()->coupon_id)->toBeNull();
});

test('suc chua ma: don pending con giu cho cua HS khac tinh vao max_uses; het giu cho thi nha cho', function () {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'LAST0001', 'max_uses' => 1]);
    $cart = vvCoCart($this->student, [vvCoCourse(100000)], $coupon);

    $other = User::factory()->student()->verified()->create();
    $hold = Order::factory()->create(['user_id' => $other->id, 'coupon_id' => $coupon->id, 'coupon_hold_until' => now()->addMinutes(10)]);

    // Preview an ma + notice
    $p = vvCoPreview()->assertOk();
    expect($p->json('coupon'))->toBeNull()->and($p->json('pricing.total'))->toBe(100000)->and(collect($p->json('notices'))->pluck('code')->all())->toContain('COUPON_EXHAUSTED');

    // Checkout: het cho -> go ma + 409
    $r = vvCoPost(90000)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect($r->json('errors.reasons'))->toBe(['COUPON_EXHAUSTED'])->and($cart->fresh()->coupon_id)->toBeNull()->and(Order::count())->toBe(1);

    // Giu cho het han -> nha cho
    $hold->forceFill(['coupon_hold_until' => now()->subMinute()])->save();
    Cart::query()->update(['coupon_id' => $coupon->id]);
    vvCoPost(90000)->assertCreated();
    expect(Order::where('coupon_id', $coupon->id)->where('status', 'pending')->count())->toBe(2);
});

test('suc chua ma: don pending cua chinh HS khong tu chan minh (dung lai don)', function () {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'LAST0002', 'max_uses' => 1]);
    vvCoCart($this->student, [vvCoCourse(100000)], $coupon);

    vvCoPost(90000)->assertCreated();
    vvCoPost(90000)->assertOk()->assertJsonPath('reused', true);
    expect(Order::count())->toBe(1);
});

test('khoa da so huu khong con trong don (unavailable); khoa xoa mem -> removed', function () {
    $owned = vvCoCourse(100000);
    $ok = vvCoCourse(50000);
    vvCoCart($this->student, [$owned, $ok]);
    Enrollment::factory()->create(['user_id' => $this->student->id, 'course_id' => $owned->id]);

    vvCoPost(50000)->assertCreated();
    expect(Order::first()->items->pluck('course_id')->all())->toBe([$ok->id]);
});

test('R1: co parent_consent_enforced tat (mac dinh) -> HS pending/revoked van preview + checkout duoc', function () {
    expect(config('features.parent_consent_enforced'))->toBeFalse();

    foreach ([ParentConsentStatus::Pending, ParentConsentStatus::Revoked] as $status) {
        $student = vvActAsStudent(User::factory()->student()->verified()->create(['parent_consent_status' => $status]));
        vvCoCart($student, [vvCoCourse(100000)]);

        vvCoPreview()->assertOk();
        vvCoPost(100000)->assertCreated();
    }
});

test('R4: don 0d - markPaid nem loi sau khi tao don -> don o lai pending; checkout lan 2 dung lai don va hoan tat', function () {
    $coupon = Coupon::factory()->percent(100)->create(['code' => 'FREE100C', 'max_uses' => 5, 'valid_until' => now()->addDay()]);
    vvCoCart($this->student, [vvCoCourse(100000)], $coupon);

    VvFlakyFulfillment::$fail = true;
    $this->app->bind(OrderFulfillmentService::class, fn ($app) => new VvFlakyFulfillment($app->make(OrderStateMachine::class), $app->make(EnrollmentService::class)));

    $this->withoutExceptionHandling();
    expect(fn () => vvCoPost(0))->toThrow(RuntimeException::class);
    $this->withExceptionHandling();

    $order = Order::firstOrFail();
    expect($order->status->value)->toBe('pending')->and(Enrollment::count())->toBe(0)->and(DB::table('coupon_usages')->count())->toBe(0);

    VvFlakyFulfillment::$fail = false;
    vvCoPost(0)->assertOk()->assertJsonPath('reused', true)->assertJsonPath('status', 'paid')->assertJsonPath('order_code', $order->code);

    expect(Order::count())->toBe(1)->and(Enrollment::count())->toBe(1)->and(DB::table('coupon_usages')->count())->toBe(1);
});

test('QA: dung lai don khi coupon_hold_until het nhung don chua het han va ma da het cho -> go ma, 409, khong cap lai giu cho', function () {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'REUSE001', 'max_uses' => 1]);
    vvCoCart($this->student, [vvCoCourse(100000)], $coupon);
    vvCoPost(90000)->assertCreated();
    $mine = Order::firstOrFail();

    $mine->forceFill(['coupon_hold_until' => now()->subMinute()])->save();
    $other = User::factory()->student()->verified()->create();
    Order::factory()->create(['user_id' => $other->id, 'coupon_id' => $coupon->id, 'coupon_hold_until' => now()->addMinutes(10)]);

    $r = vvCoPost(90000)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect($r->json('errors.reasons'))->toBe(['COUPON_EXHAUSTED'])
        ->and(Cart::first()->coupon_id)->toBeNull()
        ->and($mine->fresh()->coupon_hold_until->isPast())->toBeTrue();

    // Con cho (HS khac het giu cho): dung lai don, lam moi hold
    Order::where('user_id', $other->id)->update(['coupon_hold_until' => now()->subMinute()]);
    Cart::query()->update(['coupon_id' => $coupon->id]);
    vvCoPost(90000)->assertOk()->assertJsonPath('reused', true);
    expect($mine->fresh()->coupon_hold_until->isFuture())->toBeTrue();
});

test('QA: doi gio khi link cu con song -> don cu superseded; markPaid don cu -> paid + needs_review, attempt cu pending', function () {
    $a = vvCoCourse(100000);
    $b = vvCoCourse(50000);
    vvCoCart($this->student, [$a]);
    $first = vvCoPost(100000)->assertCreated()->json('order_code');
    CartItem::query()->insert(['cart_id' => Cart::first()->id, 'course_id' => $b->id, 'created_at' => now()]);
    vvCoPost(150000)->assertCreated();

    $old = Order::where('code', $first)->firstOrFail();
    expect($old->status->value)->toBe('cancelled')
        ->and(PaymentAttempt::where('order_id', $old->id)->value('status')->value)->toBe('pending');

    app(OrderFulfillmentService::class)->markPaid($old, 'ipn', 'REF-OLD');
    $old->refresh();
    expect($old->status->value)->toBe('paid')->and($old->needs_review)->toBeTrue()
        ->and(Enrollment::where('user_id', $this->student->id)->where('course_id', $a->id)->where('order_id', $old->id)->exists())->toBeTrue();
    expect(Order::where('status', 'pending')->count())->toBe(1);
});

test('QA: doi cong khi link cu con song -> don cu superseded, markPaid don cu -> paid + needs_review', function () {
    config(['payments.enabled_gateways' => ['fake', 'momo']]);
    vvCoCart($this->student, [vvCoCourse(100000)]);
    $first = vvCoPost(100000)->assertCreated()->json('order_code');

    $r = test()->postJson(vvApiUrl('/checkout'), ['expected_total' => 100000, 'gateway' => 'momo'], vvWebHeaders());
    if ($r->status() === 201) {
        $old = Order::where('code', $first)->firstOrFail();
        expect($old->status->value)->toBe('cancelled');
        app(OrderFulfillmentService::class)->markPaid($old, 'ipn');
        expect($old->fresh()->status->value)->toBe('paid')->and($old->fresh()->needs_review)->toBeTrue();
    } else {
        // momo chua kha dung o v1 (T19): ghi nhan, khong fail
        expect($r->status())->toBeIn([409, 422, 502]);
    }
});

test('QA: khoa bi xoa mem truoc markPaid -> don paid + needs_review, khong enrollment cho khoa do, khong 500', function () {
    $a = vvCoCourse(100000);
    $b = vvCoCourse(50000);
    vvCoCart($this->student, [$a, $b]);
    vvCoPost(150000)->assertCreated();
    $order = Order::firstOrFail();

    $a->delete();
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    $order->refresh();
    expect($order->status->value)->toBe('paid')->and($order->needs_review)->toBeTrue()
        ->and(Enrollment::where('course_id', $a->id)->count())->toBe(0)
        ->and(Enrollment::where('course_id', $b->id)->count())->toBe(1);
});

test('QA: mua trung khoa da so huu (sau khi tao don) -> markPaid giu quyen cu, needs_review', function () {
    $a = vvCoCourse(100000);
    vvCoCart($this->student, [$a]);
    vvCoPost(100000)->assertCreated();
    $order = Order::firstOrFail();

    $owned = Enrollment::factory()->create(['user_id' => $this->student->id, 'course_id' => $a->id]);
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    expect($order->fresh()->status->value)->toBe('paid')->and($order->fresh()->needs_review)->toBeTrue()
        ->and(Enrollment::where('user_id', $this->student->id)->where('course_id', $a->id)->count())->toBe(1)
        ->and($owned->fresh()->order_id)->not->toBe($order->id);
});

test('QA: markPaid idempotent - goi 2 lan khong cap lai / khong ghi coupon_usages 2 lan', function () {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'IDEM0001', 'max_uses' => 3]);
    vvCoCart($this->student, [vvCoCourse(100000)], $coupon);
    vvCoPost(90000)->assertCreated();
    $order = Order::firstOrFail();

    $svc = app(OrderFulfillmentService::class);
    $svc->markPaid($order, 'ipn');
    $svc->markPaid($order->fresh(), 'ipn');

    expect(Enrollment::count())->toBe(1)->and(DB::table('coupon_usages')->count())->toBe(1)->and($coupon->fresh()->used_count)->toBe(1)
        ->and($order->fresh()->needs_review)->toBeFalse();
});

test('QA: gia client khong duoc tin - gui them price/items vao body bi bo qua, expected_total sai luon 409', function () {
    $c = vvCoCourse(100000);
    vvCoCart($this->student, [$c]);

    vvCoPost(1, ['total' => 1, 'price' => 1, 'items' => [['course_id' => $c->id, 'price' => 1]]])->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect(Order::count())->toBe(0);
    vvCoPost(100000, ['total' => 1, 'items' => [['course_id' => $c->id, 'price' => 1]]])->assertCreated();
    expect(Order::first()->total_amount)->toBe(100000);
});

test('QA: nhan vien (admin/quan ly) khong goi duoc checkout cua hoc sinh', function () {
    vvActAsStudent(User::factory()->admin()->create());
    vvCoPreview()->assertForbidden();
    vvCoPost(0)->assertForbidden();
    expect(Order::count())->toBe(0);
});

test('QA: cong bat co parent_consent_enforced -> pending 403 PARENT_CONSENT_REQUIRED o ca preview', function () {
    config(['features.parent_consent_enforced' => true]);
    vvActAsStudent(User::factory()->student()->verified()->create(['parent_consent_status' => ParentConsentStatus::Pending]));
    vvCoPreview()->assertForbidden()->assertJsonPath('code', 'PARENT_CONSENT_REQUIRED');
    vvCoPost(0)->assertForbidden()->assertJsonPath('code', 'PARENT_CONSENT_REQUIRED');
});

test('V2: cờ paid_checkout tắt -> POST tổng > 0 trả 503 PAYMENT_DISABLED, không tạo đơn/attempt; preview can_checkout=false + notice', function () {
    config(['features.paid_checkout' => false]);
    vvCoCart($this->student, [vvCoCourse(100000)]);

    $p = vvCoPreview()->assertOk();
    expect($p->json('can_checkout'))->toBeFalse()->and($p->json('requires_payment'))->toBeTrue()
        ->and(collect($p->json('notices'))->pluck('code')->all())->toContain('PAYMENT_DISABLED');

    vvCoPost(100000)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_DISABLED');
    expect(Order::count())->toBe(0)->and(PaymentAttempt::count())->toBe(0);
});

test('V2: cờ paid_checkout tắt -> đơn 0đ (mã giảm hết) vẫn thành công, preview can_checkout=true', function () {
    config(['features.paid_checkout' => false]);
    $coupon = Coupon::factory()->percent(100)->create(['max_uses' => 5, 'valid_until' => now()->addDay()]);
    vvCoCart($this->student, [vvCoCourse(100000)], $coupon);

    vvCoPreview()->assertOk()->assertJsonPath('can_checkout', true)->assertJsonPath('requires_payment', false);
    vvCoPost(0)->assertCreated()->assertJsonPath('status', 'paid');
});

test('V2: đã có đơn pending rồi tắt cờ -> POST lại 503, đơn cũ không bị superseded, không có attempt mới', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    vvCoPost(100000)->assertCreated();

    config(['features.paid_checkout' => false]);
    vvCoPost(100000)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_DISABLED');

    expect(Order::count())->toBe(1)->and(PaymentAttempt::count())->toBe(1)
        ->and(Order::first()->status->value)->toBe('pending');
});

test('V2: config/public trả paid_checkout_enabled theo cờ', function () {
    foreach ([true, false] as $flag) {
        config(['features.paid_checkout' => $flag]);
        $this->getJson('http://'.config('app.api_host').'/api/v1/config/public')->assertOk()->assertJsonPath('paid_checkout_enabled', $flag);
    }
});

test('QA V2: cờ tắt + expected_total sai -> 409 CHECKOUT_CHANGED chứ không phải 503', function () {
    config(['features.paid_checkout' => false]);
    vvCoCart($this->student, [vvCoCourse(100000)]);

    vvCoPost(1)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    vvCoPost(0)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect(Order::count())->toBe(0)->and(PaymentAttempt::count())->toBe(0);
});

test('QA V2: cờ tắt + mã đang được giữ chỗ bởi đơn pending cũ -> 503, giữ chỗ không đổi, mã vẫn trong giỏ, HS khác vẫn thấy hết chỗ', function () {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'HOLD0001', 'max_uses' => 1, 'valid_until' => now()->addDay()]);
    $course = vvCoCourse(100000);
    $cart = vvCoCart($this->student, [$course], $coupon);
    vvCoPost(90000)->assertCreated();
    $order = Order::firstOrFail();
    $holdUntil = $order->coupon_hold_until;
    expect($holdUntil)->not->toBeNull();

    config(['features.paid_checkout' => false]);
    vvCoPost(90000)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_DISABLED');

    $order->refresh();
    expect(Order::count())->toBe(1)->and($order->status->value)->toBe('pending')
        ->and($order->coupon_hold_until->equalTo($holdUntil))->toBeTrue()
        ->and($cart->fresh()->coupon_id)->toBe($coupon->id)
        ->and($coupon->fresh()->used_count)->toBe(0)
        ->and(PaymentAttempt::count())->toBe(1);

    // HS khác: mã hết chỗ (đơn cũ còn giữ) -> giá đổi -> 409, không bị 503 che mất
    vvActAsStudent(User::factory()->student()->verified()->create());
    vvCoCart(auth()->user(), [$course], $coupon);
    vvCoPost(90000)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    expect(Order::count())->toBe(1);
});

test('QA V2: bật lại cờ sau khi tắt -> đơn pending cũ được dùng lại (200 reused), không tạo đơn/attempt mới', function () {
    vvCoCart($this->student, [vvCoCourse(100000)]);
    $first = vvCoPost(100000)->assertCreated();

    config(['features.paid_checkout' => false]);
    vvCoPost(100000)->assertStatus(503);

    config(['features.paid_checkout' => true]);
    $again = vvCoPost(100000)->assertOk()->assertJsonPath('reused', true);

    expect($again->json('order_code'))->toBe($first->json('order_code'))
        ->and($again->json('payment.pay_url'))->toBe($first->json('payment.pay_url'))
        ->and(Order::count())->toBe(1)->and(PaymentAttempt::count())->toBe(1)
        ->and(Order::first()->status->value)->toBe('pending');
});
