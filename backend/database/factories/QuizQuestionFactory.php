<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizQuestion>
 */
class QuizQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'content' => 'Giải phương trình $x^2 = '.fake()->numberBetween(1, 99).'$',
            'explanation' => 'Lời giải '.fake()->words(4, true),
            'position' => fake()->numberBetween(1, 50),
            'replaced_by_id' => null,
        ];
    }

    /** Kèm đúng 4 lựa chọn A–D, đáp án đúng ở vị trí $correct (1–4). */
    public function withOptions(int $correct = 1): static
    {
        return $this->afterCreating(function (QuizQuestion $question) use ($correct): void {
            foreach (range(1, 4) as $pos) {
                QuizOption::factory()->for($question, 'question')->create([
                    'position' => $pos,
                    'is_correct' => $pos === $correct,
                ]);
            }
        });
    }
}
