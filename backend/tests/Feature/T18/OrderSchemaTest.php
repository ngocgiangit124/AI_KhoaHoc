<?php

use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Coupons\CouponService;
use App\Services\Courses\CourseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('FK enrollments.order_id -> orders.id (restrict): order_id khong ton tai bi tu choi, xoa don dang dung bi chan', function () {
    $user = User::factory()->student()->create();
    $course = Course::factory()->published()->paid()->create();

    expect(fn () => Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'order_id' => 999999]))
        ->toThrow(QueryException::class);

    $order = Order::factory()->paid()->create(['user_id' => $user->id]);
    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'order_id' => $order->id]);
    expect(fn () => DB::table('orders')->where('id', $order->id)->delete())->toThrow(QueryException::class);

    $fk = DB::selectOne("SELECT DELETE_RULE r FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'enrollments_order_id_foreign'");
    expect($fk->r)->toBe('RESTRICT');
});

test('moi HS toi da 1 don pending (unique user_id, pending_flag); don paid/cancelled khong gioi han', function () {
    $user = User::factory()->student()->create();
    Order::factory()->create(['user_id' => $user->id]);

    expect(fn () => Order::factory()->create(['user_id' => $user->id]))->toThrow(QueryException::class);

    Order::factory()->paid()->create(['user_id' => $user->id]);
    Order::factory()->paid()->create(['user_id' => $user->id]);
    Order::factory()->cancelled()->create(['user_id' => $user->id]);
    expect(Order::where('user_id', $user->id)->count())->toBe(4);
});

test('CHECK: total + discount = subtotal; code unique; order_items unit = discount + final; U(order, course)', function () {
    $user = User::factory()->student()->create();
    expect(fn () => Order::factory()->create(['user_id' => $user->id, 'subtotal_amount' => 100, 'discount_amount' => 10, 'total_amount' => 10]))
        ->toThrow(QueryException::class);

    $o = Order::factory()->create(['user_id' => $user->id, 'code' => 'VV261014AAAAAA']);
    expect(fn () => Order::factory()->paid()->create(['user_id' => $user->id, 'code' => 'VV261014AAAAAA']))->toThrow(QueryException::class);

    $course = Course::factory()->published()->paid()->create();
    $row = ['order_id' => $o->id, 'course_id' => $course->id, 'course_title' => 'x', 'unit_price' => 100, 'discount_amount' => 10, 'final_amount' => 50];
    expect(fn () => OrderItem::query()->insert($row))->toThrow(QueryException::class);
    OrderItem::query()->insert(['final_amount' => 90] + $row);
    expect(fn () => OrderItem::query()->insert(['final_amount' => 90] + $row))->toThrow(QueryException::class);
});

test('unique coupon_usages (coupon,user) va (order); payment_attempts (gateway, gateway_order_id)', function () {
    $user = User::factory()->student()->create();
    $coupon = Coupon::factory()->create();
    $o1 = Order::factory()->paid()->create(['user_id' => $user->id]);
    $o2 = Order::factory()->paid()->create(['user_id' => $user->id]);
    DB::table('coupon_usages')->insert(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'order_id' => $o1->id, 'used_at' => now()]);

    expect(fn () => DB::table('coupon_usages')->insert(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'order_id' => $o2->id, 'used_at' => now()]))->toThrow(QueryException::class);

    PaymentAttempt::factory()->create(['order_id' => $o1->id, 'gateway_order_id' => 'A-1']);
    expect(fn () => PaymentAttempt::factory()->create(['order_id' => $o2->id, 'gateway_order_id' => 'A-1']))->toThrow(QueryException::class);
});

test('CouponService::isUsed voi bang that: sua code/gia tri bi COUPON_LOCKED khi co don tham chieu; xoa -> COUPON_IN_USE (don, coupon_usages)', function () {
    $svc = new class(app(AuditLogger::class)) extends CouponService
    {
        public function used(Coupon $c): bool
        {
            return $this->isUsed($c);
        }
    };

    $c1 = Coupon::factory()->create();
    expect($svc->used($c1))->toBeFalse();

    Order::factory()->cancelled()->create(['coupon_id' => $c1->id]);
    expect($svc->used($c1))->toBeTrue();
    expect(fn () => $svc->delete($c1))->toThrow(DomainException::class, 'Mã giảm giá đã được sử dụng');

    $c2 = Coupon::factory()->create();
    $o = Order::factory()->paid()->create();
    DB::table('coupon_usages')->insert(['coupon_id' => $c2->id, 'user_id' => $o->user_id, 'order_id' => $o->id, 'used_at' => now()]);
    expect($svc->used($c2))->toBeTrue();
    try {
        $svc->delete($c2);
    } catch (DomainException $e) {
        expect($e->code())->toBe('COUPON_IN_USE');
    }
    expect(Coupon::find($c2->id))->not->toBeNull();
});

test('CourseService::delete: khoa trong don pending con han -> 409 COURSE_HAS_PENDING_ORDERS; het han/da huy/paid(khong enrollment) thi xoa duoc', function () {
    $svc = app(CourseService::class);
    $course = Course::factory()->published()->paid()->create();
    $order = Order::factory()->create();
    OrderItem::query()->insert(['order_id' => $order->id, 'course_id' => $course->id, 'course_title' => 'x', 'unit_price' => 100000, 'discount_amount' => 0, 'final_amount' => 100000]);

    try {
        $svc->delete($course);
        $this->fail('phai bi chan');
    } catch (DomainException $e) {
        expect($e->code())->toBe('COURSE_HAS_PENDING_ORDERS')->and($e->status())->toBe(409);
    }
    expect(Course::find($course->id))->not->toBeNull();

    Order::query()->whereKey($order->id)->update(['expires_at' => now()->subMinute()]);
    $svc->delete($course);
    expect(Course::find($course->id))->toBeNull();
});
