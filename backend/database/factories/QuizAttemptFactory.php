<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lượt làm bài tối thiểu (mặc định ĐÃ NỘP để không đụng unique "1 lượt đang
 * làm/quiz"). `question_ids` bắt buộc ≥ 1 phần tử (CHECK) — truyền qua
 * `->create(['question_ids' => [...]])`, mặc định `[1]`.
 *
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    protected $model = QuizAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quiz = Quiz::factory()->create();

        return [
            'user_id' => User::factory()->student(),
            'quiz_id' => $quiz->id,
            'course_id' => $quiz->course_id,
            'question_ids' => [1],
            'answers' => [],
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(5),
            'total_questions' => 1,
            'correct_count' => 0,
            'score' => 0,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'submitted_at' => null,
            'correct_count' => null,
            'score' => null,
        ]);
    }

    /**
     * @param  list<int>  $questionIds
     */
    public function forQuestions(Quiz $quiz, array $questionIds): static
    {
        return $this->state(fn (array $attributes) => [
            'quiz_id' => $quiz->id,
            'course_id' => $quiz->course_id,
            'question_ids' => $questionIds,
            'total_questions' => count($questionIds),
        ]);
    }
}
