<?php

namespace App\Services\Cart\Data;

/**
 * Kết quả tính giá (VND, số nguyên). `total = subtotal - discount ≥ 0`; `discount = Σ lines.discountAmount`.
 */
final readonly class Pricing
{
    /**
     * @param  list<PricedLine>  $lines  Cùng thứ tự đầu vào.
     */
    public function __construct(
        public int $subtotal,
        public int $discount,
        public int $total,
        public array $lines,
    ) {}

    public function line(int $courseId): ?PricedLine
    {
        foreach ($this->lines as $line) {
            if ($line->courseId === $courseId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return array{subtotal: int, discount: int, total: int}
     */
    public function toArray(): array
    {
        return ['subtotal' => $this->subtotal, 'discount' => $this->discount, 'total' => $this->total];
    }
}
