<?php

namespace App\Http\Resources\Catalog;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /courses` (US-002) — mỗi phần tử của danh sách phân trang.
 *
 * @property-read Course $resource
 *
 * @mixin Course
 */
class CourseSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'grade_level' => $this->grade_level,
            'price' => $this->price,
            'thumbnail_path' => $this->thumbnail_path,
            'enrollments_count' => $this->enrollments_count,
            'teachers' => CourseTeacherResource::collection($this->whenLoaded('teachers')),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
