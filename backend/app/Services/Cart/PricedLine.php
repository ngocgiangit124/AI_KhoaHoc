<?php

namespace App\Services\Cart;

/**
 * 1 dòng đã tính giá: `discountAmount` là phần giảm phân bổ cho dòng này
 * (khớp `order_items.discount_amount`, data-model §3.5), `finalAmount` =
 * `unitPrice - discountAmount`.
 */
final readonly class PricedLine
{
    public function __construct(
        public int $courseId,
        public int $unitPrice,
        public int $discountAmount,
        public int $finalAmount,
    ) {}
}
