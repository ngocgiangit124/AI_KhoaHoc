<?php

use App\Models\Coupon;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DBA #5 (docs/db/design-review.md §2.9) — xác nhận CHECK constraint hoạt
 * động đúng như `SHOW CREATE TABLE` (cùng kiểu test với T07
 * `SchemaConstraintsTest`).
 */
function baseCouponRow(array $overrides = []): array
{
    $creator = User::factory()->admin()->create();

    return array_merge([
        'code' => 'TEST'.fake()->unique()->numberBetween(1000, 999999),
        'discount_type' => 'percent',
        'discount_value' => 20,
        'valid_from' => now(),
        'status' => 'active',
        'created_by' => $creator->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

test('chk_coupons_percent_range tu choi discount_value ngoai 1-100 khi percent', function (int $value) {
    expect(fn () => DB::table('coupons')->insert(baseCouponRow([
        'discount_type' => 'percent',
        'discount_value' => $value,
    ])))->toThrow(QueryException::class);
})->with([0, 101, 200]);

// 100 KHÔNG nằm trong bộ dữ liệu này — giá trị này còn bị
// `chk_coupons_full_discount_limited` bắt buộc thêm `max_uses`/`valid_until`
// (kiểm riêng ở test "chk_coupons_full_discount_limited..." bên dưới).
test('chk_coupons_percent_range chap nhan discount_value 1-100 khi percent', function (int $value) {
    $coupon = Coupon::factory()->create(['discount_type' => 'percent', 'discount_value' => $value]);

    expect($coupon->discount_value)->toBe($value);
})->with([1, 50, 99]);

test('chk_coupons_percent_range khong ap dung cho fixed_amount (gia tri lon hon 100)', function () {
    $coupon = Coupon::factory()->fixedAmount(500000)->create();

    expect($coupon->discount_value)->toBe(500000);
});

test('chk_coupons_full_discount_limited tu choi ma giam 100% thieu max_uses', function () {
    expect(fn () => DB::table('coupons')->insert(baseCouponRow([
        'discount_type' => 'percent',
        'discount_value' => 100,
        'valid_until' => now()->addMonth(),
        'max_uses' => null,
    ])))->toThrow(QueryException::class);
});

test('chk_coupons_full_discount_limited tu choi ma giam 100% thieu valid_until', function () {
    expect(fn () => DB::table('coupons')->insert(baseCouponRow([
        'discount_type' => 'percent',
        'discount_value' => 100,
        'max_uses' => 100,
        'valid_until' => null,
    ])))->toThrow(QueryException::class);
});

test('chk_coupons_full_discount_limited chap nhan ma giam 100% co du max_uses va valid_until', function () {
    $coupon = Coupon::factory()->fullDiscount()->create();

    expect($coupon->discount_value)->toBe(100)
        ->and($coupon->max_uses)->not->toBeNull()
        ->and($coupon->valid_until)->not->toBeNull();
});

test('coupons.code unique bo qua hoa thuong (utf8mb4_0900_ai_ci)', function () {
    Coupon::factory()->create(['code' => 'TOAN2026']);

    expect(fn () => DB::table('coupons')->insert(baseCouponRow([
        'code' => 'toan2026',
    ])))->toThrow(QueryException::class, '1062');
});

test('coupon_course chan xoa cung khoa hoc dang nam trong pham vi 1 ma (restrict)', function () {
    $coupon = Coupon::factory()->restricted()->create();
    $course = Course::factory()->create();
    $coupon->courses()->attach($course);

    expect(fn () => DB::table('courses')->where('id', $course->id)->delete())
        ->toThrow(QueryException::class);
});

test('coupon_subject chan xoa cung chuyen de dang nam trong pham vi 1 ma (restrict)', function () {
    $coupon = Coupon::factory()->restricted()->create();
    $subject = Subject::factory()->create();
    $coupon->subjects()->attach($subject);

    expect(fn () => DB::table('subjects')->where('id', $subject->id)->delete())
        ->toThrow(QueryException::class);
});

test('xoa coupon xoa theo cascade coupon_course va coupon_subject', function () {
    $coupon = Coupon::factory()->restricted()->create();
    $course = Course::factory()->create();
    $subject = Subject::factory()->create();
    $coupon->courses()->attach($course);
    $coupon->subjects()->attach($subject);

    DB::table('coupons')->where('id', $coupon->id)->delete();

    expect(DB::table('coupon_course')->where('coupon_id', $coupon->id)->exists())->toBeFalse();
    expect(DB::table('coupon_subject')->where('coupon_id', $coupon->id)->exists())->toBeFalse();
});
