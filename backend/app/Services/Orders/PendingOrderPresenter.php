<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\VnTime;

/**
 * Đơn `pending` hiện có của học sinh (mọi phương thức) dạng `pending_order` (api-contract §2.3.1).
 * Dùng chung cho GET /cart (T16-1) và GET /checkout/preview. 1 truy vấn theo index `orders_user_pending_unique`.
 */
class PendingOrderPresenter
{
    /** @return array{code: string, payment_method: string, total: int, created_at: string|null, expires_at: string|null}|null */
    public function forUser(int|string|null $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $order = Order::query()->where('user_id', $userId)->where('status', OrderStatus::Pending->value)->first();

        return $order === null ? null : [
            'code' => $order->code,
            'payment_method' => $order->payment_method,
            'total' => $order->total_amount,
            'created_at' => VnTime::iso($order->created_at),
            'expires_at' => VnTime::iso($order->expires_at),
        ];
    }
}
