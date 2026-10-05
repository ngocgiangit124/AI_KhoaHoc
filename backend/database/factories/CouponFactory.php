<?php

namespace Database\Factories;

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mặc định: giảm 20%, đang hiệu lực, không giới hạn, toàn bộ khóa học. `used_count`/`code` đặt qua state
 * (factory dùng forceFill nên không vướng $fillable).
 *
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('VV####??##')),
            'name' => 'Khuyến mãi '.fake()->words(2, true),
            'discount_type' => CouponDiscountType::Percent,
            'discount_value' => 20,
            'max_uses' => null,
            'used_count' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => null,
            'status' => CouponStatus::Active,
            'is_restricted' => false,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function fixed(int $amount = 50000): static
    {
        return $this->state(fn () => ['discount_type' => CouponDiscountType::FixedAmount, 'discount_value' => $amount]);
    }

    public function percent(int $value = 20): static
    {
        return $this->state(fn () => ['discount_type' => CouponDiscountType::Percent, 'discount_value' => $value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => CouponStatus::Inactive]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['valid_from' => now()->subDays(10), 'valid_until' => now()->subDay()]);
    }

    public function upcoming(): static
    {
        return $this->state(fn () => ['valid_from' => now()->addDay()]);
    }

    public function exhausted(int $max = 5): static
    {
        return $this->state(fn () => ['max_uses' => $max, 'used_count' => $max]);
    }

    public function restricted(): static
    {
        return $this->state(fn () => ['is_restricted' => true]);
    }
}
