<?php

namespace App\Services\Cart;

/**
 * 1 dòng đầu vào của `PricingCalculator`: giá gốc (VND, số nguyên) và có nằm
 * trong phạm vi áp dụng của mã hay không.
 */
final readonly class PricingLine
{
    public function __construct(
        public int $courseId,
        public int $unitPrice,
        public bool $eligible,
    ) {}
}
