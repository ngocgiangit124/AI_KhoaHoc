<?php

use App\Mail\ManualOrderCancelledMail;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CouponCapacity;
use App\Services\Orders\ManualOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/helpers.php';

function vvMoGet(string $path)
{
    return test()->getJson(vvApiUrl($path), vvWebHeaders());
}

function vvMoCancel(string $code)
{
    return test()->postJson(vvApiUrl("/orders/{$code}/cancel"), [], vvWebHeaders());
}

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
    $this->student = vvMoStudent();
});

test('(i) GET /orders: mới nhất trước, 10/trang, meta/links đúng shape, item_titles tối đa 3, chỉ đơn của mình', function () {
    for ($i = 0; $i < 12; $i++) {
        $order = Order::factory()->cancelled('user_cancelled')->create(['user_id' => $this->student->id, 'payment_method' => 'manual', 'created_at' => now()->subHours(20 - $i)]);
        foreach (range(1, $i === 11 ? 5 : 1) as $n) {
            DB::table('order_items')->insert(['order_id' => $order->id, 'course_id' => vvMoCourse()->id, 'course_title' => "Khoa {$i}-{$n}", 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000]);
        }
    }
    Order::factory()->create(['payment_method' => 'manual']); // của người khác

    $r = vvMoGet('/orders')->assertOk();
    expect($r->json('data'))->toHaveCount(10)
        ->and($r->json('meta'))->toBe(['current_page' => 1, 'per_page' => 10, 'total' => 12, 'last_page' => 2])
        ->and($r->json('links.prev'))->toBeNull()->and($r->json('links.next'))->not->toBeNull()
        ->and($r->json('data.0.items_count'))->toBe(5)
        ->and($r->json('data.0.item_titles'))->toHaveCount(3)
        ->and($r->json('data.1.item_titles'))->toBe(['Khoa 10-1'])
        ->and(array_keys($r->json('data.0')))->toBe(['code', 'status', 'status_reason', 'payment_method', 'items_count', 'item_titles', 'subtotal', 'discount', 'total', 'created_at', 'expires_at', 'paid_at', 'cancelled_at', 'can_cancel', 'replaced_by_code']);

    expect(vvMoGet('/orders?page=2')->json('data'))->toHaveCount(2);
    vvMoGet('/orders?page=0')->assertStatus(422);
    vvMoGet('/orders?page=abc')->assertStatus(422);
});

test('(i) GET /orders không N+1: số truy vấn như nhau với 2 hay 10 đơn', function () {
    foreach (range(1, 10) as $i) {
        vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'created_at' => now()->subMinutes($i)]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    vvMoGet('/orders')->assertOk();
    $many = count(DB::getQueryLog());

    $drop = Order::query()->orderBy('id')->pluck('id')->slice(2)->all();
    DB::table('order_items')->whereIn('order_id', $drop)->delete();
    DB::table('orders')->whereIn('id', $drop)->delete();
    DB::flushQueryLog();
    vvMoGet('/orders')->assertOk();
    $few = count(DB::getQueryLog());

    expect($many)->toBe($few);
});

test('(i) GET /orders/{code}: shape đầy đủ, không có khoá nội bộ; can_cancel đúng', function () {
    $order = vvMoOrder($this->student, null, ['customer_note' => 'Gọi sau 18h', 'payment_reference' => 'SECRET-REF', 'needs_review' => true, 'confirmed_by' => User::factory()->admin()->create()->id]);
    DB::table('order_notes')->insert(['order_id' => $order->id, 'author_id' => User::factory()->admin()->create()->id, 'body' => 'GHICHUNOIBO', 'created_at' => now()]);

    $r = vvMoGet("/orders/{$order->code}")->assertOk();

    expect(array_keys($r->json()))->toBe(['code', 'status', 'status_reason', 'payment_method', 'items', 'coupon_code', 'subtotal', 'discount', 'total', 'customer_note', 'cancel_reason', 'replaced_by_code', 'created_at', 'expires_at', 'paid_at', 'cancelled_at', 'refunded_at', 'can_cancel', 'payment'])
        ->and($r->json('can_cancel'))->toBeTrue()->and($r->json('payment'))->toBeNull()->and($r->json('customer_note'))->toBe('Gọi sau 18h')
        ->and(array_keys($r->json('items.0')))->toBe(['course_id', 'title', 'slug', 'grade_level', 'unit_price', 'discount_amount', 'final_amount']);

    $raw = $r->getContent();
    expect($raw)->not->toContain('SECRET-REF')->not->toContain('GHICHUNOIBO')->not->toContain('needs_review')->not->toContain('confirmed_by');
});

test('(i) chi tiết đơn người khác hoặc mã không tồn tại -> 404 NOT_FOUND cùng body (AC11)', function () {
    $other = Order::factory()->manual()->create();
    $a = vvMoGet("/orders/{$other->code}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $b = vvMoGet('/orders/VV000000NOPE00')->assertNotFound();

    expect($a->json('message'))->toBe($b->json('message'))->and(array_keys($a->json()))->toBe(array_keys($b->json()));
    vvMoCancel($other->code)->assertNotFound();
    expect($other->fresh()->status->value)->toBe('pending');
});

test('(i) cancel_reason chỉ khi admin_cancelled; replaced_by_code khi superseded; slug null khi khóa đã xoá', function () {
    $course = vvMoCourse();
    $admin = vvMoOrder($this->student, $course, ['status' => 'cancelled', 'status_reason' => 'admin_cancelled', 'cancel_reason_public' => 'Không liên hệ được', 'cancelled_at' => now(), 'created_at' => now()->subDays(3)], 'manual');
    $user = vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancel_reason_public' => 'KHONG-LO', 'cancelled_at' => now(), 'created_at' => now()->subDays(2)]);
    $sup = vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'superseded', 'cancelled_at' => now(), 'created_at' => now()->subDays(1)]);
    $next = vvMoOrder($this->student);

    expect(vvMoGet("/orders/{$admin->code}")->json('cancel_reason'))->toBe('Không liên hệ được');
    expect(vvMoGet("/orders/{$user->code}")->json('cancel_reason'))->toBeNull();
    expect(vvMoGet("/orders/{$sup->code}")->json('replaced_by_code'))->toBe($next->code);
    expect(vvMoGet("/orders/{$admin->code}")->json('items.0.slug'))->toBe($course->slug)->and(vvMoGet("/orders/{$admin->code}")->json('items.0.grade_level'))->toBe($course->grade_level);

    $course->delete();
    expect(vvMoGet("/orders/{$admin->code}")->json('items.0.slug'))->toBeNull()->and(vvMoGet("/orders/{$admin->code}")->json('items.0.grade_level'))->toBeNull();

    $list = collect(vvMoGet('/orders')->json('data'))->keyBy('code');
    expect($list[$sup->code]['replaced_by_code'])->toBe($next->code)->and($list[$user->code]['replaced_by_code'])->toBeNull()->and($list[$next->code]['replaced_by_code'])->toBeNull();
});

test('(i) đơn cổng (fake) pending có payment {gateway, link_expired, can_retry}, can_cancel=false; đơn 0đ payment null', function () {
    $momo = Order::factory()->create(['user_id' => $this->student->id]);
    $r = vvMoGet("/orders/{$momo->code}")->assertOk();
    expect($r->json('can_cancel'))->toBeFalse()->and($r->json('payment'))->toBe(['gateway' => 'fake', 'link_expired' => true, 'can_retry' => true]);

    $zero = Order::factory()->paid()->create(['user_id' => $this->student->id, 'payment_method' => 'none', 'subtotal_amount' => 0, 'total_amount' => 0]);
    expect(vvMoGet("/orders/{$zero->code}")->json('payment'))->toBeNull();
});

test('(j) HS tự huỷ: 200 user_cancelled, giỏ nguyên, không thư; lần 2 -> 409 ALREADY_PROCESSED', function () {
    $cart = vvMoCart($this->student, [vvMoCourse(100000)]);
    $code = vvMoPost(100000)->assertCreated()->json('order_code');
    Mail::fake();

    $r = vvMoCancel($code)->assertOk();
    expect($r->json('status'))->toBe('cancelled')->and($r->json('status_reason'))->toBe('user_cancelled')->and($r->json('can_cancel'))->toBeFalse()->and($r->json('cancelled_at'))->toEndWith('+07:00');

    $log = DB::table('order_status_logs')->where('order_id', Order::where('code', $code)->value('id'))->orderByDesc('id')->first();
    expect($log->to_status)->toBe('cancelled')->and($log->actor_type)->toBe('user')->and((int) $log->actor_id)->toBe($this->student->id)
        ->and($cart->items()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'like', 'order.%')->where('subject_id', Order::where('code', $code)->value('id'))->count())->toBe(0);
    Mail::assertNothingQueued();

    vvMoCancel($code)->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED')->assertJsonPath('errors.status', 'cancelled')->assertJsonPath('errors.status_reason', 'user_cancelled');
});

test('(j) huỷ nhả chỗ lượt mã ngay: trước huỷ mã hết chỗ, sau huỷ còn chỗ', function () {
    $coupon = Coupon::factory()->percent(10)->create(['max_uses' => 1, 'valid_until' => now()->addDays(5)]);
    vvMoCart($this->student, [vvMoCourse(100000)], $coupon);
    $code = vvMoPost(90000)->assertCreated()->json('order_code');

    expect(app(CouponCapacity::class)->hasRoom($coupon->fresh()))->toBeFalse();
    vvMoCancel($code)->assertOk();
    expect(app(CouponCapacity::class)->hasRoom($coupon->fresh()))->toBeTrue();
});

test('(j) đơn manual đã paid -> 409 ORDER_STATUS_CHANGED; đơn fake pending và đơn 0đ -> 409 ORDER_NOT_MANUAL (kiểm phương thức trước trạng thái)', function () {
    $paid = vvMoOrder($this->student, null, ['status' => 'paid', 'paid_at' => now()]);
    vvMoCancel($paid->code)->assertStatus(409)->assertJsonPath('code', 'ORDER_STATUS_CHANGED')->assertJsonPath('errors.status', 'paid');
    expect($paid->fresh()->status->value)->toBe('paid');

    $momo = Order::factory()->create(['user_id' => $this->student->id]);
    vvMoCancel($momo->code)->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_MANUAL');
    expect($momo->fresh()->status->value)->toBe('pending');

    $zero = Order::factory()->paid()->create(['user_id' => $this->student->id, 'payment_method' => 'none', 'subtotal_amount' => 0, 'total_amount' => 0]);
    vvMoCancel($zero->code)->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_MANUAL');
});

test('(j) huỷ chạy được khi cờ manual tắt (AC29); xem đơn không cần xác thực OTP', function () {
    $order = vvMoOrder($this->student);
    vvMoConfig(['features.manual_payment' => false]);

    vvMoCancel($order->code)->assertOk();

    $unverified = vvActAsStudent(User::factory()->student()->create());
    vvMoGet('/orders')->assertOk()->assertJsonPath('data', []);
});

test('(j) limiter order-cancel: lần thứ 11 trong phút -> 429', function () {
    $order = vvMoOrder($this->student);
    for ($i = 0; $i < 10; $i++) {
        vvMoCancel($order->code);
    }
    vvMoCancel($order->code)->assertStatus(429);
});

test('(j) limiter orders-read: lần thứ 61 trong phút -> 429', function () {
    for ($i = 0; $i < 60; $i++) {
        vvMoGet('/orders')->assertOk();
    }
    vvMoGet('/orders')->assertStatus(429);
});

test('khách chưa đăng nhập 401', function () {
    auth()->logout();
    $this->app['auth']->forgetGuards();
    test()->flushSession();

    vvMoGet('/orders')->assertUnauthorized();
    test()->postJson(vvApiUrl('/orders/VV261008K7M2QX/cancel'), [], vvWebHeaders())->assertUnauthorized();
});

test('giáo viên gọi /orders* -> 403', function () {
    $order = vvMoOrder($this->student);
    vvActAsStudent(User::factory()->teacher()->create());

    vvMoGet('/orders')->assertForbidden();
    vvMoGet("/orders/{$order->code}")->assertForbidden();
    vvMoCancel($order->code)->assertForbidden();
    expect($order->fresh()->status->value)->toBe('pending');
});

test('(j) huỷ trong lúc tác vụ hết hạn đã huỷ trước: chỉ 1 dòng log chuyển cancelled (tuần tự)', function () {
    $order = vvMoOrder($this->student, null, [], 'expiredManual');

    app(ManualOrderService::class)->expireDue();
    vvMoCancel($order->code)->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED')->assertJsonPath('errors.status_reason', 'expired');

    expect(DB::table('order_status_logs')->where('order_id', $order->id)->where('to_status', 'cancelled')->count())->toBe(1);
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);
});

test('route: /orders* nằm trong nhóm student + role hoc_sinh, không có account.verified', function () {
    foreach (['api.orders.index', 'api.orders.show', 'api.orders.cancel'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        $mw = $route->gatherMiddleware();
        expect($mw)->toContain('auth:sanctum')->toContain('role:hoc_sinh')->toContain('student.single_session')->not->toContain('account.verified');
    }
    expect(Route::getRoutes()->getByName('api.orders.cancel')->gatherMiddleware())->toContain('throttle:order-cancel');
    expect(Route::getRoutes()->getByName('api.orders.show')->gatherMiddleware())->toContain('throttle:orders-read');
});
