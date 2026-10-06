<?php

namespace App\Http\Resources;

use App\Services\Cart\Data\CartSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Xem trước checkout (api-contract §2.3): cùng shape giỏ nhưng tách `items` (hợp lệ) và `removed_items`
 * (không còn khả dụng — bị loại khỏi đơn, US-005 AC4) + `requires_payment`/`can_checkout`.
 *
 * @property CartSnapshot $resource
 */
class CheckoutPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $cart */
        $cart = (new CartResource($this->resource))->toArray($request);

        $items = [];
        $removed = [];
        foreach ($cart['items'] as $row) {
            $row['unavailable'] ? $removed[] = $row : $items[] = $row;
        }

        return [
            'items' => $items,
            'removed_items' => $removed,
            'coupon' => $cart['coupon'],
            'pricing' => $cart['pricing'],
            'notices' => $cart['notices'],
            'can_checkout' => $items !== [],
            'requires_payment' => $items !== [] && $cart['pricing']['total'] > 0,
        ];
    }
}
