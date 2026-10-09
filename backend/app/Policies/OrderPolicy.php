<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Đơn hàng (US-022). Học sinh chỉ xem/huỷ đơn của chính mình. Route phía học sinh tra đơn theo `code` VÀ `user_id` nên người
 * khác nhận 404 (không lộ tồn tại); Policy là lớp phòng thủ thứ hai. Quyền Quản trị (viewAny/approve/...) thêm ở T24/T39.
 */
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->isStudent() && (int) $order->user_id === (int) $user->getKey();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }
}
