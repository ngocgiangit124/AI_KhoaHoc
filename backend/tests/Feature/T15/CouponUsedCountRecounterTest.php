<?php

use App\Models\Coupon;
use App\Services\Counters\CouponUsedCountRecounter;
use Illuminate\Support\Facades\Schema;

/**
 * DBA #5 (docs/db/design-review.md §2.3/2.9) — đối soát `coupons.used_count`.
 * `coupon_usages` chỉ được tạo ở T18 (cần FK `orders`, chưa tồn tại ở nhánh
 * này) nên `recount()` chỉ kiểm được nhánh "bảng chưa tồn tại"; logic đếm/áp
 * dụng số liệu (`applyCounts()`) được test độc lập, không cần bảng thật —
 * xem TODO(T18) ở `CouponUsedCountRecounter`.
 */
test('recount() bo qua an toan khi bang coupon_usages chua ton tai (TODO T18)', function () {
    expect(Schema::hasTable('coupon_usages'))->toBeFalse();

    $coupon = Coupon::factory()->create(['used_count' => 3]);

    (new CouponUsedCountRecounter)->recount();

    expect($coupon->fresh()->used_count)->toBe(3);
});

test('applyCounts() cap nhat dung used_count theo so dem thuc te tung ma', function () {
    $couponA = Coupon::factory()->create(['used_count' => 0]);
    $couponB = Coupon::factory()->create(['used_count' => 5]);
    $couponC = Coupon::factory()->create(['used_count' => 0]);

    (new CouponUsedCountRecounter)->applyCounts(collect([
        $couponA->id => 2,
        $couponB->id => 1,
        // $couponC cố tình KHÔNG có mặt -> phải giữ nguyên 0 (không đơn nào).
    ]));

    expect($couponA->fresh()->used_count)->toBe(2)
        ->and($couponB->fresh()->used_count)->toBe(1)
        ->and($couponC->fresh()->used_count)->toBe(0);
});

test('applyCounts() khong ghi DB thua khi used_count da dung san', function () {
    $coupon = Coupon::factory()->create(['used_count' => 4]);
    $updatedAtBefore = $coupon->updated_at;

    $this->travel(1)->seconds();

    (new CouponUsedCountRecounter)->applyCounts(collect([$coupon->id => 4]));

    expect($coupon->fresh()->updated_at->equalTo($updatedAtBefore))->toBeTrue();
});
