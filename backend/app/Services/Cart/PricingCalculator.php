<?php

namespace App\Services\Cart;

use App\Enums\CouponDiscountType;
use InvalidArgumentException;

/**
 * Hàm THUẦN tính giá dùng chung cho giỏ / checkout / đơn (api-contract §3 "Trách
 * nhiệm dễ đoán sai") — không truy cập DB, không đọc giờ, cùng đầu vào luôn
 * cùng kết quả (để checkout so `expected_total` với giỏ và snapshot vào
 * `order_items`).
 *
 * Quy tắc (US-004 BR7-BR9, review T15 R12 — KHÔNG tin cờ rủi ro của mã, tự chặn):
 * - Chỉ các dòng `eligible` (thuộc phạm vi mã) chịu giảm giá; dòng ngoài phạm
 *   vi giữ nguyên giá gốc (BR9).
 * - `percent`: `floor(eligibleSubtotal * value / 100)` — làm tròn XUỐNG tới
 *   1 VND bằng số nguyên (không dùng float), không bao giờ giảm nhiều hơn phần
 *   trăm khai báo.
 * - `fixed_amount`: `value`.
 * - Cả hai bị chặn trên bởi `eligibleSubtotal` (BR8): tổng thanh toán không
 *   âm, không giảm quá giá thực của phần thuộc phạm vi.
 * - Phân bổ giảm giá về từng dòng theo tỷ lệ giá bằng phương pháp "phần dư lớn
 *   nhất" (largest remainder): tổng phân bổ == tổng giảm chính xác, mỗi dòng
 *   giảm trong [0, giá dòng]. (Không dồn toàn bộ phần dư vào dòng cuối như ghi
 *   chú data-model §3.5, vì cách đó có thể làm dòng cuối giảm QUÁ giá của nó.)
 */
class PricingCalculator
{
    /**
     * @param  list<PricingLine>  $lines
     * @param  int|null  $discountValue  null = không áp mã
     *
     * @throws InvalidArgumentException giá âm, giá trị giảm không hợp lệ hoặc tổng vượt giới hạn tính toán
     */
    public function calculate(array $lines, ?CouponDiscountType $discountType = null, ?int $discountValue = null): PricingResult
    {
        $subtotal = 0;
        $eligibleSubtotal = 0;
        $maxEligiblePrice = 0;

        foreach ($lines as $line) {
            if ($line->unitPrice < 0) {
                throw new InvalidArgumentException('Giá khóa học không được âm.');
            }

            $subtotal += $line->unitPrice;

            if ($line->eligible) {
                $eligibleSubtotal += $line->unitPrice;
                $maxEligiblePrice = max($maxEligiblePrice, $line->unitPrice);
            }
        }

        $discount = $this->totalDiscount($eligibleSubtotal, $discountType, $discountValue);

        // Phép nhân phân bổ `discount * unitPrice` (và `subtotal * percent`) phải
        // vừa số nguyên 64-bit. Giá khóa <= 50.000.000 (validate quản trị) nên
        // chỉ vượt khi hàng nghìn khóa giá tối đa nằm cùng 1 mã — `CartService`
        // bắt lỗi này thành kết quả có kiểm soát (không 500).
        if ($maxEligiblePrice > 0 && $discount > intdiv(PHP_INT_MAX, $maxEligiblePrice)) {
            throw new InvalidArgumentException('Tổng giá vượt giới hạn tính toán.');
        }
        $allocation = $this->allocate($lines, $eligibleSubtotal, $discount);

        $priced = [];

        foreach ($lines as $i => $line) {
            $lineDiscount = $allocation[$i];
            $priced[] = new PricedLine($line->courseId, $line->unitPrice, $lineDiscount, $line->unitPrice - $lineDiscount);
        }

        return new PricingResult($subtotal, $discount, $subtotal - $discount, $priced);
    }

    private function totalDiscount(int $eligibleSubtotal, ?CouponDiscountType $type, ?int $value): int
    {
        if ($type === null || $value === null || $eligibleSubtotal === 0) {
            return 0;
        }

        if ($value < 0) {
            throw new InvalidArgumentException('Giá trị giảm không được âm.');
        }

        if ($eligibleSubtotal > intdiv(PHP_INT_MAX, 100)) {
            throw new InvalidArgumentException('Tổng giá vượt giới hạn tính toán.');
        }

        $raw = match ($type) {
            CouponDiscountType::Percent => intdiv($eligibleSubtotal * min($value, 100), 100),
            CouponDiscountType::FixedAmount => $value,
        };

        return min($raw, $eligibleSubtotal);
    }

    /**
     * @param  list<PricingLine>  $lines
     * @return array<int, int> discount theo chỉ số dòng
     */
    private function allocate(array $lines, int $eligibleSubtotal, int $discount): array
    {
        $allocation = array_fill(0, count($lines), 0);

        if ($discount === 0 || $eligibleSubtotal === 0) {
            return $allocation;
        }

        $remainders = [];
        $allocated = 0;

        foreach ($lines as $i => $line) {
            if (! $line->eligible || $line->unitPrice === 0) {
                continue;
            }

            $numerator = $discount * $line->unitPrice;
            $allocation[$i] = intdiv($numerator, $eligibleSubtotal);
            $remainders[$i] = $numerator % $eligibleSubtotal;
            $allocated += $allocation[$i];
        }

        // Phần dư (< số dòng) chia mỗi dòng 1 VND, ưu tiên phần dư lớn nhất; hoà
        // thì dòng đứng sau trước (xác định, ổn định giữa các lần gọi). Mỗi dòng
        // nhận nhiều nhất 1 VND và chỉ khi phần dư > 0 nên không vượt giá dòng.
        $leftover = $discount - $allocated;

        uksort($remainders, fn (int $a, int $b) => [$remainders[$b], $b] <=> [$remainders[$a], $a]);

        foreach (array_keys($remainders) as $i) {
            if ($leftover <= 0) {
                break;
            }

            $allocation[$i]++;
            $leftover--;
        }

        return $allocation;
    }
}
