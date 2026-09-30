<?php

use App\Enums\CouponDiscountType;
use App\Services\Cart\PricingCalculator;
use App\Services\Cart\PricingLine;

/**
 * `PricingCalculator` là hàm thuần (T16, api-contract §3): không DB.
 */
function vvLines(array $prices, ?array $eligible = null): array
{
    $lines = [];

    foreach ($prices as $i => $price) {
        $lines[] = new PricingLine($i + 1, $price, $eligible === null ? true : in_array($i + 1, $eligible, true));
    }

    return $lines;
}

test('khong co ma: tong = tong gia goc, giam 0', function () {
    $result = (new PricingCalculator)->calculate(vvLines([199000, 299000]));

    expect($result->totals())->toBe(['subtotal' => 498000, 'discount' => 0, 'total' => 498000]);
    expect($result->lines[0]->discountAmount)->toBe(0);
});

test('gio rong tinh ra 0', function () {
    expect((new PricingCalculator)->calculate([])->totals())->toBe(['subtotal' => 0, 'discount' => 0, 'total' => 0]);
});

test('percent lam tron XUONG toi 1 VND, khong dung float', function () {
    // 10% cua 199.999 = 19.999,9 -> 19.999
    $result = (new PricingCalculator)->calculate(vvLines([199999]), CouponDiscountType::Percent, 10);

    expect($result->discount)->toBe(19999);
    expect($result->total)->toBe(199999 - 19999);
});

test('percent 100 giam het, tong bang 0 khong am', function () {
    $result = (new PricingCalculator)->calculate(vvLines([199000, 299000]), CouponDiscountType::Percent, 100);

    expect($result->discount)->toBe(498000);
    expect($result->total)->toBe(0);
});

test('percent tren 100 bi chan o 100 (khong giam qua gia)', function () {
    $result = (new PricingCalculator)->calculate(vvLines([100000]), CouponDiscountType::Percent, 250);

    expect($result->discount)->toBe(100000);
    expect($result->total)->toBe(0);
});

test('fixed_amount lon hon gia thuc bi chan o tong phan thuoc pham vi (BR8)', function () {
    $result = (new PricingCalculator)->calculate(vvLines([199000, 299000], [1]), CouponDiscountType::FixedAmount, 1_000_000);

    // Chi khoa 1 thuoc pham vi: giam toi da 199.000, khoa 2 giu nguyen gia.
    expect($result->discount)->toBe(199000);
    expect($result->total)->toBe(299000);
    expect($result->lines[0]->finalAmount)->toBe(0);
    expect($result->lines[1]->discountAmount)->toBe(0);
    expect($result->lines[1]->finalAmount)->toBe(299000);
});

test('chi khoa thuoc pham vi bi giam, percent tinh tren tong phan thuoc pham vi (AC11)', function () {
    $result = (new PricingCalculator)->calculate(vvLines([200000, 300000], [2]), CouponDiscountType::Percent, 20);

    expect($result->subtotal)->toBe(500000);
    expect($result->discount)->toBe(60000);
    expect($result->total)->toBe(440000);
    expect($result->lines[0]->discountAmount)->toBe(0);
    expect($result->lines[1]->discountAmount)->toBe(60000);
});

test('khong dong nao thuoc pham vi: khong giam', function () {
    $none = (new PricingCalculator)->calculate(vvLines([200000], []), CouponDiscountType::FixedAmount, 50000);
    expect($none->discount)->toBe(0);
    expect($none->total)->toBe(200000);
});

test('phan bo giam gia: tong phan bo bang dung tong giam, moi dong trong [0, gia dong]', function () {
    // Truong hop dong cuoi mac ket neu don phan du vao dong cuoi: 1, 1, 1000 giam 1001.
    $result = (new PricingCalculator)->calculate(vvLines([1, 1, 1000]), CouponDiscountType::FixedAmount, 1001);

    $sum = 0;
    foreach ($result->lines as $line) {
        expect($line->discountAmount)->toBeGreaterThanOrEqual(0);
        expect($line->discountAmount)->toBeLessThanOrEqual($line->unitPrice);
        expect($line->finalAmount)->toBe($line->unitPrice - $line->discountAmount);
        $sum += $line->discountAmount;
    }

    expect($sum)->toBe(1001);
    expect($result->discount)->toBe(1001);
    expect($result->total)->toBe(1002 - 1001);
});

test('phan bo giam gia bat bien tren nhieu tap ngau nhien (tong khop, khong am, khong qua gia)', function () {
    mt_srand(20260930);
    $calc = new PricingCalculator;

    for ($n = 0; $n < 300; $n++) {
        $count = mt_rand(1, 8);
        $prices = [];
        for ($i = 0; $i < $count; $i++) {
            $prices[] = mt_rand(1, 20) * 1000 + mt_rand(0, 999);
        }
        $eligibleIds = array_values(array_filter(range(1, $count), fn () => mt_rand(0, 3) > 0));
        $type = mt_rand(0, 1) ? CouponDiscountType::Percent : CouponDiscountType::FixedAmount;
        $value = $type === CouponDiscountType::Percent ? mt_rand(1, 100) : mt_rand(1, 300000);

        $result = $calc->calculate(vvLines($prices, $eligibleIds), $type, $value);

        $sumDiscount = 0;
        $eligibleSubtotal = 0;
        foreach ($result->lines as $i => $line) {
            expect($line->discountAmount)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($line->unitPrice);
            $sumDiscount += $line->discountAmount;
            if (in_array($i + 1, $eligibleIds, true)) {
                $eligibleSubtotal += $line->unitPrice;
            } else {
                expect($line->discountAmount)->toBe(0);
            }
        }

        expect($sumDiscount)->toBe($result->discount);
        expect($result->discount)->toBeLessThanOrEqual($eligibleSubtotal);
        expect($result->total)->toBe($result->subtotal - $result->discount)->toBeGreaterThanOrEqual(0);
    }
});

test('gia am bi tu choi', function () {
    expect(fn () => (new PricingCalculator)->calculate([new PricingLine(1, -1, true)]))->toThrow(InvalidArgumentException::class);
});

test('gioi han tran so nguyen dua tren gia dong toi da: 61 khoa 50 trieu van tinh duoc, hang nghin khoa thi bi tu choi co kiem soat (R6)', function () {
    $calc = new PricingCalculator;

    $lines = array_map(fn ($i) => new PricingLine($i, 50_000_000, true), range(1, 61));
    $result = $calc->calculate($lines, CouponDiscountType::FixedAmount, 2_000_000_000);
    expect($result->discount)->toBe(2_000_000_000);
    expect(array_sum(array_map(fn ($l) => $l->discountAmount, $result->lines)))->toBe(2_000_000_000);

    // Khong co ma: khong bao gio nem, ke ca tong rat lon.
    $huge = array_map(fn ($i) => new PricingLine($i, 50_000_000, true), range(1, 5000));
    expect($calc->calculate($huge)->subtotal)->toBe(250_000_000_000);

    // discount * gia dong > PHP_INT_MAX -> InvalidArgumentException (CartService bat, khong 500).
    expect(fn () => $calc->calculate($huge, CouponDiscountType::FixedAmount, 250_000_000_000))
        ->toThrow(InvalidArgumentException::class);
});
