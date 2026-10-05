<?php

namespace Database\Factories;

use App\Enums\SubjectStatus;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Chuyên đề '.fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999999),
            'status' => SubjectStatus::Active,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => SubjectStatus::Hidden]);
    }
}
