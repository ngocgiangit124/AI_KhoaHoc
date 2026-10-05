<?php

namespace App\Services\Cart\Data;

use App\Models\Coupon;

/** Mã đã qua kiểm tra: danh sách khóa trong giỏ thuộc phạm vi được giảm (không rỗng). */
final readonly class CouponEvaluation
{
    /**
     * @param  list<int>  $eligibleCourseIds
     */
    public function __construct(
        public Coupon $coupon,
        public array $eligibleCourseIds,
    ) {}
}
