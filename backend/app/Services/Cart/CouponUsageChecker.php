<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\User;

/**
 * "Học sinh đã dùng mã này chưa" (US-013 BR3, unique `coupon_usages
 * (coupon_id, user_id)` ở tầng DB — T18).
 */
interface CouponUsageChecker
{
    public function hasUsed(Coupon $coupon, User $user): bool;
}
