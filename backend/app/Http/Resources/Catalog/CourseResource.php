<?php

namespace App\Http\Resources\Catalog;

use App\Models\Course;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /courses/{slug}` (US-003) — object phẳng (api-contract §1.4). Outline
 * KHÔNG chứa URL/ID video (S13, xem `LessonOutlineResource`). `viewer_state`
 * KHÔNG nằm ở đây — tách riêng `GET /courses/{slug}/viewer-state` để endpoint
 * này cache được (S16, api-contract §2.1).
 *
 * `description` đã sanitize theo Purifier profile `course_description` LÚC
 * GHI (`CourseService` — T08); sanitize LẠI LÚC ĐỌC ở đây (S8, api-contract
 * §4 — "sanitize khi ghi và khi đọc"), phòng dữ liệu cũ/ghi trực tiếp DB chưa
 * qua Service. Việc gọi lại `HtmlSanitizer` 2 lần (ghi + đọc) là idempotent —
 * sanitize HTML đã sạch cho ra chính nó.
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
            'description' => app(HtmlSanitizer::class)->sanitize($this->description),
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
