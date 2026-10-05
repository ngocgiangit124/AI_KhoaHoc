<?php

namespace Database\Factories;

use App\Enums\VideoSource;
use App\Models\Chapter;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * `course_id` luôn lấy theo chương của bài (không để lệch).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'course_id' => fn (array $attributes) => Chapter::query()->findOrFail($attributes['chapter_id'])->course_id,
            'title' => 'Bài '.fake()->words(3, true),
            'position' => fake()->numberBetween(1, 50),
            'video_source' => VideoSource::None,
            'is_preview' => false,
        ];
    }

    public function preview(): static
    {
        return $this->state(fn () => ['is_preview' => true]);
    }

    public function external(string $provider = 'youtube', string $videoId = 'dQw4w9WgXcQ'): static
    {
        return $this->state(fn () => [
            'video_source' => VideoSource::ExternalLink,
            'external_provider' => $provider,
            'external_video_id' => $videoId,
        ]);
    }
}
