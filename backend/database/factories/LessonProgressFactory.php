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
    protected $model = LessonProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lesson = Lesson::factory()->create();

        return [
            'user_id' => User::factory(),
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'watched_seconds' => 0,
            'last_position_seconds' => 0,
            'status' => LessonProgressStatus::InProgress,
            'last_accessed_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LessonProgressStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
