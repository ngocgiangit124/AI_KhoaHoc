<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mặc định gắn vào 1 bài (tạo chương + bài trước để `course_id` luôn khớp).
 * Dùng `->forChapter($chapter)` / `->forLesson($lesson)` để chọn nơi gắn.
 *
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lesson = Lesson::factory()->create();

        return [
            'course_id' => $lesson->course_id,
            'chapter_id' => null,
            'lesson_id' => $lesson->id,
            'title' => ucfirst(fake()->words(4, true)),
            'time_limit_minutes' => null,
            'position' => 1,
        ];
    }

    public function forLesson(Lesson $lesson): static
    {
        return $this->state(fn (array $attributes) => [
            'course_id' => $lesson->course_id,
            'chapter_id' => null,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function forChapter(Chapter $chapter): static
    {
        return $this->state(fn (array $attributes) => [
            'course_id' => $chapter->course_id,
            'chapter_id' => $chapter->id,
            'lesson_id' => null,
        ]);
    }

    public function timed(int $minutes = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'time_limit_minutes' => $minutes,
        ]);
    }
}
