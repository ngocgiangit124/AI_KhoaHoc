<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use App\Services\Cart\Data\CartSnapshot;
use App\Services\Content\ImageUploadService;
use App\Services\Orders\PendingOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Giỏ hàng (api-contract §2.3): `{items, coupon, pricing, notices}`, phẳng. Dòng không còn khả dụng có
 * `unavailable: true`, không tính vào `pricing` và `discount_amount`/`final_amount` = null.
 *
 * @property CartSnapshot $resource
 */
class CartResource extends JsonResource
{
    private bool $withPendingOrder = false;

    /** GET /cart (T16-1): thêm `pending_order` (cùng shape checkout preview). */
    public function withPendingOrder(): static
    {
        $this->withPendingOrder = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;
        $images = app(ImageUploadService::class);

        $rows = [];
        foreach ($snapshot->items as $item) {
            $line = $snapshot->pricing->line($item->course_id);
            $rows[] = $this->row($item, $images, false, $line?->discountAmount, $line?->finalAmount);
        }
        foreach ($snapshot->unavailableItems as $item) {
            $rows[] = $this->row($item, $images, true, null, null);
        }
        usort($rows, fn (array $a, array $b) => $b['_id'] <=> $a['_id']);
        $rows = array_map(function (array $r): array {
            unset($r['_id']);

            return $r;
        }, $rows);

        $coupon = $snapshot->coupon;

        $out = [
            'items' => $rows,
            'coupon' => $coupon === null ? null : [
                'code' => $coupon->code,
                'name' => $coupon->name,
                'discount_type' => $coupon->discount_type->value,
                'discount_value' => $coupon->discount_value,
                'discount_amount' => $snapshot->pricing->discount,
                'applies_to_course_ids' => $snapshot->evaluation->eligibleCourseIds ?? [],
            ],
            'pricing' => $snapshot->pricing->toArray(),
            'notices' => $snapshot->notices,
        ];

        if ($this->withPendingOrder) {
            $out['pending_order'] = app(PendingOrderPresenter::class)->forUser($request->user()?->getAuthIdentifier());
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CartItem $item, ImageUploadService $images, bool $unavailable, ?int $discount, ?int $final): array
    {
        $course = $item->course;

        return [
            '_id' => $item->id,
            'course_id' => $item->course_id,
            'title' => $course->title,
            'slug' => $course->slug,
            'grade_level' => $course->grade_level,
            'thumbnail_url' => $images->url($course->thumbnail_path),
            'price' => $course->price,
            'unavailable' => $unavailable,
            'discount_amount' => $discount,
            'final_amount' => $final,
            'added_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
