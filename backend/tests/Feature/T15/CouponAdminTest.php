<?php

use App\Enums\CouponState;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T28/helpers.php';

function vvCouponActor(string $state = 'admin'): User
{
    $user = vvStaffUser($state);
    vvStaffLogin($user);

    return $user;
}

function vvCouponSend(string $method, string $path, array $data = []): TestResponse
{
    return test()->json($method, vvAdminUrl($path), $data, vvAdminHeaders());
}

/** @return array<string, mixed> */
function vvCouponBody(array $over = []): array
{
    return array_merge([
        'code' => 'TOAN2026',
        'name' => 'Khuyến mãi Toán',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'valid_from' => now()->subHour()->toIso8601String(),
        'valid_until' => now()->addMonth()->toIso8601String(),
    ], $over);
}

test('chua dang nhap 401; hoc sinh va giao vien bi 403 o moi route', function () {
    vvAdminGet('/admin/coupons')->assertUnauthorized();

    $coupon = Coupon::factory()->create();

    foreach (['student', 'teacher'] as $role) {
        $user = $role === 'student' ? User::factory()->student()->create() : vvStaffUser('teacher');
        $role === 'student' ? test()->actingAs($user) : vvStaffLogin($user);

        vvAdminGet('/admin/coupons')->assertForbidden();
        vvAdminGet("/admin/coupons/{$coupon->id}")->assertForbidden();
        // 403 trước validate: payload rỗng vẫn 403, không 422.
        vvCouponSend('POST', '/admin/coupons', [])->assertForbidden();
        vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", [])->assertForbidden();
        vvCouponSend('POST', "/admin/coupons/{$coupon->id}/deactivate")->assertForbidden();
        vvCouponSend('POST', "/admin/coupons/{$coupon->id}/activate")->assertForbidden();
        vvCouponSend('DELETE', "/admin/coupons/{$coupon->id}")->assertForbidden();
    }

    expect(Coupon::query()->count())->toBe(1)->and($coupon->fresh()->status->value)->toBe('active');
});

test('AC1: admin va quan ly trang tao ma, mac dinh active, chuan hoa chu hoa, ghi audit', function (string $state) {
    $actor = vvCouponActor($state);

    $res = vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => '  toan2026 ']))->assertCreated();

    $res->assertJsonPath('code', 'TOAN2026')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('state', 'active')
        ->assertJsonPath('used_count', 0)
        ->assertJsonPath('max_uses_per_user', 1)
        ->assertJsonPath('is_restricted', false)
        ->assertJsonPath('created_by', $actor->id);

    $coupon = Coupon::query()->firstOrFail();
    expect($coupon->code)->toBe('TOAN2026');

    $log = AuditLog::query()->where('action', 'coupon.create')->firstOrFail();
    expect($log->actor_id)->toBe($actor->id)
        ->and($log->changes['coupon_code'])->toBe('TOAN2026')
        ->and($log->changes['high_risk'])->toBeFalse();
})->with(['admin', 'pageManager']);

test('AC2/BR1: trung ma khong phan biet hoa thuong -> 422 Ma giam gia da ton tai', function () {
    vvCouponActor();
    Coupon::factory()->create(['code' => 'TOAN2026']);

    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'toan2026']))
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'Mã giảm giá đã tồn tại.');

    expect(Coupon::query()->count())->toBe(1);
});

test('validate: code, loai, gia tri, max_uses, ngay (AC5, AC7)', function (array $over, string $field) {
    vvCouponActor();

    vvCouponSend('POST', '/admin/coupons', vvCouponBody($over))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(Coupon::query()->count())->toBe(0);
})->with([
    'code ngan' => [['code' => 'AB'], 'code'],
    'code ky tu la' => [['code' => 'TOAN 2026'], 'code'],
    'code co dau' => [['code' => 'TOÁN2026'], 'code'],
    'AC7 percent > 100' => [['discount_value' => 101], 'discount_value'],
    'percent = 0' => [['discount_value' => 0], 'discount_value'],
    'percent am' => [['discount_value' => -5], 'discount_value'],
    'fixed qua tran' => [['discount_type' => 'fixed_amount', 'discount_value' => 100_000_001], 'discount_value'],
    'loai la' => [['discount_type' => 'bogo'], 'discount_type'],
    'max_uses 0' => [['max_uses' => 0], 'max_uses'],
    'AC5 het han truoc bat dau' => [['valid_from' => '2026-12-10T00:00:00+07:00', 'valid_until' => '2026-12-01T00:00:00+07:00'], 'valid_until'],
    'ten co html' => [['name' => '<b>x</b>'], 'name'],
    'khoa khong ton tai' => [['course_ids' => [999999]], 'course_ids.0'],
    'chuyen de khong ton tai' => [['subject_ids' => [999999]], 'subject_ids.0'],
    'khoa trung' => [['course_ids' => [1, 1]], 'course_ids.0'],
]);

test('khoa hoc da xoa mem khong chon duoc vao pham vi', function () {
    vvCouponActor();
    $course = Course::factory()->published()->create();
    $course->delete();

    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['course_ids' => [$course->id]]))
        ->assertStatus(422)->assertJsonValidationErrors('course_ids.0');
});

test('BR4/AC8: pham vi theo khoa va chuyen de, chi tiet hien danh sach; khong chon = toan bo', function () {
    vvCouponActor();
    $course = Course::factory()->published()->create(['title' => 'Toán 9 nâng cao']);
    $subject = Subject::factory()->hidden()->create(['name' => 'Hình học']);

    $all = vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'ALLCOURSES']))->assertCreated();
    $all->assertJsonPath('is_restricted', false)->assertJsonPath('courses', [])->assertJsonPath('subjects', []);

    $res = vvCouponSend('POST', '/admin/coupons', vvCouponBody([
        'code' => 'SCOPED01', 'course_ids' => [$course->id], 'subject_ids' => [$subject->id],
    ]))->assertCreated();

    $res->assertJsonPath('is_restricted', true)
        ->assertJsonPath('courses.0.title', 'Toán 9 nâng cao')
        ->assertJsonPath('subjects.0.name', 'Hình học');

    $id = $res->json('id');
    vvAdminGet("/admin/coupons/{$id}")->assertOk()
        ->assertJsonPath('courses.0.id', $course->id)
        ->assertJsonPath('subjects.0.id', $subject->id);

    // Sửa: bỏ hết phạm vi -> trở lại toàn bộ.
    vvCouponSend('PUT', "/admin/coupons/{$id}", vvCouponBody(['code' => 'SCOPED01']))->assertOk()
        ->assertJsonPath('is_restricted', false);
    expect(DB::table('coupon_course')->where('coupon_id', $id)->count())->toBe(0)
        ->and(DB::table('coupon_subject')->where('coupon_id', $id)->count())->toBe(0);
});

test('S18: ma 100% hoac fixed >= gia khoa re nhat phai co max_uses va valid_until', function () {
    vvCouponActor();
    Course::factory()->published()->paid(200000)->create();
    Course::factory()->published()->paid(500000)->create();
    // Khóa nháp/rẻ hơn nhưng không bán không được tính.
    Course::factory()->paid(1000)->create();

    $noLimit = vvCouponBody(['valid_until' => null, 'max_uses' => null]);

    vvCouponSend('POST', '/admin/coupons', $noLimit + ['code' => 'FREE100'] + [])->assertCreated(); // 20% bình thường

    $full = ['discount_value' => 100, 'valid_until' => null, 'max_uses' => null, 'code' => 'FULL100'];
    vvCouponSend('POST', '/admin/coupons', array_merge($noLimit, $full))
        ->assertStatus(422)->assertJsonValidationErrors(['max_uses', 'valid_until']);

    $fixed = ['discount_type' => 'fixed_amount', 'discount_value' => 200000, 'code' => 'FIXED200'];
    vvCouponSend('POST', '/admin/coupons', array_merge($noLimit, $fixed))
        ->assertStatus(422)->assertJsonValidationErrors(['max_uses', 'valid_until']);

    // Chỉ thiếu valid_until.
    vvCouponSend('POST', '/admin/coupons', array_merge($noLimit, $fixed, ['max_uses' => 10]))
        ->assertStatus(422)->assertJsonValidationErrors(['valid_until'])->assertJsonMissingValidationErrors(['max_uses']);

    // 199.999 < giá rẻ nhất: không bắt buộc.
    vvCouponSend('POST', '/admin/coupons', array_merge($noLimit, $fixed, ['discount_value' => 199999, 'code' => 'FIXED199']))->assertCreated();

    // Đủ giới hạn: OK, audit đánh dấu high_risk.
    vvCouponSend('POST', '/admin/coupons', array_merge($fixed, ['max_uses' => 10, 'valid_until' => now()->addDay()->toIso8601String(), 'valid_from' => null]))->assertCreated();
    $log = AuditLog::query()->where('action', 'coupon.create')->latest('id')->firstOrFail();
    expect($log->changes['high_risk'])->toBeTrue();
});

test('S18: sua ma thuong thanh 100% van bi chan khi thieu gioi han', function () {
    vvCouponActor();
    $coupon = Coupon::factory()->create(['code' => 'NORMAL20']);

    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", vvCouponBody(['code' => 'NORMAL20', 'discount_value' => 100, 'max_uses' => null, 'valid_until' => null]))
        ->assertStatus(422)->assertJsonValidationErrors(['max_uses', 'valid_until']);

    expect($coupon->fresh()->discount_value)->toBe(20);
});

test('sua ma chua dung: doi duoc ca code/loai/gia tri, audit ghi diff; ma trung -> 422', function () {
    vvCouponActor();
    $coupon = Coupon::factory()->create(['code' => 'OLDCODE1']);
    Coupon::factory()->create(['code' => 'TAKEN123']);

    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", vvCouponBody(['code' => 'taken123']))
        ->assertStatus(422)->assertJsonValidationErrors('code');

    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", vvCouponBody([
        'code' => 'newcode9', 'discount_type' => 'fixed_amount', 'discount_value' => 30000,
    ]))->assertOk()->assertJsonPath('code', 'NEWCODE9')->assertJsonPath('discount_type', 'fixed_amount');

    $log = AuditLog::query()->where('action', 'coupon.update')->firstOrFail();
    expect($log->changes['discount_value'])->toEqual(['from' => 20, 'to' => 30000])
        ->and($log->changes['coupon_code'])->toEqual(['from' => 'OLDCODE1', 'to' => 'NEWCODE9']);
});

test('ma da co luot dung: khong doi code/loai/gia tri (422 COUPON_LOCKED), van doi ten/han/max_uses', function () {
    vvCouponActor();
    $coupon = Coupon::factory()->create(['code' => 'USED2026', 'used_count' => 3]);
    $base = vvCouponBody(['code' => 'USED2026']);

    foreach ([['code' => 'OTHER2026'], ['discount_value' => 30], ['discount_type' => 'fixed_amount']] as $over) {
        vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", array_merge($base, $over))
            ->assertStatus(422)->assertJsonPath('code', 'COUPON_LOCKED')
            ->assertJsonPath('errors.fields.0', array_key_first($over));
    }
    expect($coupon->fresh()->code)->toBe('USED2026')->and($coupon->fresh()->discount_value)->toBe(20);

    // max_uses không được nhỏ hơn used_count.
    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", array_merge($base, ['max_uses' => 2]))
        ->assertStatus(422)->assertJsonValidationErrors('max_uses');

    // Gửi lại đúng giá trị cũ (khác hoa/thường mã) + đổi tên, hạn, max_uses: OK.
    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", array_merge($base, ['code' => 'used2026', 'name' => 'Tên mới', 'max_uses' => 50]))
        ->assertOk()->assertJsonPath('name', 'Tên mới')->assertJsonPath('max_uses', 50);
});

test('AC3: vo hieu hoa / bat lai, idempotent, audit chi khi doi trang thai', function () {
    vvCouponActor();
    $coupon = Coupon::factory()->create();

    vvCouponSend('POST', "/admin/coupons/{$coupon->id}/deactivate")->assertOk()
        ->assertJsonPath('status', 'inactive')->assertJsonPath('state', 'inactive');
    vvCouponSend('POST', "/admin/coupons/{$coupon->id}/deactivate")->assertOk();
    expect(AuditLog::query()->where('action', 'coupon.deactivate')->count())->toBe(1);

    vvCouponSend('POST', "/admin/coupons/{$coupon->id}/activate")->assertOk()->assertJsonPath('status', 'active');
    expect(AuditLog::query()->where('action', 'coupon.activate')->count())->toBe(1);

    vvCouponSend('POST', '/admin/coupons/999999/deactivate')->assertNotFound();
});

test('AC4/AC6: state hien thi va bo loc dung cho tung trang thai', function () {
    vvCouponActor();
    $active = Coupon::factory()->create(['code' => 'ACTIVE01']);
    $inactive = Coupon::factory()->inactive()->create(['code' => 'INACTIVE1']);
    $expired = Coupon::factory()->expired()->create(['code' => 'EXPIRED01']);
    $exhausted = Coupon::factory()->exhausted(5)->create(['code' => 'EXHAUST01']);
    $upcoming = Coupon::factory()->upcoming()->create(['code' => 'UPCOMING1']);
    // Ưu tiên: inactive > expired > exhausted.
    $both = Coupon::factory()->expired()->exhausted(2)->create(['code' => 'BOTH0001']);
    $inactiveExpired = Coupon::factory()->inactive()->expired()->create(['code' => 'INEXP001']);

    $expect = [
        'active' => [$active],
        'inactive' => [$inactive, $inactiveExpired],
        'expired' => [$expired, $both],
        'exhausted' => [$exhausted],
        'upcoming' => [$upcoming],
    ];

    foreach ($expect as $state => $coupons) {
        $ids = collect(vvAdminGet("/admin/coupons?state={$state}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        expect($ids)->toBe(collect($coupons)->pluck('id')->sort()->values()->all(), "state={$state}");
    }

    $all = vvAdminGet('/admin/coupons')->assertOk()->json('data');
    expect($all)->toHaveCount(7);
    $byCode = collect($all)->keyBy('code');
    expect($byCode['EXHAUST01']['state'])->toBe(CouponState::Exhausted->value)
        ->and($byCode['EXHAUST01']['used_count'])->toBe(5)
        ->and($byCode['EXHAUST01']['max_uses'])->toBe(5)
        ->and($byCode['BOTH0001']['state'])->toBe('expired')
        ->and($byCode['INEXP001']['state'])->toBe('inactive');

    vvAdminGet('/admin/coupons?state=bogus')->assertStatus(422)->assertJsonValidationErrors('state');
    vvAdminGet('/admin/coupons?per_page=7')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

test('danh sach: tim theo code/ten (escape LIKE), co phan trang va dem pham vi', function () {
    vvCouponActor();
    $course = Course::factory()->published()->create();
    $a = Coupon::factory()->create(['code' => 'SUMMER26', 'name' => 'Hè rực rỡ']);
    $a->courses()->attach($course->id);
    Coupon::factory()->create(['code' => 'WINTER26', 'name' => 'Đông ấm']);

    $r = vvAdminGet('/admin/coupons?q=summer')->assertOk();
    expect($r->json('data'))->toHaveCount(1)->and($r->json('data.0.courses_count'))->toBe(1);
    expect(vvAdminGet('/admin/coupons?q='.urlencode('hè'))->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/coupons?q=%25')->json('data'))->toHaveCount(0);
    $r->assertJsonStructure(['data', 'links', 'meta']);
});

test('xoa: ma chua dung xoa cung duoc (204, audit, don pivot); ma da dung 409 COUPON_IN_USE', function () {
    vvCouponActor();
    $course = Course::factory()->published()->create();
    $fresh = Coupon::factory()->create(['code' => 'DELME001']);
    $fresh->courses()->attach($course->id);
    $used = Coupon::factory()->create(['code' => 'KEEPME01', 'used_count' => 1]);

    vvCouponSend('DELETE', "/admin/coupons/{$fresh->id}")->assertNoContent();
    expect(Coupon::query()->whereKey($fresh->id)->exists())->toBeFalse()
        ->and(DB::table('coupon_course')->where('coupon_id', $fresh->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'coupon.delete')->count())->toBe(1);

    vvCouponSend('DELETE', "/admin/coupons/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'COUPON_IN_USE');
    expect(Coupon::query()->whereKey($used->id)->exists())->toBeTrue();

    vvCouponSend('DELETE', "/admin/coupons/{$fresh->id}")->assertNotFound();
});

test('xoa chuyen de khong bi chan boi pham vi ma: go khoi coupon_subject', function () {
    $subject = Subject::factory()->create();
    $coupon = Coupon::factory()->restricted()->create();
    $coupon->subjects()->attach($subject->id);

    $subject->delete();

    expect(DB::table('coupon_subject')->where('coupon_id', $coupon->id)->count())->toBe(0);
});

test('DB: unique code khong phan biet hoa thuong; CHECK percent 1..100 va 100% phai gioi han', function () {
    Coupon::factory()->create(['code' => 'DUPCODE1']);
    $row = fn (array $o) => Coupon::factory()->make($o)->getAttributes() + [];

    $insert = function (array $over): void {
        $attrs = Coupon::factory()->make($over)->getAttributes();
        $attrs['discount_type'] = $over['discount_type'] ?? 'percent';
        $attrs['status'] = 'active';
        $attrs['created_by'] = User::factory()->admin()->create()->id;
        $attrs['valid_from'] = now()->subDay()->toDateTimeString();
        $attrs['valid_until'] = isset($over['valid_until']) ? now()->addDay()->toDateTimeString() : null;
        $attrs['created_at'] = $attrs['updated_at'] = now()->toDateTimeString();
        DB::table('coupons')->insert($attrs);
    };

    expect(fn () => $insert(['code' => 'dupcode1']))->toThrow(QueryException::class, '1062');
    expect(fn () => $insert(['code' => 'PCT0', 'discount_value' => 0]))->toThrow(QueryException::class, 'chk_coupons_percent_range');
    expect(fn () => $insert(['code' => 'PCT101', 'discount_value' => 101]))->toThrow(QueryException::class, 'chk_coupons_percent_range');
    expect(fn () => $insert(['code' => 'PCT100A', 'discount_value' => 100]))->toThrow(QueryException::class, 'chk_coupons_full_discount_limited');
    expect(fn () => $insert(['code' => 'PCT100B', 'discount_value' => 100, 'max_uses' => 5]))->toThrow(QueryException::class, 'chk_coupons_full_discount_limited');
    expect(fn () => $insert(['code' => 'PCT100C', 'discount_value' => 100, 'valid_until' => 1]))->toThrow(QueryException::class, 'chk_coupons_full_discount_limited');

    $insert(['code' => 'PCT100D', 'discount_value' => 100, 'max_uses' => 5, 'valid_until' => 1]);
    // fixed_amount > 100 được phép.
    $insert(['code' => 'FIX50000', 'discount_type' => 'fixed_amount', 'discount_value' => 50000]);
    expect(Coupon::query()->count())->toBe(3);
});

test('Coupon::state va normalizeCode', function () {
    expect(Coupon::normalizeCode("  toan2026\n"))->toBe('TOAN2026');
    $c = Coupon::factory()->make(['valid_until' => now()->addDay(), 'max_uses' => 3, 'used_count' => 2]);
    expect($c->state())->toBe(CouponState::Active);
    $c->used_count = 3;
    expect($c->state())->toBe(CouponState::Exhausted);
});

test('counters:recount: bo qua khi chua co coupon_usages; sua used_count lech, --dry-run khong sua', function () {
    $c1 = Coupon::factory()->create(['used_count' => 7]);
    $c2 = Coupon::factory()->create(['used_count' => 0]);

    // T18 chưa tạo bảng: không đụng used_count.
    test()->artisan('counters:recount')->assertSuccessful();
    expect($c1->fresh()->used_count)->toBe(7);

    // Bảng tạm (không commit ngầm như DDL thường) mô phỏng coupon_usages của T18.
    DB::statement('CREATE TEMPORARY TABLE coupon_usages (id BIGINT AUTO_INCREMENT PRIMARY KEY, coupon_id BIGINT NOT NULL, user_id BIGINT NOT NULL)');

    try {
        DB::table('coupon_usages')->insert([
            ['coupon_id' => $c2->id, 'user_id' => 1],
            ['coupon_id' => $c2->id, 'user_id' => 2],
        ]);

        test()->artisan('counters:recount --dry-run')->assertSuccessful();
        expect($c1->fresh()->used_count)->toBe(7)->and($c2->fresh()->used_count)->toBe(0);

        test()->artisan('counters:recount')->assertSuccessful();
        expect($c1->fresh()->used_count)->toBe(0)->and($c2->fresh()->used_count)->toBe(2);
    } finally {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS coupon_usages');
    }
});

test('DB: CHECK valid_until >= valid_from va discount_type hop le', function () {
    $insert = function (array $over): void {
        $attrs = [
            'code' => 'CHK'.strtoupper(substr(md5(json_encode($over)), 0, 6)), 'discount_type' => 'percent', 'discount_value' => 10,
            'valid_from' => '2026-10-10 00:00:00', 'valid_until' => null, 'status' => 'active',
            'created_by' => User::factory()->admin()->create()->id,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ];
        DB::table('coupons')->insert($over + $attrs);
    };

    expect(fn () => $insert(['valid_until' => '2026-10-09 00:00:00']))->toThrow(QueryException::class, 'chk_coupons_dates');
    expect(fn () => $insert(['discount_type' => 'bogo']))->toThrow(QueryException::class, 'chk_coupons_type');

    $insert(['valid_until' => '2026-10-10 00:00:00']);
    $insert(['valid_until' => null, 'discount_type' => 'fixed_amount', 'discount_value' => 5000]);
    expect(Coupon::query()->count())->toBe(2);
});
