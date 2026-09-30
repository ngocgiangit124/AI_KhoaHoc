<?php

namespace App\Enums;

/**
 * `coupons.discount_type` (US-013, data-model §3.5).
 */
enum CouponDiscountType: string
{
    case Percent = 'percent';
    case FixedAmount = 'fixed_amount';
}
