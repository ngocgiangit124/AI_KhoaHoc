<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
        ];
    }

    /**
     * `coupon_id` không fillable (S17) nên gán sau khi tạo model.
     */
    public function withCoupon(?Coupon $coupon = null): static
    {
        return $this->afterMaking(function (Cart $cart) use ($coupon): void {
            $cart->coupon_id = ($coupon ?? Coupon::factory()->create())->id;
        });
    }
}
