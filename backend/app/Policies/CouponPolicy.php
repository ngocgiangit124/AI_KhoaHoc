<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;

/**
 * US-013 §Phân quyền — chỉ Admin/Quản lý trang (`User::isStaff()`) được xem/
 * tạo/sửa/vô hiệu hoá/xoá mã giảm giá; Giáo Viên và Học Sinh không có quyền
 * nào (middleware `role:admin,quan_ly_trang,giao_vien` trên route admin-api
 * đã chặn Học Sinh ở lớp thô — api-contract §1.3, Giáo Viên bị chặn ở Policy).
 */
class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $user->isStaff();
    }

    public function deactivate(User $user, Coupon $coupon): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $user->isStaff();
    }
}
