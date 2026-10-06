<?php

namespace Database\Factories;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mặc định: hồ sơ rỗng (giáo viên mới, chưa đồng ý). Dùng state `complete()` / `consented()` cho hồ sơ đầy đủ.
 *
 * @extends Factory<TeacherProfile>
 */
class TeacherProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->teacher(),
            'headline' => null,
            'bio' => null,
            'avatar_path' => null,
            'public_consent_at' => null,
            'public_consent_version' => null,
            'public_consent_withdrawn_at' => null,
            'show_on_homepage' => false,
            'homepage_order' => null,
        ];
    }

    /** Có ảnh, headline, bio (chưa đồng ý). */
    public function withContent(): static
    {
        return $this->state(fn () => [
            'headline' => 'Giáo viên Toán THPT',
            'bio' => "10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh.",
            'avatar_path' => (string) fake()->uuid().'.webp',
        ]);
    }

    /** Đang đồng ý công khai theo phiên bản câu chữ hiện hành. */
    public function consented(): static
    {
        return $this->state(fn () => [
            'public_consent_at' => now(),
            'public_consent_version' => (string) config('teacher_profile.consent_version'),
        ]);
    }

    public function onHomepage(?int $order = null): static
    {
        return $this->state(fn () => ['show_on_homepage' => true, 'homepage_order' => $order]);
    }
}
