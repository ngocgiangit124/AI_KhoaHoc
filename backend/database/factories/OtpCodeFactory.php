<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Mặc định: mã `123456` còn hiệu lực gửi qua email cho `user`.
 *
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'purpose' => OtpPurpose::VerifyAccount,
            'channel' => 'email',
            'destination' => fake()->safeEmail(),
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes((int) config('auth.otp.ttl_minutes')),
        ];
    }

    public function withCode(string $code): static
    {
        return $this->state(fn () => ['code_hash' => Hash::make($code)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
