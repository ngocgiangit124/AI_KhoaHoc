<?php

namespace Database\Factories;

use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state — học sinh trưởng thành (>= ngưỡng đồng
     * ý phụ huynh), chưa xác thực email/SĐT (giống lúc mới đăng ký thật).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->unique()->numerify('########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'grade_level' => fake()->numberBetween(6, 12),
            'date_of_birth' => fake()->dateTimeBetween('-19 years', '-18 years')->format('Y-m-d'),
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'parent_consent_status' => ParentConsentStatus::NotRequired,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Đã xác thực cả email lẫn SĐT (US-001 điều kiện `account.verified`).
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
            'grade_level' => null,
            'date_of_birth' => null,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    public function pageManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::PageManager,
            'grade_level' => null,
            'date_of_birth' => null,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Teacher,
            'grade_level' => null,
            'date_of_birth' => null,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Student,
            'grade_level' => fake()->numberBetween(6, 12),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Locked,
        ]);
    }

    /**
     * Học sinh dưới ngưỡng tuổi cần đồng ý của phụ huynh (US-001, US-017;
     * `privacy.parent_consent_age`, mặc định 18).
     */
    public function minor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Student,
            'date_of_birth' => fake()->dateTimeBetween('-17 years', '-11 years')->format('Y-m-d'),
            'parent_phone' => '09'.fake()->numerify('########'),
            'parent_email' => fake()->safeEmail(),
            'parent_consent_status' => ParentConsentStatus::Pending,
        ]);
    }
}
