<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\User;
use App\Services\Content\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dòng khóa học trong danh sách quản trị (không có `description`). `subjects`/`teachers` cần eager load.
 * `manual_order` chỉ trả cho staff.
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
        /** @var User|null $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'grade_level' => $this->grade_level,
            'price' => $this->price,
            'thumbnail_url' => app(ImageUploadService::class)->url($this->thumbnail_path),
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'manual_order' => $this->when($user?->isStaff() === true, $this->manual_order),
            'enrollments_count' => $this->enrollments_count,
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'slug' => $s->slug,
            ])->values()->all()),
            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name,
            ])->values()->all()),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
