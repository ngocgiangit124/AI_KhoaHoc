<?php

namespace App\Http\Resources;

use App\Models\Chapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chương cho trang quản trị. `lessons` chỉ có khi controller đã nạp quan hệ.
 *
 * @mixin Chapter
 */
class ChapterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'position' => $this->position,
            'lessons' => LessonResource::collection($this->whenLoaded('lessons'))->resolve($request),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
