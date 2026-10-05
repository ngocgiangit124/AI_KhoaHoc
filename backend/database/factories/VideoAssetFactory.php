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
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'internal',
            'provider_library_id' => 'lib-1',
            'provider_video_id' => (string) Str::uuid(),
            'status' => VideoAssetStatus::Created,
            'lesson_id' => Lesson::factory(),
            'declared_size_bytes' => 10 * 1024 * 1024,
            'created_by' => User::factory()->teacher(),
        ];
    }

    public function ready(int $durationSeconds = 600): static
    {
        return $this->state(fn () => ['status' => VideoAssetStatus::Ready, 'duration_seconds' => $durationSeconds]);
    }
}
