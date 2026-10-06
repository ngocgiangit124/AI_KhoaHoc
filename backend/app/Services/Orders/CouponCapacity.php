<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Sức chứa của mã giảm giá có tính "giữ chỗ" của đơn pending (ADR-001 §6, S18):
 * `used_count + số đơn pending mang mã còn trong hạn giữ chỗ < max_uses`. Đơn hết hạn giữ chỗ/huỷ/thất bại tự
 * nhả chỗ vì không còn được đếm. Chính xác khi caller đang giữ khoá dòng `coupons` (FOR UPDATE) và connection
 * chạy READ COMMITTED (đọc `used_count`/đơn mới nhất đã commit). Thời gian bind Carbon theo múi giờ app (`now()`).
 */
class CouponCapacity
{
    public function hasRoom(Coupon $coupon, ?int $excludeOrderId = null, ?Carbon $now = null): bool
    {
        if ($coupon->max_uses === null) {
            return true;
        }

        return $coupon->used_count + $this->held($coupon, $excludeOrderId, $now) < $coupon->max_uses;
    }

    public function held(Coupon $coupon, ?int $excludeOrderId = null, ?Carbon $now = null): int
    {
        $now ??= now();

        return Order::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('status', OrderStatus::Pending->value)
            ->where('coupon_hold_until', '>', $now)
            ->when($excludeOrderId !== null, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->count();
    }
}
