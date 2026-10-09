<?php

namespace App\Http\Resources;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quiz cho trang quản trị (staff/giáo viên được gán, KHÔNG dành cho học sinh). `questions` chỉ có khi đã nạp
 * quan hệ; câu hỏi trong đó có đáp án đúng — resource học sinh (T22) phải là class khác.
 *
 * @mixin Quiz
 */
class QuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'chapter_id' => $this->chapter_id,
            'lesson_id' => $this->lesson_id,
            'parent_type' => $this->lesson_id !== null ? 'lesson' : 'chapter',
            'parent_title' => $this->when(
                $this->relationLoaded('lesson') || $this->relationLoaded('chapter'),
                fn () => $this->lesson_id !== null ? $this->lesson?->title : $this->chapter?->title,
            ),
            'title' => $this->title,
            'time_limit_minutes' => $this->time_limit_minutes,
            'position' => $this->position,
            'has_attempts' => $this->when(array_key_exists('has_attempts', $this->getAttributes()), fn () => (bool) $this->getAttribute('has_attempts')),
            'questions_count' => $this->whenCounted('questions'),
            'questions' => $this->whenLoaded('questions', fn () => QuizQuestionResource::collection($this->questions)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
