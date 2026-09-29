<?php

namespace Database\Factories;

use App\Enums\VideoAssetStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoAsset>
 */
class VideoAssetFactory extends Factory
{
    protected $model = VideoAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'internal',
            'provider_library_id' => (string) fake()->numberBetween(1, 100),
            'provider_video_id' => (string) Str::uuid(),
            'status' => VideoAssetStatus::Ready,
            'duration_seconds' => fake()->numberBetween(60, 1800),
            'original_filename' => fake()->word().'.mp4',
            'size_bytes' => fake()->numberBetween(1_000_000, 500_000_000),
            'lesson_id' => Lesson::factory(),
            'declared_size_bytes' => fake()->numberBetween(1_000_000, 500_000_000),
            'created_by' => User::factory()->admin(),
        ];
    }
}
