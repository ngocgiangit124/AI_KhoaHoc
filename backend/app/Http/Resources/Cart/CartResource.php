<?php

namespace App\Http\Resources\Cart;

use App\Services\Cart\CartView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Giỏ hàng (api-contract §2.3): `items` (kèm cờ `unavailable`), `coupon`,
 * `pricing`, `notices[]`. Không lộ ID giỏ/người dùng, `used_count`, `max_uses`
 * hay phạm vi của mã (chỉ những gì học sinh cần để hiển thị).
 *
 * Khóa `unavailable`: `discount_amount`/`final_amount` = null (không tính vào
 * tổng). `pricing`: VND, số nguyên.
 *
 * @property-read CartView $resource
 *
 * @mixin CartView
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        $items = [];

        foreach ($view->items as $item) {
            $course = $item->course;
            $unavailable = $view->isUnavailable($item->course_id);
            $priced = $unavailable ? null : $view->pricing->line($item->course_id);

            $items[] = [
                'course_id' => $item->course_id,
                'title' => $course->title,
                'slug' => $course->slug,
                'thumbnail_path' => $course->thumbnail_path,
                'price' => (int) $course->price,
                'unavailable' => $unavailable,
                'discount_amount' => $priced?->discountAmount,
                'final_amount' => $priced?->finalAmount,
                'added_at' => $item->created_at?->toIso8601String(),
            ];
        }

        return [
            'items' => $items,
            'count' => count($items),
            'coupon' => $view->coupon === null ? null : [
                'code' => $view->coupon->code,
                'discount_type' => $view->coupon->discount_type->value,
                'discount_value' => $view->coupon->discount_value,
            ],
            'pricing' => $view->pricing->totals(),
            'notices' => $view->notices,
        ];
    }
}
