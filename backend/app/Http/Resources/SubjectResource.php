<?php

namespace App\Http\Resources;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chuyên đề cho trang quản trị. `courses_count` (số khóa đang gán, để UI biết có xoá được không) chỉ trả
 * cho staff và chỉ khi controller đã `withCount('courses')`.
 *
 * @mixin Subject
 */
class SubjectResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'courses_count' => $this->when(
                $user?->isStaff() === true && isset($this->courses_count),
                fn () => (int) $this->courses_count,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
