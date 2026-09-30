<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /admin/teachers` (US-009, api-contract §2.5 — "Chỉ `id`, `name`
 * (không email/SĐT)"). Dùng để điền `<MultiSelect>` "Giáo viên phụ trách"
 * khi tạo/sửa khóa học.
 *
 * @property-read User $resource
 *
 * @mixin User
 */
class TeacherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
