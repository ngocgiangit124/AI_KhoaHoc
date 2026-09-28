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
    protected $model = Lesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // course_id denormalize từ chapter — tạo Chapter trước (không phải
        // closure lười) để đảm bảo course_id LUÔN khớp đúng course của
        // chapter_id, tránh trạng thái không hợp lệ khi test không override
        // cả 2 khoá cùng lúc.
        $chapter = Chapter::factory()->create();

        return [
            'course_id' => $chapter->course_id,
            'chapter_id' => $chapter->id,
            'title' => ucfirst(fake()->words(4, true)),
            'position' => 1,
            'video_source' => VideoSource::None,
            'duration_seconds' => fake()->numberBetween(120, 900),
            'is_preview' => false,
        ];
    }

    public function preview(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_preview' => true,
        ]);
    }
}
