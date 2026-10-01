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
    protected $model = QuizQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'content' => 'Giá trị của $x^2$ khi $x = '.fake()->numberBetween(1, 9).'$ là?',
            'explanation' => fake()->sentence(),
            'position' => 1,
        ];
    }

    /**
     * Sinh đủ 4 lựa chọn A–D, đáp án đúng ở vị trí `$correctPosition` (1–4).
     */
    public function withOptions(int $correctPosition = 1): static
    {
        return $this->afterCreating(function (QuizQuestion $question) use ($correctPosition): void {
            foreach (range(1, 4) as $position) {
                QuizOption::factory()->create([
                    'question_id' => $question->id,
                    'position' => $position,
                    'is_correct' => $position === $correctPosition,
                ]);
            }
        });
    }
}
