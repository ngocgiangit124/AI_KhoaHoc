<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;

/**
 * US-013 (Phân quyền): chỉ admin và quản lý trang (permission `manage_coupons`); giáo viên/học sinh không có quyền.
 */
class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $user->isStaff();
    }
}
