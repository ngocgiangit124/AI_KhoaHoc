<?php

namespace App\Services\Cart;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TODO(T18) — BẮT BUỘC khi T18 tạo bảng `coupon_usages` (unique `(coupon_id,
 * user_id)`): (1) XOÁ `tableExists()`/nhánh `hasTable` (mỗi request áp mã đang
 * tốn thêm 1 truy vấn information_schema và chỉ là cầu nối T16 → T18); (2) đổi
 * test `CouponUsageCheckerTest` sang bảng thật (hiện dùng bảng TEMPORARY ép
 * `tableExists() = true`). Đến lúc đó chưa ai có thể "đã dùng" mã nên trả
 * `false` khi thiếu bảng.
 */
class DatabaseCouponUsageChecker implements CouponUsageChecker
{
    private ?bool $tableExists = null;

    public function hasUsed(Coupon $coupon, User $user): bool
    {
        $this->tableExists ??= $this->tableExists();

        if (! $this->tableExists) {
            return false;
        }

        return DB::table('coupon_usages')
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    protected function tableExists(): bool
    {
        return Schema::hasTable('coupon_usages');
    }
}
