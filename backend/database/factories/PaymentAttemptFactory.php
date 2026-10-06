<?php

namespace Database\Factories;

use App\Enums\PaymentAttemptStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = Order::factory();

        return [
            'order_id' => $order,
            'gateway' => 'fake',
            'gateway_order_id' => 'VV'.strtoupper(Str::random(8)).'-1',
            'request_id' => (string) Str::uuid(),
            'amount' => 100000,
            'status' => PaymentAttemptStatus::Pending,
            'pay_url' => 'https://fake-pay.vitaminvui.test/pay/x',
            'expires_at' => now()->addMinutes(30),
            'next_check_at' => now()->addMinutes(3),
        ];
    }
}
