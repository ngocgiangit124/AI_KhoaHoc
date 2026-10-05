<?php

namespace App\Http\Resources\Quiz;

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Lượt ĐANG LÀM. Dựng tường minh từng trường (whitelist): TUYỆT ĐỐI không có `is_correct`, `explanation`,
 * `result` (I3). Có `server_now`/`expires_at`/`remaining_seconds` để FE hiển thị đồng hồ theo server.
 *
 * @mixin QuizAttempt
 */
class AttemptInProgressResource extends JsonResource
{
    /**
     * @param  Collection<int, QuizQuestion>  $questions
     */
    public function __construct(QuizAttempt $attempt, private readonly Collection $questions)
    {
        parent::__construct($attempt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = now();

        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'status' => 'in_progress',
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'server_now' => $now->toIso8601String(),
            'remaining_seconds' => $this->expires_at === null ? null : max(0, (int) $now->diffInSeconds($this->expires_at, false)),
            'total_questions' => $this->total_questions,
            // {"<question_id>": option_id}; luôn là object (kể cả rỗng).
            'answers' => (object) $this->answers,
            'questions' => $this->questions->map(fn (QuizQuestion $q): array => [
                'id' => $q->id,
                'position' => $q->position,
                'content' => $q->content,
                'options' => $q->options->map(fn ($o): array => [
                    'id' => $o->id,
                    'position' => $o->position,
                    'content' => $o->content,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
