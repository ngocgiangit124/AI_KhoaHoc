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

    /** Đơn thanh toán thủ công đang chờ duyệt (US-022): hạn 72 giờ, giữ chỗ mã tới hạn đơn nếu có mã. */
    public function manual(): static
    {
        return $this->state(function (array $attrs) {
            $expiresAt = now()->addHours((int) config('orders.manual.pending_ttl_hours', 72));

            return [
                'payment_method' => 'manual',
                'expires_at' => $expiresAt,
                'coupon_hold_until' => ($attrs['coupon_id'] ?? null) !== null ? $expiresAt : null,
            ];
        });
    }

    public function pendingManual(): static
    {
        return $this->manual();
    }

    /** Đơn `manual` đã quá `expires_at` nhưng job `orders:expire-manual` chưa chạy (vẫn `pending`). */
    public function expiredManual(): static
    {
        return $this->manual()->state(fn () => ['expires_at' => now()->subMinute(), 'created_at' => now()->subHours(73)]);
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
