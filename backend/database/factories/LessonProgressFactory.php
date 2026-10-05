<?php

namespace Database\Factories;

use App\Enums\LessonProgressStatus;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'lesson_id' => Lesson::factory(),
            'course_id' => fn (array $attributes) => Lesson::query()->findOrFail($attributes['lesson_id'])->course_id,
            'watched_seconds' => 0,
            'last_position_seconds' => 0,
            'status' => LessonProgressStatus::InProgress,
            'last_accessed_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => LessonProgressStatus::Completed, 'completed_at' => now()]);
    }
}
