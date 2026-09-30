<?php

use App\Models\Coupon;
use App\Models\User;
use App\Services\Cart\DatabaseCouponUsageChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `DatabaseCouponUsageChecker` (lớp THẬT, không fake) — R5 review T16.
 *
 * TODO(T18): khi có bảng `coupon_usages` thật, xoá nhánh `hasTable` ở checker
 * và đổi test thứ 2 sang bảng thật (bảng TEMPORARY dưới đây chỉ để tránh DDL
 * làm vỡ transaction của RefreshDatabase — `CREATE TEMPORARY TABLE` không
 * commit ngầm).
 */
test('chua co bang coupon_usages: lop that tra false, khong nem loi', function () {
    expect(Schema::hasTable('coupon_usages'))->toBeFalse('T18 đã tạo bảng: xoá cầu nối hasTable và đổi test này');

    $coupon = Coupon::factory()->create();
    $user = User::factory()->student()->create();

    expect((new DatabaseCouponUsageChecker)->hasUsed($coupon, $user))->toBeFalse();
});

test('co bang coupon_usages: dung cap (coupon, user) moi tra true', function () {
    DB::statement('CREATE TEMPORARY TABLE coupon_usages (id BIGINT AUTO_INCREMENT PRIMARY KEY, coupon_id BIGINT NOT NULL, user_id BIGINT NOT NULL, UNIQUE (coupon_id, user_id))');

    try {
        $coupon = Coupon::factory()->create();
        $otherCoupon = Coupon::factory()->create();
        $user = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        DB::table('coupon_usages')->insert(['coupon_id' => $coupon->id, 'user_id' => $user->id]);

        // Ép nhánh "có bảng" (information_schema không thấy bảng TEMPORARY).
        $checker = new class extends DatabaseCouponUsageChecker
        {
            protected function tableExists(): bool
            {
                return true;
            }
        };

        expect($checker->hasUsed($coupon, $user))->toBeTrue();
        expect($checker->hasUsed($coupon, $other))->toBeFalse();
        expect($checker->hasUsed($otherCoupon, $user))->toBeFalse();
    } finally {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS coupon_usages');
    }
});
