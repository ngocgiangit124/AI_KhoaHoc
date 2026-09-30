<?php

namespace Database\Factories;

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => ucfirst(fake()->words(3, true)),
            'discount_type' => CouponDiscountType::Percent,
            'discount_value' => fake()->numberBetween(5, 50),
            'max_uses' => null,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'status' => CouponStatus::Active,
            'is_restricted' => false,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function fixedAmount(int $value = 50000): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => CouponDiscountType::FixedAmount,
            'discount_value' => $value,
        ]);
    }

    /**
     * Mã giảm 100% — theo `chk_coupons_full_discount_limited` (DBA #5) BẮT
     * BUỘC có `max_uses` + `valid_until`.
     */
    public function fullDiscount(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => CouponDiscountType::Percent,
            'discount_value' => 100,
            'max_uses' => 100,
            'valid_until' => now()->addMonth(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CouponStatus::Inactive,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_restricted' => true,
        ]);
    }

    public function used(int $count = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'used_count' => $count,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now()->subMonths(2),
            'valid_until' => now()->subDay(),
        ]);
    }
}
