<?php

namespace Database\Factories;

use App\Models\StaffKnownDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffKnownDevice>
 */
class StaffKnownDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->teacher(),
            'device_id' => (string) Str::uuid(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
