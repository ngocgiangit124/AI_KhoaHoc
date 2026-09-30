<?php

namespace App\Http\Resources\Admin;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api-contract §2.5 (US-013). `course_ids`/`subject_ids` chỉ có giá trị THẬT
 * khi controller đã `with(['courses', 'subjects'])` — nếu chưa eager-load,
 * `whenLoaded()` trả `[]` thay vì lười tải (tránh N+1 âm thầm).
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
            'used_count' => $this->used_count,
            'valid_from' => $this->valid_from->toAtomString(),
            'valid_until' => $this->valid_until?->toAtomString(),
            'status' => $this->status->value,
            'is_restricted' => $this->is_restricted,
            'course_ids' => $this->whenLoaded(
                'courses',
                fn () => $this->courses->pluck('id')->values()->all(),
                []
            ),
            'subject_ids' => $this->whenLoaded(
                'subjects',
                fn () => $this->subjects->pluck('id')->values()->all(),
                []
            ),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
