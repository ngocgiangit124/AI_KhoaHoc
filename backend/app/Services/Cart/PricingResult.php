<?php

namespace App\Services\Cart;

/**
 * Kết quả tính giá: `subtotal` (tổng giá gốc mọi dòng), `discount` (tổng giảm),
 * `total` = `subtotal - discount` (>= 0). `lines` cùng thứ tự đầu vào.
 */
final readonly class PricingResult
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public int $subtotal,
        public int $discount,
        public int $total,
        public array $lines,
    ) {}

    /**
     * @return array{subtotal: int, discount: int, total: int}
     */
    public function totals(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
        ];
    }

    public function line(int $courseId): ?PricedLine
    {
        foreach ($this->lines as $line) {
            if ($line->courseId === $courseId) {
                return $line;
            }
        }

        return null;
    }
}
