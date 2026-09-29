<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    /**
     * Mã rõ dùng trong test (KHÔNG dùng ngoài factory — sản phẩm không bao giờ
     * lưu/ghi log mã rõ, xem `OtpCode::code_hash`).
     */
    public const PLAIN_CODE = '123456';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'purpose' => OtpPurpose::VerifyAccount,
            'channel' => 'email',
            // T04 security review M1 — `verify()` giờ so `destination` với
            // liên hệ HIỆN TẠI của user (`email`/`phone` tuỳ `channel`), nên
            // mặc định factory phải KHỚP với user liên kết (qua `->for($user)`
            // hoặc `user_id` tường minh), thay vì email ngẫu nhiên không liên
            // quan — nếu không mọi test `verify()` mặc định sẽ luôn thất bại.
            // Test cố tình kiểm tra "destination không khớp" phải override
            // tường minh (xem `tests/Feature/T04/OtpServiceTest.php`).
            'destination' => function (array $attributes) {
                $user = isset($attributes['user_id']) ? User::find($attributes['user_id']) : null;

                return $user !== null ? $user->email : fake()->safeEmail();
            },
            'code_hash' => Hash::make(self::PLAIN_CODE),
            'expires_at' => now()->addMinutes((int) config('auth.otp.ttl_minutes')),
            'consumed_at' => null,
            'invalidated_at' => null,
            'attempts' => 0,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes) => [
            'consumed_at' => now(),
        ]);
    }

    public function invalidated(): static
    {
        return $this->state(fn (array $attributes) => [
            'invalidated_at' => now(),
        ]);
    }

    public function attemptsExhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'attempts' => (int) config('auth.otp.max_attempts_per_code'),
        ]);
    }
}
