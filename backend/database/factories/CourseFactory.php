<?php

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = 'Toán '.fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(100, 999999),
            'short_description' => fake()->sentence(),
            'description' => '<p>'.fake()->paragraph().'</p>',
            'grade_level' => fake()->numberBetween(6, 12),
            'price' => 0,
            'status' => CourseStatus::Draft,
            'enrollments_count' => 0,
            'created_by' => User::factory()->teacher(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Published, 'published_at' => now()]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Unpublished, 'published_at' => now()->subDay()]);
    }

    public function paid(int $price = 299000): static
    {
        return $this->state(fn () => ['price' => $price]);
    }
}
