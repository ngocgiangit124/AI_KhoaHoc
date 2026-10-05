<?php

namespace App\Http\Resources\Catalog;

use App\Models\Course;
use App\Support\StaticUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thẻ khóa học trong danh mục. Controller phải eager load `subjects`, `teachers` (chống N+1).
 *
 * @mixin Course
 */
class CourseListResource extends JsonResource
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
            'is_free' => $this->price === 0,
            'thumbnail_url' => StaticUrl::to($this->thumbnail_path),
            'enrollments_count' => $this->enrollments_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'subjects' => PublicSubjectResource::collection($this->subjects)->resolve($request),
            'teachers' => $this->teachers->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
        ];
    }
}
