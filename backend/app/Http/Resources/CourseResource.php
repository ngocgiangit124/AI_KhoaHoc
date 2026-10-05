<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\User;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Http\Request;

/**
 * Chi tiết khóa học quản trị. `description` được lọc lại bằng Purifier khi trả ra (S8). `abilities` chỉ để ẩn/hiện
 * UI (quyền thật do Policy quyết định). `chapters_count`/`lessons_count` chỉ có khi controller đã withCount.
 *
 * @mixin Course
 */
class CourseResource extends CourseListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $isStaff = $user?->isStaff() === true;

        return array_merge(parent::toArray($request), [
            'description' => app(HtmlSanitizer::class)->clean($this->description),
            'chapters_count' => $this->whenCounted('chapters'),
            'lessons_count' => $this->whenCounted('lessons'),
            'abilities' => [
                'update' => $user !== null && $user->can('update', $this->resource),
                'delete' => $isStaff,
                'publish' => $isStaff,
                'manage_teachers' => $isStaff,
                'edit_price' => $isStaff,
                'edit_grade_level' => $isStaff || $this->published_at === null,
            ],
        ]);
    }
}
