<?php

namespace App\Http\Resources;

use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Câu hỏi cho trang quản trị: có `explanation` và `options[].is_correct`. CHỈ dùng ở route admin-api.
 *
 * @mixin QuizQuestion
 */
class QuizQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'content' => $this->content,
            'explanation' => $this->getAttribute('explanation'),
            'position' => $this->position,
            'has_attempts' => $this->when(array_key_exists('has_attempts', $this->getAttributes()), fn () => (bool) $this->getAttribute('has_attempts')),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'id' => $o->id,
                'content' => $o->content,
                'is_correct' => (bool) $o->getAttribute('is_correct'),
                'position' => $o->position,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
