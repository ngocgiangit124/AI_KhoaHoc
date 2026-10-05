<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Giỏ của một học sinh (mặc định rỗng, không mã). `withCourses` thêm dòng; `withCoupon` gắn mã (forceFill: S17).
 *
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory()->student()];
    }

    /**
     * @param  iterable<Course>  $courses
     */
    public function withCourses(iterable $courses): static
    {
        return $this->afterCreating(function (Cart $cart) use ($courses): void {
            foreach ($courses as $course) {
                CartItem::query()->insert(['cart_id' => $cart->getKey(), 'course_id' => $course->getKey(), 'created_at' => now()]);
            }
        });
    }

    public function withCoupon(Coupon $coupon): static
    {
        return $this->afterCreating(fn (Cart $cart) => $cart->forceFill(['coupon_id' => $coupon->getKey()])->save());
    }
}
