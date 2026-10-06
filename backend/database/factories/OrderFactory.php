<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Đơn pending 100.000đ không mã, hết hạn sau 12 giờ. Dòng đơn tạo riêng (`OrderItem`).
 * Factory chạy unguarded nên không vướng $fillable rỗng.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'VV'.now()->format('ymd').strtoupper(fake()->unique()->bothify('######')),
            'user_id' => User::factory()->student(),
            'status' => OrderStatus::Pending,
            'subtotal_amount' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'payment_method' => 'fake',
            'needs_review' => false,
            'expires_at' => now()->addHours(12),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Paid, 'paid_at' => now()]);
    }

    public function cancelled(string $reason = 'superseded'): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Cancelled, 'status_reason' => $reason, 'cancelled_at' => now()]);
    }
}
