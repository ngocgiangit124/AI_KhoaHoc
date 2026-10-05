<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mặc định gắn vào 1 chương mới của khóa (course_id luôn theo chương).
 *
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'lesson_id' => null,
            'course_id' => fn (array $a) => Chapter::query()->findOrFail($a['chapter_id'])->course_id,
            'title' => 'Quiz '.fake()->words(3, true),
            'time_limit_minutes' => null,
            'position' => fake()->numberBetween(1, 20),
        ];
    }

    public function forLesson(?Lesson $lesson = null): static
    {
        return $this->state(function () use ($lesson) {
            $lesson ??= Lesson::factory()->create();

            return ['chapter_id' => null, 'lesson_id' => $lesson->id, 'course_id' => $lesson->course_id];
        });
    }

    public function forCourse(Course $course): static
    {
        return $this->state(fn () => ['chapter_id' => Chapter::factory()->for($course), 'course_id' => $course->id]);
    }
}
