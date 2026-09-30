<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TODO(T18): bảng `coupon_usages` do T18 tạo (cần FK `orders`). Cho tới lúc đó
 * chưa ai có thể "đã dùng" mã nên trả `false`; khi T18 có bảng, XOÁ nhánh
 * `hasTable` (chỉ là cầu nối T16 → T18, tránh phụ thuộc migration chưa có).
 */
class DatabaseCouponUsageChecker implements CouponUsageChecker
{
    private ?bool $tableExists = null;

    public function hasUsed(Coupon $coupon, User $user): bool
    {
        $this->tableExists ??= Schema::hasTable('coupon_usages');

        if (! $this->tableExists) {
            return false;
        }

        return DB::table('coupon_usages')
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
