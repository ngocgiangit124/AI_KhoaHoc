<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\PaymentMethods;
use App\Support\VnTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một dòng "Đơn của tôi" (api-contract §2.3.1). Cần `withCount('items')` và `items:id,order_id,course_title` đã eager load.
 *
 * @property Order $resource
 */
class MyOrderListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'code' => $order->code,
            'status' => $order->status->value,
            'status_reason' => $order->status_reason,
            'payment_method' => $order->payment_method,
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'item_titles' => $order->items->sortBy('id')->take(3)->pluck('course_title')->values()->all(),
            'subtotal' => $order->subtotal_amount,
            'discount' => $order->discount_amount,
            'total' => $order->total_amount,
            'created_at' => VnTime::iso($order->created_at),
            'expires_at' => VnTime::iso($order->expires_at),
            'paid_at' => VnTime::iso($order->paid_at),
            'cancelled_at' => VnTime::iso($order->cancelled_at),
            'can_cancel' => $order->payment_method === PaymentMethods::MANUAL && $order->status === OrderStatus::Pending,
            'replaced_by_code' => $order->status_reason === 'superseded' ? $order->getAttribute('replaced_by_code') : null,
        ];
    }
}
