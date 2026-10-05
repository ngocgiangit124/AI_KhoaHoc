<?php

use App\Models\Coupon;
use App\Services\Cart\PricingCalculator;

function vvCalc(array $prices, ?Coupon $coupon = null, ?array $eligible = null)
{
    return (new PricingCalculator)->calculate($prices, $coupon, $eligible);
}

test('khong ma: tong = tam tinh, khong giam', function () {
    $p = vvCalc([1 => 100000, 2 => 50000]);

    expect($p->subtotal)->toBe(150000)->and($p->discount)->toBe(0)->and($p->total)->toBe(150000)
        ->and($p->line(1)->finalAmount)->toBe(100000);
});

test('gio rong: toan 0', function () {
    $p = vvCalc([], Coupon::factory()->make());

    expect($p->toArray())->toBe(['subtotal' => 0, 'discount' => 0, 'total' => 0]);
});

test('percent giam tren toan gio, lam tron xuong, tong phan bo == tong giam', function () {
    $p = vvCalc([1 => 99999, 2 => 33333, 3 => 1], Coupon::factory()->percent(15)->make());

    expect($p->discount)->toBe(intdiv((99999 + 33333 + 1) * 15, 100))
        ->and(array_sum(array_map(fn ($l) => $l->discountAmount, $p->lines)))->toBe($p->discount)
        ->and($p->total)->toBe($p->subtotal - $p->discount);
    foreach ($p->lines as $l) {
        expect($l->discountAmount)->toBeLessThanOrEqual($l->unitPrice)->and($l->finalAmount)->toBe($l->unitPrice - $l->discountAmount);
    }
});

test('AC11: chi khoa thuoc pham vi duoc giam, khoa ngoai giu nguyen gia', function () {
    $p = vvCalc([1 => 200000, 2 => 100000], Coupon::factory()->percent(50)->make(), [2]);

    expect($p->discount)->toBe(50000)->and($p->total)->toBe(250000)
        ->and($p->line(1)->discountAmount)->toBe(0)->and($p->line(2)->discountAmount)->toBe(50000);
});

test('BR6: fixed lon hon phan ap dung -> giam toi da bang phan ap dung, tong khong am', function () {
    $p = vvCalc([1 => 80000, 2 => 500000], Coupon::factory()->fixed(100000)->make(), [1]);

    expect($p->discount)->toBe(80000)->and($p->line(1)->finalAmount)->toBe(0)->and($p->line(2)->finalAmount)->toBe(500000)
        ->and($p->total)->toBe(500000);
});

test('fixed phan bo theo ty le, phan du don vao dong cuoi trong pham vi', function () {
    $p = vvCalc([1 => 1000, 2 => 1000, 3 => 1000], Coupon::factory()->fixed(1000)->make());

    expect(array_map(fn ($l) => $l->discountAmount, $p->lines))->toBe([333, 333, 334]);
});

test('phan du khong lam dong nao giam qua gia (tran nguoc len dong truoc)', function () {
    foreach ([[3, 1, 7], [5, 5, 5, 1], [1, 1, 1], [2, 9, 2, 9]] as $prices) {
        $sum = array_sum($prices);
        foreach ([1, $sum - 1, $sum, intdiv($sum, 2)] as $fixed) {
            if ($fixed < 1) {
                continue;
            }
            $p = vvCalc($prices, Coupon::factory()->fixed($fixed)->make());
            expect($p->discount)->toBe(min($fixed, $sum))
                ->and(array_sum(array_map(fn ($l) => $l->discountAmount, $p->lines)))->toBe($p->discount);
            foreach ($p->lines as $l) {
                expect($l->finalAmount)->toBeGreaterThanOrEqual(0);
            }
        }
    }
});

test('percent 100 -> tong 0', function () {
    $p = vvCalc([1 => 70000, 2 => 30000], Coupon::factory()->percent(100)->make());

    expect($p->total)->toBe(0)->and($p->discount)->toBe(100000);
});

test('pham vi rong (khong khoa nao thuoc ma) -> khong giam', function () {
    $p = vvCalc([1 => 70000], Coupon::factory()->percent(50)->make(), []);

    expect($p->discount)->toBe(0)->and($p->total)->toBe(70000);
});
