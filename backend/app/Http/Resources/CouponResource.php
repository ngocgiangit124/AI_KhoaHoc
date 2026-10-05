<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mã giảm giá cho trang quản trị. `courses`/`subjects` (chi tiết phạm vi) chỉ có khi controller đã load quan hệ;
 * danh sách chỉ trả `courses_count`/`subjects_count` (withCount).
 *
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'discount_type' => $this->discount_type->value,
            'discount_value' => $this->discount_value,
            'max_uses' => $this->max_uses,
            'max_uses_per_user' => Coupon::MAX_USES_PER_USER,
            'used_count' => $this->used_count,
            'valid_from' => $this->valid_from->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'status' => $this->status->value,
            'state' => $this->state()->value,
            'is_restricted' => $this->is_restricted,
            'courses_count' => $this->when(isset($this->courses_count), fn () => (int) $this->courses_count),
            'subjects_count' => $this->when(isset($this->subjects_count), fn () => (int) $this->subjects_count),
            'courses' => $this->whenLoaded('courses', fn () => $this->courses
                ->sortBy('id')->values()
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])->all()),
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects
                ->sortBy('id')->values()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all()),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
