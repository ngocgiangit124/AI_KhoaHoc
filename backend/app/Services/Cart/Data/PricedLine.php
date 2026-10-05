<?php

namespace App\Services\Cart\Data;

/** Một dòng đã tính giá: `finalAmount = unitPrice - discountAmount`. */
final readonly class PricedLine
{
    public function __construct(
        public int $courseId,
        public int $unitPrice,
        public int $discountAmount,
        public int $finalAmount,
    ) {}
}
