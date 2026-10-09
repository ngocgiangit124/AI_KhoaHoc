<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\PaymentMethods;
use App\Services\Orders\PiiMasker;
use App\Support\VnTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một dòng danh sách đơn quản trị (api-contract §2.5.1). Cần: `items_count` (withCount), `first_item_title` (subquery), quan hệ
 * `user` (cột trong `PiiMasker::USER_COLUMNS`) và `confirmedBy:id,name` đã nạp sẵn. Email/SĐT LUÔN che.
 *
 * @property Order $resource
 */
class AdminOrderListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $soonHours = (int) config('orders.manual.expiring_soon_hours', 12);

        return [
            'code' => $order->code,
            'status' => $order->status->value,
            'status_reason' => $order->status_reason,
            'payment_method' => $order->payment_method,
            'items_count' => (int) $order->getAttribute('items_count'),
            'first_item_title' => $order->getAttribute('first_item_title'),
            'subtotal' => $order->subtotal_amount,
            'discount' => $order->discount_amount,
            'total' => $order->total_amount,
            'needs_review' => $order->needs_review,
            'created_at' => VnTime::iso($order->created_at),
            'expires_at' => VnTime::iso($order->expires_at),
            // "Sắp hết hạn" chỉ có nghĩa với đơn manual chờ duyệt (đơn cổng tự hết hạn sau 12 giờ, không cần người duyệt).
            'expiring_soon' => $order->payment_method === PaymentMethods::MANUAL
                && $order->status === OrderStatus::Pending
                && $order->expires_at->lt(now()->addHours($soonHours)),
            'paid_at' => VnTime::iso($order->paid_at),
            'cancelled_at' => VnTime::iso($order->cancelled_at),
            'confirmed_by' => $order->confirmedBy !== null ? ['id' => $order->confirmedBy->getKey(), 'name' => $order->confirmedBy->name] : null,
            'student' => PiiMasker::masked($order->user, (int) $order->user_id),
        ];
    }
}
