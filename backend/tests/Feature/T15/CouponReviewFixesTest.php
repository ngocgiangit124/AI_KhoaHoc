<?php

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\User;
use App\Services\Coupon\CouponService;

/**
 * Sửa vòng 1 sau review T15 (docs/reviews/review-T15.md R1, R2, R4, R5, R8 và
 * docs/db/T15-review.md M2).
 */
function couponFixUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1/coupons'.$path;
}

function couponFixHeaders(): array
{
    return ['Origin' => config('app.admin_url')];
}

function couponFixPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'FIX'.fake()->unique()->numberBetween(1000, 999999),
        'discount_type' => 'percent',
        'discount_value' => 10,
        'valid_from' => now()->toDateString(),
    ], $overrides);
}

// --- R1: phạm vi không có khóa published ------------------------------------

test('R1 fixed lon voi pham vi chi co khoa draft van bi bat buoc gioi han', function () {
    $admin = User::factory()->admin()->create();
    $draft = Course::factory()->create(['price' => 100000]);

    $response = $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'discount_type' => 'fixed_amount',
        'discount_value' => 100000,
        'course_ids' => [$draft->id],
    ]), couponFixHeaders());

    $response->assertStatus(422)->assertJsonValidationErrors(['max_uses', 'valid_until']);
});

test('R1 fixed khi khong xac dinh duoc gia re nhat (site khong co khoa nao) coi la rui ro cao', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'discount_type' => 'fixed_amount',
        'discount_value' => 10000,
    ]), couponFixHeaders());

    $response->assertStatus(422)->assertJsonValidationErrors(['max_uses', 'valid_until']);
});

test('R1 fixed rui ro cao co du gioi han thi tao duoc va audit co co rui ro', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'discount_type' => 'fixed_amount',
        'discount_value' => 10000,
        'max_uses' => 10,
        'valid_until' => now()->addMonth()->toDateString(),
    ]), couponFixHeaders());

    $response->assertCreated();
    $log = AuditLog::query()->where('action', 'coupon.create')->firstOrFail();
    expect($log->changes['high_risk_full_discount'])->toBeTrue();
});

// --- R2: cờ audit -------------------------------------------------------------

test('R2 audit ghi co high_risk_full_discount true voi ma 100% va false voi ma thuong', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'code' => 'RISKY100',
        'discount_value' => 100,
        'max_uses' => 5,
        'valid_until' => now()->addMonth()->toDateString(),
    ]), couponFixHeaders())->assertCreated();

    $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload(['code' => 'SAFE10']), couponFixHeaders())->assertCreated();

    $logs = AuditLog::query()->where('action', 'coupon.create')->get()->keyBy(fn ($l) => $l->changes['coupon_code']);
    expect($logs['RISKY100']->changes['high_risk_full_discount'])->toBeTrue();
    expect($logs['SAFE10']->changes['high_risk_full_discount'])->toBeFalse();
});

// --- R4: valid_until cuối ngày ------------------------------------------------

test('R4 valid_until chi co ngay het han cuoi ngay va bien valid_until = valid_from hop le', function () {
    $admin = User::factory()->admin()->create();
    $day = now()->addDays(3)->toDateString();

    $response = $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'code' => 'SAMEDAY',
        'valid_from' => $day,
        'valid_until' => $day,
    ]), couponFixHeaders());

    $response->assertCreated();
    $coupon = Coupon::query()->where('code', 'SAMEDAY')->firstOrFail();
    expect($coupon->valid_until->format('Y-m-d H:i:s'))->toBe($day.' 23:59:59');
    expect($coupon->valid_until->greaterThan($coupon->valid_from))->toBeTrue();
});

test('R4 valid_until co gio duoc giu nguyen', function () {
    $admin = User::factory()->admin()->create();
    $day = now()->addDays(3)->toDateString();

    $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'code' => 'WITHTIME',
        'valid_until' => $day.' 10:30:00',
    ]), couponFixHeaders())->assertCreated();

    expect(Coupon::query()->where('code', 'WITHTIME')->firstOrFail()->valid_until->format('Y-m-d H:i:s'))->toBe($day.' 10:30:00');
});

// --- R5 -----------------------------------------------------------------------

test('R5 gia tri vuot tran cot tra 422 khong 500', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'discount_type' => 'fixed_amount',
        'discount_value' => 5000000000,
        'max_uses' => 1,
        'valid_until' => now()->addMonth()->toDateString(),
    ]), couponFixHeaders())->assertStatus(422)->assertJsonValidationErrors('discount_value');

    $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'max_uses' => 5000000000,
    ]), couponFixHeaders())->assertStatus(422)->assertJsonValidationErrors('max_uses');
});

test('R5 khong cho ha max_uses duoi used_count (422 o request)', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->used(50)->create(['max_uses' => 100]);

    $response = $this->actingAs($admin)->putJson(couponFixUrl('/'.$coupon->id), [
        'code' => $coupon->code,
        'discount_type' => $coupon->discount_type->value,
        'discount_value' => $coupon->discount_value,
        'valid_from' => $coupon->valid_from->toDateString(),
        'valid_until' => $coupon->valid_until->toDateString(),
        'max_uses' => 10,
    ], couponFixHeaders());

    $response->assertStatus(422)->assertJsonValidationErrors('max_uses');
    expect($coupon->fresh()->max_uses)->toBe(100);
});

test('R5 course_ids trung nhau bi 422', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)->postJson(couponFixUrl(), couponFixPayload([
        'course_ids' => [$course->id, $course->id],
    ]), couponFixHeaders())->assertStatus(422)->assertJsonValidationErrors('course_ids.0');
});

// --- DBA M2 / defense in depth -----------------------------------------------

test('Service ghi de code/loai/gia tri khi used_count > 0 (bo qua du lieu gui len)', function () {
    $coupon = Coupon::factory()->used(2)->create([
        'discount_type' => CouponDiscountType::Percent,
        'discount_value' => 10,
    ]);
    $originalCode = $coupon->code;

    app(CouponService::class)->update($coupon, [
        'code' => 'HACKED',
        'discount_type' => 'fixed_amount',
        'discount_value' => 999,
        'valid_from' => now()->toDateString(),
    ]);

    $fresh = $coupon->fresh();
    expect($fresh->code)->toBe($originalCode)
        ->and($fresh->discount_type)->toBe(CouponDiscountType::Percent)
        ->and($fresh->discount_value)->toBe(10);
});

test('Service tu choi ha max_uses duoi used_count du request bi qua mat', function () {
    $coupon = Coupon::factory()->used(5)->create(['max_uses' => 10]);

    expect(fn () => app(CouponService::class)->update($coupon, [
        'code' => $coupon->code,
        'discount_type' => 'percent',
        'discount_value' => 10,
        'max_uses' => 2,
        'valid_from' => now()->toDateString(),
    ]))->toThrow(DomainException::class);

    expect($coupon->fresh()->max_uses)->toBe(10);
});

test('Service dung used_count moi nhat (sau khoa dong) khi xoa', function () {
    $stale = Coupon::factory()->create();
    Coupon::query()->whereKey($stale->id)->update(['used_count' => 1]);

    expect(fn () => app(CouponService::class)->delete($stale))->toThrow(DomainException::class);
    $this->assertDatabaseHas('coupons', ['id' => $stale->id]);
});

test('deactivate lan hai khong ghi audit them', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create();

    $this->actingAs($admin)->postJson(couponFixUrl('/'.$coupon->id.'/deactivate'), [], couponFixHeaders())->assertOk();
    $this->actingAs($admin)->postJson(couponFixUrl('/'.$coupon->id.'/deactivate'), [], couponFixHeaders())
        ->assertOk()->assertJson(['status' => 'inactive']);

    expect(AuditLog::query()->where('action', 'coupon.deactivate')->count())->toBe(1);
    expect($coupon->fresh()->status)->toBe(CouponStatus::Inactive);
});

test('409 COUPON_IN_USE co noi dung thong bao', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->used(1)->create();

    $response = $this->actingAs($admin)->deleteJson(couponFixUrl('/'.$coupon->id), [], couponFixHeaders());

    $response->assertStatus(409);
    expect($response->json('message'))->toContain('vô hiệu hoá');
});
