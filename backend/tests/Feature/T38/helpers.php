<?php

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T04/helpers.php';

/** Bật phương thức `manual`, tắt MoMo (mặc định của US-022): chỉ `manual` có mặt. */
function vvMoConfig(array $extra = []): void
{
    config(array_merge([
        'features.manual_payment' => true,
        'features.paid_checkout' => false,
        'payments.enabled_gateways' => ['fake'],
        'orders.manual.notify_emails' => ['ops@example.com'],
        'orders.manual.contact' => ['phone' => '0901 234 567', 'zalo_url' => 'https://zalo.me/0901234567', 'email' => 'hotro@example.com', 'hours' => '8:00-21:00'],
    ], $extra));
}

function vvMoStudent(array $attrs = []): User
{
    return vvActAsStudent(User::factory()->student()->verified()->create(array_merge(['name' => 'Tran Van Tinh', 'email' => 'tinh.'.uniqid().'@example.com', 'phone' => '09'.random_int(10000000, 99999999)], $attrs)));
}

function vvMoCourse(int $price = 100000): Course
{
    return Course::factory()->published()->paid($price)->create();
}

/** @param  list<Course>  $courses */
function vvMoCart(User $user, array $courses, ?Coupon $coupon = null): Cart
{
    $f = Cart::factory()->state(['user_id' => $user->id])->withCourses($courses);
    if ($coupon !== null) {
        $f = $f->withCoupon($coupon);
    }

    return $f->create();
}

/** POST /checkout KHÔNG tự thêm `gateway` (khác helper T18). */
function vvMoPost(int $expected, array $extra = [])
{
    return test()->postJson(vvApiUrl('/checkout'), array_merge(['expected_total' => $expected], $extra), vvWebHeaders());
}

function vvMoPreview()
{
    return test()->getJson(vvApiUrl('/checkout/preview'), vvWebHeaders());
}

/** Đơn `manual` `pending` kèm dòng đơn thật (khoá cho trước). */
function vvMoOrder(User $user, ?Course $course = null, array $state = [], string $factoryState = 'manual'): Order
{
    $course ??= vvMoCourse(100000);
    $order = Order::factory()->{$factoryState}()->create(array_merge(['user_id' => $user->id], $state));
    DB::table('order_items')->insert([
        'order_id' => $order->id, 'course_id' => $course->id, 'course_title' => $course->title,
        'unit_price' => $order->subtotal_amount, 'discount_amount' => $order->discount_amount, 'final_amount' => $order->total_amount,
    ]);

    return $order;
}

/** Giờ VN → Carbon theo múi giờ của app (Eloquent ghi chuỗi giờ theo múi giờ của chính Carbon, không đổi). */
function vvMoVn(string $dateTime): Carbon
{
    return Carbon::parse($dateTime, 'Asia/Ho_Chi_Minh')->setTimezone((string) config('app.timezone'));
}

/** Thêm khóa vào giỏ đã có. */
function vvMoAddToCart(Cart $cart, Course $course): void
{
    DB::table('cart_items')->insert(['cart_id' => $cart->id, 'course_id' => $course->id, 'created_at' => now()]);
}
