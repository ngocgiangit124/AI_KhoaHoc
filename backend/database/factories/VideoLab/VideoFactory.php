<?php

namespace Database\Factories\VideoLab;

use App\VideoLab\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guid' => (string) Str::uuid(),
            'library_id' => (string) config('video.library_id', 'default'),
            'title' => 'lesson-1',
            'status' => Video::CREATED,
            'max_bytes' => 2048 * 1024 * 1024,
            'upload_offset' => 0,
            'upload_expires_at' => now()->addHours(6),
        ];
    }

    public function finished(int $lengthSeconds = 120): static
    {
        return $this->state(fn () => ['status' => Video::FINISHED, 'length_seconds' => $lengthSeconds, 'finished_at' => now()]);
    }
}
