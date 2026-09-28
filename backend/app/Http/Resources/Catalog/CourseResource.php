<?php

namespace App\Http\Resources\Catalog;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /courses/{slug}` (US-003) — object phẳng (api-contract §1.4). Outline
 * KHÔNG chứa URL/ID video (S13, xem `LessonOutlineResource`). `viewer_state`
 * KHÔNG nằm ở đây — tách riêng `GET /courses/{slug}/viewer-state` để endpoint
 * này cache được (S16, api-contract §2.1).
 *
 * `description` đã sanitize theo Purifier profile `course_description` LÚC
 * GHI (T08 — HtmlSanitizer chưa hiện thực ở T07/T10); TODO(T08): sanitize lại
 * ở đây LÚC ĐỌC (S8, api-contract §4 — "sanitize khi ghi và khi đọc").
 *
 * @property-read Course $resource
 *
 * @mixin Course
 */
class CourseResource extends JsonResource
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
            'description' => $this->description,
            'grade_level' => $this->grade_level,
            'price' => $this->price,
            'thumbnail_path' => $this->thumbnail_path,
            'enrollments_count' => $this->enrollments_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'teachers' => CourseTeacherResource::collection($this->whenLoaded('teachers')),
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            'outline' => ChapterOutlineResource::collection(
                $this->whenLoaded('chapters', fn () => $this->chapters->sortBy('position')->values())
            ),
        ];
    }
}
