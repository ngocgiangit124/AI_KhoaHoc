<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderNote>
 */
class OrderNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->manual(),
            'author_id' => User::factory()->admin(),
            'body' => 'Đã gọi học sinh, hẹn chuyển khoản chiều nay.',
            'created_at' => now(),
        ];
    }
}
