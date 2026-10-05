<?php

namespace App\Http\Resources\Quiz;

use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dòng lịch sử: không câu hỏi, không đáp án.
 *
 * @mixin QuizAttempt
 */
class AttemptSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $submitted = $this->isSubmitted();

        return [
            'id' => $this->id,
            'status' => $submitted ? 'submitted' : 'in_progress',
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'auto_submitted' => $this->auto_submitted,
            'total_questions' => $this->total_questions,
            'correct_count' => $this->correct_count,
            'score' => $this->score,
        ];
    }
}
