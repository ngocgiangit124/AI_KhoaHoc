<?php

namespace App\Http\Resources\Quiz;

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Kết quả lượt ĐÃ NỘP (BR5): đáp án đã chọn, đáp án đúng, giải thích, đúng/sai từng câu. Chỉ dùng khi
 * `submitted_at` đã có; controller không bao giờ dựng resource này cho lượt đang làm.
 *
 * @mixin QuizAttempt
 */
class AttemptResultResource extends JsonResource
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
        $result = $this->result ?? [];

        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'status' => 'submitted',
            'started_at' => $this->started_at->toIso8601String(),
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'auto_submitted' => $this->auto_submitted,
            'server_now' => now()->toIso8601String(),
            'total_questions' => $this->total_questions,
            'correct_count' => $this->correct_count,
            'unanswered_count' => count(array_filter($result, fn (array $r): bool => $r['selected'] === null)),
            'score' => $this->score,
            'questions' => $this->questions->map(function (QuizQuestion $q) use ($result): array {
                $r = $result[(string) $q->id] ?? ['selected' => null, 'correct' => null, 'ok' => false];

                return [
                    'id' => $q->id,
                    'position' => $q->position,
                    'content' => $q->content,
                    'explanation' => $q->explanation,
                    'selected_option_id' => $r['selected'],
                    'correct_option_id' => $r['correct'],
                    'is_correct' => $r['ok'],
                    'options' => $q->options->map(fn ($o): array => [
                        'id' => $o->id,
                        'position' => $o->position,
                        'content' => $o->content,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
