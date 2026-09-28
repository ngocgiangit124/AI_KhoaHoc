<?php

namespace App\Http\Resources\Catalog;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Giáo viên phụ trách hiển thị công khai (US-003 AC6) — CHỈ `id`, `name`,
 * `bio`. KHÔNG BAO GIỜ `email`/`phone` (không phải màn quản trị).
 *
 * @property-read User $resource
 *
 * @mixin User
 */
class CourseTeacherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bio' => $this->bio,
            'avatar_path' => $this->avatar_path,
        ];
    }
}
