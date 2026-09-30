<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Catalog\CourseTeacherResource;
use App\Http\Resources\Catalog\SubjectResource;
use App\Models\Course;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET/POST/PUT /admin/courses[/{course}]` (US-009, api-contract §2.5).
 * Dùng chung cho cả danh sách và chi tiết (khác `Catalog\CourseResource`
 * công khai — có thêm `status`/`manual_order`/timestamps, KHÔNG lọc theo
 * `published()`).
 *
 * `teachers`/`subjects` dùng lại Resource công khai (`Catalog\...`) — không
 * chứa PII (email/SĐT giáo viên KHÔNG được trả, kể cả ở đây — api-contract
 * §2.5 "GET /admin/teachers ... Chỉ id, name (không email/SĐT)").
 *
 * `description` đã sanitize LÚC GHI (`CourseService` — `HtmlSanitizer`);
 * sanitize LẠI LÚC ĐỌC ở đây (S8, api-contract §4 "sanitize khi ghi và khi
 * đọc") — phòng dữ liệu cũ/ghi trực tiếp DB chưa qua Service.
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
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'manual_order' => $this->manual_order,
            'enrollments_count' => $this->enrollments_count,
            'created_by' => $this->created_by,
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            'teachers' => CourseTeacherResource::collection($this->whenLoaded('teachers')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
