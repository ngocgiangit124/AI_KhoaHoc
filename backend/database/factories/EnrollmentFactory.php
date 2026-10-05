<?php

namespace Database\Factories;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    /**
     * Mặc định: đã kích hoạt qua mua (đang học).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'course_id' => Course::factory(),
            'status' => EnrollmentStatus::Active,
            'source' => EnrollmentSource::Purchase,
            'activated_at' => now(),
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn () => [
            'status' => EnrollmentStatus::PendingApproval,
            'source' => EnrollmentSource::FreeApproval,
            'requested_at' => now(),
            'activated_at' => null,
        ]);
    }

    public function rejected(string $reason = 'Chưa đủ điều kiện'): static
    {
        return $this->state(fn () => [
            'status' => EnrollmentStatus::Rejected,
            'source' => EnrollmentSource::FreeApproval,
            'requested_at' => now()->subDay(),
            'rejection_reason' => $reason,
            'activated_at' => null,
        ]);
    }

    public function revoked(string $reason = 'refund'): static
    {
        return $this->state(fn () => [
            'status' => EnrollmentStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }
}
