<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mặc định: lượt ĐANG LÀM, chưa có câu hỏi chốt (question_ids phải truyền vào — CHECK đòi 1–200 câu).
 *
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'quiz_id' => Quiz::factory(),
            'course_id' => fn (array $a) => Quiz::query()->withTrashed()->findOrFail($a['quiz_id'])->course_id,
            'question_ids' => [1],
            'answers' => new \stdClass,
            'result' => null,
            'started_at' => now(),
            'expires_at' => null,
            'submitted_at' => null,
            'auto_submitted' => false,
            'total_questions' => fn (array $a) => count($a['question_ids']),
        ];
    }

    /** Đã nộp, điểm do caller truyền (không chấm lại). */
    public function submitted(int $correct = 0, ?float $score = null): static
    {
        return $this->state(fn (array $a) => [
            'submitted_at' => now(),
            'correct_count' => $correct,
            'score' => $score ?? round($correct / max(1, count($a['question_ids'])) * 10, 2),
            'result' => new \stdClass,
        ]);
    }
}
