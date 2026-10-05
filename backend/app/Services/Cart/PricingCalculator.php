<?php

namespace App\Services\Cart;

use App\Enums\CouponDiscountType;
use App\Models\Coupon;
use App\Services\Cart\Data\PricedLine;
use App\Services\Cart\Data\Pricing;

/**
 * Hàm thuần tính giá cho giỏ/checkout/đơn (api-contract §3): không đọc DB, không kiểm hiệu lực mã (việc của
 * CouponEvaluator). Mọi số là VND nguyên.
 *
 * Giảm giá = percent: floor(Σ giá khóa trong phạm vi × % / 100); fixed_amount: min(giá trị mã, Σ giá khóa trong
 * phạm vi) (US-013 BR6). Phân bổ xuống dòng theo tỷ lệ giá, phần dư dồn vào dòng cuối trong phạm vi (data-model
 * order_items); mỗi dòng không bao giờ giảm quá giá của nó.
 */
class PricingCalculator
{
    /**
     * @param  array<int, int>  $prices  course_id => đơn giá (thứ tự được giữ)
     * @param  list<int>|null  $eligibleCourseIds  Khóa thuộc phạm vi mã; null = mọi khóa trong `$prices`.
     */
    public function calculate(array $prices, ?Coupon $coupon = null, ?array $eligibleCourseIds = null): Pricing
    {
        $subtotal = array_sum($prices);

        $scope = $coupon === null
            ? []
            : array_values(array_filter(
                array_keys($prices),
                fn (int $id) => $eligibleCourseIds === null || in_array($id, $eligibleCourseIds, true),
            ));
        $scopeSum = array_sum(array_map(fn (int $id) => $prices[$id], $scope));

        $discount = $coupon === null ? 0 : $this->discountFor($coupon, $scopeSum);
        $allocation = $this->allocate($prices, $scope, $scopeSum, $discount);

        $lines = [];
        foreach ($prices as $courseId => $price) {
            $d = $allocation[$courseId] ?? 0;
            $lines[] = new PricedLine($courseId, $price, $d, $price - $d);
        }

        return new Pricing($subtotal, $discount, $subtotal - $discount, $lines);
    }

    /** Số tiền giảm tối đa mã này cho trên phần giá `$scopeSum` thuộc phạm vi. */
    public function discountFor(Coupon $coupon, int $scopeSum): int
    {
        if ($scopeSum <= 0) {
            return 0;
        }

        $raw = match ($coupon->discount_type) {
            CouponDiscountType::Percent => intdiv($scopeSum * $coupon->discount_value, 100),
            CouponDiscountType::FixedAmount => $coupon->discount_value,
        };

        return max(0, min($raw, $scopeSum));
    }

    /**
     * @param  array<int, int>  $prices
     * @param  list<int>  $scope
     * @return array<int, int> course_id => số giảm
     */
    private function allocate(array $prices, array $scope, int $scopeSum, int $discount): array
    {
        if ($discount <= 0 || $scope === []) {
            return [];
        }

        $allocation = [];
        $given = 0;
        foreach ($scope as $courseId) {
            $allocation[$courseId] = intdiv($discount * $prices[$courseId], $scopeSum);
            $given += $allocation[$courseId];
        }

        // Phần dư dồn vào dòng cuối; nếu dòng đó đã hết chỗ (đã giảm bằng giá) thì tràn ngược lên dòng trước.
        $remainder = $discount - $given;
        foreach (array_reverse($scope) as $courseId) {
            if ($remainder <= 0) {
                break;
            }
            $add = min($remainder, $prices[$courseId] - $allocation[$courseId]);
            $allocation[$courseId] += $add;
            $remainder -= $add;
        }

        return $allocation;
    }
}
