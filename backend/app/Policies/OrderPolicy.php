<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Đơn hàng (US-022). Học sinh chỉ xem/huỷ đơn của chính mình. Route phía học sinh tra đơn theo `code` VÀ `user_id` nên người
 * khác nhận 404 (không lộ tồn tại); Policy là lớp phòng thủ thứ hai. Quyền Quản trị: `viewAny`/`refund` (T24-V1); approve/cancelAsStaff/addNote thêm ở T39. Mọi quyền Quản trị = `isStaff()` (admin, quản lý
 * trang); giáo viên bị từ chối. Truyền `Order::class` (không cần bản ghi) để quyền kiểm TRƯỚC khi tìm đơn/validate.
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

    /** Xem danh sách/chi tiết/đếm chờ duyệt đơn. */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /** Hoàn tiền (US-010). `$order` có thể là tên lớp: quyền không phụ thuộc bản ghi. */
    public function refund(User $user, mixed $order = null): bool
    {
        return $user->isStaff();
    }
}
