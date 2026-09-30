<?php

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\User;

/**
 * US-013 — Quản lý mã giảm giá (host admin-api, nhóm `staff`).
 */
function couponAdminUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1/coupons'.$path;
}

function couponAdminHeaders(): array
{
    return ['Origin' => config('app.admin_url')];
}

// --- Phân quyền (US-013 §Phân quyền, api-contract §2.5) --------------------

test('admin xem duoc danh sach ma giam gia (AC1)', function () {
    $admin = User::factory()->admin()->create();
    Coupon::factory()->count(2)->create();

    $response = $this->actingAs($admin)->getJson(couponAdminUrl(), couponAdminHeaders());

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

test('quan ly trang tao ma giam gia moi thanh cong (AC1)', function () {
    $pageManager = User::factory()->pageManager()->create();

    $response = $this->actingAs($pageManager)->postJson(couponAdminUrl(), [
        'code' => 'toan2026',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addMonth()->toDateString(),
    ], couponAdminHeaders());

    $response->assertCreated();
    $response->assertJson([
        'code' => 'TOAN2026',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'status' => 'active',
        'is_restricted' => false,
    ]);

    $this->assertDatabaseHas('coupons', ['code' => 'TOAN2026', 'status' => 'active']);
});

test('giao vien khong xem/tao/sua/vo hieu hoa/xoa duoc ma giam gia', function () {
    $teacher = User::factory()->teacher()->create();
    $coupon = Coupon::factory()->create();

    $this->actingAs($teacher)->getJson(couponAdminUrl(), couponAdminHeaders())->assertForbidden();
    $this->actingAs($teacher)->postJson(couponAdminUrl(), [], couponAdminHeaders())->assertForbidden();
    $this->actingAs($teacher)->putJson(couponAdminUrl('/'.$coupon->id), [], couponAdminHeaders())->assertForbidden();
    $this->actingAs($teacher)->postJson(couponAdminUrl('/'.$coupon->id.'/deactivate'), [], couponAdminHeaders())->assertForbidden();
    $this->actingAs($teacher)->deleteJson(couponAdminUrl('/'.$coupon->id), [], couponAdminHeaders())->assertForbidden();
});

test('hoc sinh bi tu choi 403', function () {
    $student = User::factory()->student()->create();

    $response = $this->actingAs($student)->getJson(couponAdminUrl(), couponAdminHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('khach chua dang nhap bi tu choi 401', function () {
    $response = $this->getJson(couponAdminUrl(), couponAdminHeaders());

    $response->assertStatus(401);
});

// --- Validation (AC2, AC5, AC7) ----------------------------------------------

test('ma trung khong phan biet hoa thuong bi bao loi Ma giam gia da ton tai (AC2)', function () {
    $admin = User::factory()->admin()->create();
    Coupon::factory()->create(['code' => 'TOAN2026']);

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'toan2026',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'valid_from' => now()->toDateString(),
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('code');
    expect($response->json('errors.code.0'))->toBe('Mã giảm giá đã tồn tại.');
});

test('ngay ket thuc truoc ngay bat dau bi bao loi validate (AC5)', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'SALE01',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->subDay()->toDateString(),
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('valid_until');
});

test('gia tri giam phan tram vuot 100 bi bao loi validate (AC7)', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'SALE02',
        'discount_type' => 'percent',
        'discount_value' => 150,
        'valid_from' => now()->toDateString(),
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('discount_value');
    expect($response->json('errors.discount_value.0'))->toBe('Giá trị giảm không được vượt quá 100%.');
});

test('ma giam 100% thieu max_uses va valid_until bi bao loi (S18)', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'FULL100',
        'discount_type' => 'percent',
        'discount_value' => 100,
        'valid_from' => now()->toDateString(),
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['max_uses', 'valid_until']);
});

test('ma fixed lon hon hoac bang gia khoa re nhat dang ban thieu gioi han bi bao loi (S18)', function () {
    $admin = User::factory()->admin()->create();
    Course::factory()->published()->create(['price' => 100000]);
    Course::factory()->published()->create(['price' => 200000]);

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'FIXED100K',
        'discount_type' => 'fixed_amount',
        'discount_value' => 100000,
        'valid_from' => now()->toDateString(),
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['max_uses', 'valid_until']);
});

test('ma fixed thap hon gia khoa re nhat dang ban khong bi bat buoc gioi han', function () {
    $admin = User::factory()->admin()->create();
    Course::factory()->published()->create(['price' => 200000]);

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'FIXED50K',
        'discount_type' => 'fixed_amount',
        'discount_value' => 50000,
        'valid_from' => now()->toDateString(),
    ], couponAdminHeaders());

    $response->assertCreated();
});

// --- Phạm vi áp dụng (US-013 BR4, AC8) ---------------------------------------

test('tao ma voi pham vi khoa hoc cu the danh dau is_restricted va tra dung course_ids (AC8)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'SCOPE01',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'valid_from' => now()->toDateString(),
        'course_ids' => [$course->id],
    ], couponAdminHeaders());

    $response->assertCreated();
    $response->assertJson(['is_restricted' => true, 'course_ids' => [$course->id]]);
});

test('course_ids khong ton tai bi bao loi validate', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'SCOPE02',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'valid_from' => now()->toDateString(),
        'course_ids' => [999999],
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('course_ids.0');
});

// --- Sửa / vô hiệu hoá / xoá --------------------------------------------------

test('sua ma giam gia thanh cong', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create(['name' => 'Ten cu']);

    $response = $this->actingAs($admin)->putJson(couponAdminUrl('/'.$coupon->id), [
        'code' => $coupon->code,
        'name' => 'Ten moi',
        'discount_type' => $coupon->discount_type->value,
        'discount_value' => $coupon->discount_value,
        'valid_from' => $coupon->valid_from->toDateString(),
        'valid_until' => $coupon->valid_until->toDateString(),
    ], couponAdminHeaders());

    $response->assertOk();
    $response->assertJson(['name' => 'Ten moi']);
});

test('ma da co luot dung khong cho doi code/loai/gia tri giam (dac ta UX §2.2)', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->used(1)->create([
        'discount_type' => CouponDiscountType::Percent,
        'discount_value' => 10,
    ]);

    $response = $this->actingAs($admin)->putJson(couponAdminUrl('/'.$coupon->id), [
        'code' => 'LOCKED_NEW',
        'discount_type' => 'fixed_amount',
        'discount_value' => 5000,
        'valid_from' => $coupon->valid_from->toDateString(),
        'valid_until' => $coupon->valid_until->toDateString(),
    ], couponAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['code', 'discount_type', 'discount_value']);
});

test('ma da co luot dung van sua duoc ten/han muc khi khong doi code/loai/gia tri', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->used(1)->create();

    $response = $this->actingAs($admin)->putJson(couponAdminUrl('/'.$coupon->id), [
        'code' => $coupon->code,
        'name' => 'Chuong trinh moi',
        'discount_type' => $coupon->discount_type->value,
        'discount_value' => $coupon->discount_value,
        'valid_from' => $coupon->valid_from->toDateString(),
        'valid_until' => now()->addMonths(2)->toDateString(),
    ], couponAdminHeaders());

    $response->assertOk();
    $response->assertJson(['name' => 'Chuong trinh moi']);
});

test('vo hieu hoa ma thanh cong (AC3)', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create();

    $response = $this->actingAs($admin)->postJson(couponAdminUrl('/'.$coupon->id.'/deactivate'), [], couponAdminHeaders());

    $response->assertOk();
    $response->assertJson(['status' => 'inactive']);
    expect($coupon->fresh()->status)->toBe(CouponStatus::Inactive);
});

test('xoa ma chua dung lan nao thanh cong', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create();

    $response = $this->actingAs($admin)->deleteJson(couponAdminUrl('/'.$coupon->id), [], couponAdminHeaders());

    $response->assertNoContent();
    $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
});

test('xoa ma da dung it nhat 1 lan bi chan 409', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->used(1)->create();

    $response = $this->actingAs($admin)->deleteJson(couponAdminUrl('/'.$coupon->id), [], couponAdminHeaders());

    $response->assertStatus(409);
    $response->assertJson(['code' => 'COUPON_IN_USE']);
    $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
});

// --- Lọc theo trạng thái (AC4, AC6) -------------------------------------------

test('loc danh sach theo trang thai active/inactive/expired/exhausted (AC6)', function () {
    $admin = User::factory()->admin()->create();
    $active = Coupon::factory()->create();
    $inactive = Coupon::factory()->inactive()->create();
    $expired = Coupon::factory()->expired()->create();
    $exhausted = Coupon::factory()->create(['max_uses' => 5, 'used_count' => 5]);

    $activeIds = collect(
        $this->actingAs($admin)->getJson(couponAdminUrl('?state=active'), couponAdminHeaders())->json('data')
    )->pluck('id')->all();
    expect($activeIds)->toBe([$active->id]);

    $inactiveIds = collect(
        $this->actingAs($admin)->getJson(couponAdminUrl('?state=inactive'), couponAdminHeaders())->json('data')
    )->pluck('id')->all();
    expect($inactiveIds)->toBe([$inactive->id]);

    $expiredIds = collect(
        $this->actingAs($admin)->getJson(couponAdminUrl('?state=expired'), couponAdminHeaders())->json('data')
    )->pluck('id')->all();
    expect($expiredIds)->toBe([$expired->id]);

    $exhaustedIds = collect(
        $this->actingAs($admin)->getJson(couponAdminUrl('?state=exhausted'), couponAdminHeaders())->json('data')
    )->pluck('id')->all();
    expect($exhaustedIds)->toBe([$exhausted->id]);
});

test('danh sach hien thi dung luot da dung/tong so luot cho phep (AC4)', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create(['max_uses' => 100, 'used_count' => 45]);

    $response = $this->actingAs($admin)->getJson(couponAdminUrl(), couponAdminHeaders());

    $response->assertOk();
    $item = collect($response->json('data'))->firstWhere('id', $coupon->id);
    expect($item['used_count'])->toBe(45);
    expect($item['max_uses'])->toBe(100);
});

// --- Audit log (S15) -----------------------------------------------------------

test('tao ma giam gia ghi audit log', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(couponAdminUrl(), [
        'code' => 'AUDIT01',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'valid_from' => now()->toDateString(),
    ], couponAdminHeaders());

    $log = AuditLog::query()->where('action', 'coupon.create')->first();

    expect($log)->not->toBeNull();
    expect($log->actor_id)->toBe($admin->id);
    expect($log->subject_type)->toBe((new Coupon)->getMorphClass());
    expect($log->changes['coupon_code'])->toBe('AUDIT01');
});

test('vo hieu hoa ma ghi audit log', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create();

    $this->actingAs($admin)->postJson(couponAdminUrl('/'.$coupon->id.'/deactivate'), [], couponAdminHeaders());

    $log = AuditLog::query()->where('action', 'coupon.deactivate')->first();

    expect($log)->not->toBeNull();
    expect($log->subject_id)->toBe($coupon->id);
});

test('xoa ma ghi audit log', function () {
    $admin = User::factory()->admin()->create();
    $coupon = Coupon::factory()->create();

    $this->actingAs($admin)->deleteJson(couponAdminUrl('/'.$coupon->id), [], couponAdminHeaders());

    $log = AuditLog::query()->where('action', 'coupon.delete')->first();

    expect($log)->not->toBeNull();
    expect($log->subject_id)->toBe($coupon->id);
});
