<?php

namespace App\Http\Resources\Catalog;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Content\HtmlSanitizer;
use App\Support\StaticUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chi tiết công khai (cache được: không chứa dữ liệu theo người xem). Outline chỉ có tên bài, thời lượng,
 * `is_preview` — KHÔNG có URL/ID video/asset/nguồn video (S13). Cần eager load
 * `subjects`, `teachers`, `chapters.lessons`.
 *
 * `description` được sanitize khi ghi (T08) và lọc lại khi trả ra bằng HtmlSanitizer (api-contract §4).
 *
 * @mixin Course
 */
class CourseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lessonsCount = 0;
        $duration = 0;
        $hasPreview = false;

        $outline = $this->chapters->map(function (Chapter $chapter) use (&$lessonsCount, &$duration, &$hasPreview): array {
            $lessons = $chapter->lessons->map(function (Lesson $lesson) use (&$lessonsCount, &$duration, &$hasPreview): array {
                $lessonsCount++;
                $duration += (int) $lesson->duration_seconds;
                $hasPreview = $hasPreview || $lesson->is_preview;

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'position' => $lesson->position,
                    'duration_seconds' => $lesson->duration_seconds,
                    'is_preview' => $lesson->is_preview,
                ];
            })->values()->all();

            return ['id' => $chapter->id, 'title' => $chapter->title, 'position' => $chapter->position, 'lessons' => $lessons];
        })->values()->all();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => app(HtmlSanitizer::class)->clean($this->description),
            'grade_level' => $this->grade_level,
            'price' => $this->price,
            'is_free' => $this->price === 0,
            'thumbnail_url' => StaticUrl::to($this->thumbnail_path),
            'enrollments_count' => $this->enrollments_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'subjects' => PublicSubjectResource::collection($this->subjects)->resolve($request),
            'teachers' => $this->teachers->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'bio' => $t->bio,
                'avatar_url' => StaticUrl::to($t->avatar_path),
            ])->values()->all(),
            'lessons_count' => $lessonsCount,
            'total_duration_seconds' => $duration,
            'has_preview' => $hasPreview,
            'outline' => $outline,
        ];
    }
}
