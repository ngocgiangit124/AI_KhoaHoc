<?php

namespace App\Enums;

enum CouponDiscountType: string
{
    case Percent = 'percent';
    case FixedAmount = 'fixed_amount';
}
