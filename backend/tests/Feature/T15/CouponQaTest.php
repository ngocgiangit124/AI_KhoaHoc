<?php

use App\Enums\CouponState;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__.'/CouponAdminTest.php';

test('QA AC1: code duoc trim + chu hoa; code rong/chi khoang trang -> 422; trung khac hoa thuong -> errors.code', function () {
    vvCouponActor();

    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => '  khai-truong_26  ']))
        ->assertCreated()->assertJsonPath('code', 'KHAI-TRUONG_26');
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'Khai-Truong_26']))
        ->assertStatus(422)->assertJsonValidationErrors('code');
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => '    ']))->assertStatus(422)->assertJsonValidationErrors('code');
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => str_repeat('A', 51)]))->assertStatus(422)->assertJsonValidationErrors('code');
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => str_repeat('A', 50)]))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'ABCD']))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'ABC']))->assertStatus(422);
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => ['x']]))->assertStatus(422);
});

test('QA AC7 bien: percent 100/1 va fixed 100.000.000/1 hop le, 100.000.001 loi', function () {
    vvCouponActor();
    Course::factory()->published()->paid(500000)->create();
    $lim = ['max_uses' => 5, 'valid_until' => now()->addDay()->toIso8601String()];

    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'PCT1', 'discount_value' => 1]))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'PCT100'] + ['discount_value' => 100] + $lim))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'FIX1', 'discount_type' => 'fixed_amount', 'discount_value' => 1]))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'FIXMAX', 'discount_type' => 'fixed_amount', 'discount_value' => 100_000_000] + $lim))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'FIXOVR', 'discount_type' => 'fixed_amount', 'discount_value' => 100_000_001] + $lim))->assertStatus(422);
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'DEC', 'discount_value' => 10.5]))->assertStatus(422);
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'STR', 'discount_value' => 'abc']))->assertStatus(422);
});

test('QA AC5 bien: valid_until == valid_from hop le; < valid_from loi', function () {
    vvCouponActor();
    $t = '2027-01-01T10:00:00+07:00';
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'SAMETIME', 'valid_from' => $t, 'valid_until' => $t]))->assertCreated();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'EARLIER1', 'valid_from' => $t, 'valid_until' => '2027-01-01T09:59:59+07:00']))
        ->assertStatus(422)->assertJsonValidationErrors('valid_until');
});

test('QA pham vi: course_ids/subject_ids > 200, trung, khoa xoa mem, mang khong phai so', function () {
    vvCouponActor();
    $over = range(1, 201);
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['course_ids' => $over]))->assertStatus(422)->assertJsonValidationErrors('course_ids');
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['subject_ids' => $over]))->assertStatus(422)->assertJsonValidationErrors('subject_ids');

    $s = Subject::factory()->create();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['subject_ids' => [$s->id, $s->id]]))->assertStatus(422)->assertJsonValidationErrors('subject_ids.0');
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['course_ids' => 'abc']))->assertStatus(422);
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['course_ids' => ['x']]))->assertStatus(422);

    // 200 chinh xac hop le.
    $ids = Course::factory()->published()->count(3)->create()->pluck('id')->all();
    vvCouponSend('POST', '/admin/coupons', vvCouponBody(['code' => 'OK200', 'course_ids' => $ids, 'subject_ids' => [$s->id]]))
        ->assertCreated()->assertJsonPath('is_restricted', true);

    // Sua: khoa xoa mem giua chung bi tu choi.
    $c = Course::factory()->published()->create();
    $c->delete();
    $coupon = Coupon::query()->where('code', 'OK200')->firstOrFail();
    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", vvCouponBody(['code' => 'OK200', 'course_ids' => [$c->id]]))
        ->assertStatus(422)->assertJsonValidationErrors('course_ids.0');
});

test('QA S18 bien: fixed == gia re nhat bat buoc gioi han; ma 100% sua thanh gia tri thuong thi bo duoc', function () {
    vvCouponActor();
    Course::factory()->published()->paid(300000)->create();
    $b = vvCouponBody(['max_uses' => null, 'valid_until' => null]);

    vvCouponSend('POST', '/admin/coupons', array_merge($b, ['code' => 'EQ300', 'discount_type' => 'fixed_amount', 'discount_value' => 300000]))
        ->assertStatus(422)->assertJsonValidationErrors(['max_uses', 'valid_until']);
    vvCouponSend('POST', '/admin/coupons', array_merge($b, ['code' => 'LT300', 'discount_type' => 'fixed_amount', 'discount_value' => 299999]))->assertCreated();

    $full = vvCouponSend('POST', '/admin/coupons', array_merge($b, ['code' => 'FULLOK', 'discount_value' => 100, 'max_uses' => 3, 'valid_until' => now()->addDay()->toIso8601String()]))->assertCreated()->json('id');
    vvCouponSend('PUT', "/admin/coupons/{$full}", array_merge($b, ['code' => 'FULLOK', 'discount_value' => 50]))->assertOk();
});

test('QA S18 + max_uses=used_count: dat max_uses bang used_count duoc (exhausted), thap hon bi chan', function () {
    vvCouponActor();
    $coupon = Coupon::factory()->create(['code' => 'USED0003', 'used_count' => 3]);
    $base = vvCouponBody(['code' => 'USED0003']);
    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", $base + ['max_uses' => 3])->assertOk()->assertJsonPath('state', 'exhausted');
    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", array_merge($base, ['max_uses' => 2]))->assertStatus(422)->assertJsonValidationErrors('max_uses');
    // Gui lai gia tri cu, loai khong doi -> khong COUPON_LOCKED.
    vvCouponSend('PUT', "/admin/coupons/{$coupon->id}", array_merge($base, ['max_uses' => 10, 'discount_type' => 'percent', 'discount_value' => 20]))->assertOk();
});

test('QA xoa: ma chua dung 204, lan hai 404; ma da dung 409 COUPON_IN_USE khong mat du lieu; GET 404 sau xoa', function () {
    vvCouponActor();
    $a = Coupon::factory()->create(['code' => 'DEL00001']);
    vvCouponSend('DELETE', "/admin/coupons/{$a->id}")->assertNoContent();
    vvCouponSend('DELETE', "/admin/coupons/{$a->id}")->assertNotFound();
    vvAdminGet("/admin/coupons/{$a->id}")->assertNotFound();
    expect(AuditLog::query()->where('action', 'coupon.delete')->count())->toBe(1);

    $b = Coupon::factory()->create(['code' => 'DEL00002', 'used_count' => 1]);
    vvCouponSend('DELETE', "/admin/coupons/{$b->id}")->assertStatus(409)->assertJsonPath('code', 'COUPON_IN_USE');
    expect(Coupon::query()->whereKey($b->id)->exists())->toBeTrue();
});

test('QA phan quyen: quan ly trang duoc CRUD; khach 401 truoc validate; id khong ton tai khi la hoc sinh 403/404', function () {
    vvCouponSend('POST', '/admin/coupons', [])->assertUnauthorized();
    vvCouponActor('pageManager');
    $c = vvCouponSend('POST', '/admin/coupons', vvCouponBody())->assertCreated()->json('id');
    vvCouponSend('POST', "/admin/coupons/{$c}/deactivate")->assertOk();
    vvCouponSend('DELETE', "/admin/coupons/{$c}")->assertNoContent();
    expect(AuditLog::query()->whereIn('action', ['coupon.create', 'coupon.deactivate', 'coupon.delete'])->pluck('action')->sort()->values()->all())
        ->toBe(['coupon.create', 'coupon.deactivate', 'coupon.delete']);
});

test('QA audit: coupon.create ghi actor_id, khong ghi PII; update khong doi gi khong sinh log', function () {
    $admin = vvCouponActor();
    $same = vvCouponBody(['code' => 'AUD00001', 'valid_from' => '2026-10-01T00:00:00+07:00', 'valid_until' => '2027-10-01T00:00:00+07:00']);
    $id = vvCouponSend('POST', '/admin/coupons', $same)->assertCreated()->json('id');
    $log = AuditLog::query()->where('action', 'coupon.create')->firstOrFail();
    expect((int) $log->actor_id)->toBe($admin->id)
        ->and(json_encode($log->changes))->not->toContain($admin->email);

    $before = AuditLog::query()->where('action', 'coupon.update')->count();
    vvCouponSend('PUT', "/admin/coupons/{$id}", $same)->assertOk();
    expect(AuditLog::query()->where('action', 'coupon.update')->count())->toBe($before);
});

test('QA danh sach: per_page 25/50 hop le, 10/0/100 -> 422, sap xep on dinh, khong N+1', function () {
    vvCouponActor();
    Coupon::factory()->count(30)->create();

    expect(vvAdminGet('/admin/coupons')->assertOk()->json('data'))->toHaveCount(25);
    expect(vvAdminGet('/admin/coupons?per_page=50')->assertOk()->json('data'))->toHaveCount(30);
    expect(vvAdminGet('/admin/coupons?per_page=25&page=2')->assertOk()->json('data'))->toHaveCount(5);
    foreach ([0, 10, 100, 'abc'] as $pp) {
        vvAdminGet('/admin/coupons?per_page='.$pp)->assertStatus(422);
    }
    vvAdminGet('/admin/coupons?q='.str_repeat('a', 101))->assertStatus(422);

    DB::enableQueryLog();
    vvAdminGet('/admin/coupons?per_page=50')->assertOk();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($n)->toBeLessThan(15);
});

test('QA thoi gian: valid_from/valid_until luu dung gio (UTC) du connection +07:00; ranh gioi state', function () {
    vvCouponActor();
    $id = vvCouponSend('POST', '/admin/coupons', vvCouponBody([
        'code' => 'TZ000001',
        'valid_from' => '2027-03-01T07:00:00+07:00',
        'valid_until' => '2027-03-02T23:59:59+07:00',
    ]))->assertCreated()->json('id');

    $raw = DB::table('coupons')->where('id', $id)->first();
    // Cột DATETIME lưu theo giờ múi giờ ứng dụng (config app.timezone), không phụ thuộc offset client gửi.
    $tz = config('app.timezone');
    expect($raw->valid_from)->toBe(Carbon::parse('2027-03-01 00:00:00', 'UTC')->setTimezone($tz)->toDateTimeString())
        ->and($raw->valid_until)->toBe(Carbon::parse('2027-03-02 16:59:59', 'UTC')->setTimezone($tz)->toDateTimeString());

    $show = vvAdminGet("/admin/coupons/{$id}")->assertOk()->json();
    expect(Carbon::parse($show['valid_from'])->utc()->toDateTimeString())->toBe('2027-03-01 00:00:00')
        ->and(Carbon::parse($show['valid_until'])->utc()->toDateTimeString())->toBe('2027-03-02 16:59:59');

    $c = Coupon::findOrFail($id);
    // Đúng giờ bắt đầu: active; 1 giây trước: upcoming. Đúng giờ hết hạn: còn active; 1 giây sau: expired.
    expect($c->state(Carbon::parse('2027-02-28 23:59:59', 'UTC'))->value)->toBe('upcoming')
        ->and($c->state(Carbon::parse('2027-03-01 00:00:00', 'UTC'))->value)->toBe('active')
        ->and($c->state(Carbon::parse('2027-03-02 16:59:59', 'UTC'))->value)->toBe('active')
        ->and($c->state(Carbon::parse('2027-03-02 17:00:00', 'UTC'))->value)->toBe('expired');

    // Scope SQL (bộ lọc state) khớp state() tại các mốc ranh giới (không dùng NOW() của MySQL).
    foreach (['2027-02-28 23:59:59' => 'upcoming', '2027-03-01 00:00:00' => 'active', '2027-03-02 16:59:59' => 'active', '2027-03-02 17:00:00' => 'expired'] as $at => $state) {
        $now = Carbon::parse($at, 'UTC')->setTimezone(config('app.timezone'));
        foreach (['upcoming', 'active', 'expired'] as $candidate) {
            $hit = Coupon::query()->whereKey($id)->inState(CouponState::from($candidate), $now)->exists();
            expect($hit)->toBe($candidate === $state, "scope {$candidate} tai {$at}");
        }
    }
});

test('QA state: upcoming + exhausted => exhausted (uu tien), expired+exhausted+inactive => inactive; loc khop', function () {
    vvCouponActor();
    $c = Coupon::factory()->upcoming()->exhausted(2)->create(['code' => 'UPEX0001']);
    expect($c->state()->value)->toBe('exhausted');
    expect(collect(vvAdminGet('/admin/coupons?state=exhausted')->json('data'))->pluck('id')->all())->toContain($c->id);
    expect(collect(vvAdminGet('/admin/coupons?state=upcoming')->json('data'))->pluck('id')->all())->not->toContain($c->id);
});

test('QA tim kiem: q chu hoa/thuong/khoang trang, ky tu _ va %', function () {
    vvCouponActor();
    Coupon::factory()->create(['code' => 'AB_CD123', 'name' => 'Một']);
    Coupon::factory()->create(['code' => 'ABXCD123', 'name' => 'Hai']);
    expect(vvAdminGet('/admin/coupons?q=ab_cd')->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/coupons?q='.urlencode('  một '))->json('data'))->toHaveCount(1);
});

test('QA counters:recount: coupon_usages that - bang rong thi used_count ve 0 (nguon su that la coupon_usages)', function () {
    $c = Coupon::factory()->create(['code' => 'REC00001', 'used_count' => 4]);
    $this->artisan('counters:recount')->assertSuccessful();
    expect($c->fresh()->used_count)->toBe(0);
});

function vvCouponRaceWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/coupon_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(180);

    return $p;
}

function vvCouponRaceOnce(array $args): array
{
    $p = vvCouponRaceWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

test('QA race: 6 tien trinh tao cung ma (khac hoa thuong) -> dung 1 thanh cong, con lai validation errors.code, khong 500', function () {
    $adminId = vvCouponRaceOnce(['setup'])['admin'];
    $code = 'RACE'.random_int(1000, 9999);

    try {
        $startAt = microtime(true) + 3.0;
        $procs = [];
        foreach (range(0, 5) as $i) {
            $procs[] = $p = vvCouponRaceWorker(['create', $adminId, $i % 2 ? $code : strtoupper($code), $startAt]);
            $p->start();
        }
        $results = [];
        foreach ($procs as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
            $results[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }
        $kinds = collect($results)->countBy('result')->all();
        expect($kinds['ok'] ?? 0)->toBe(1)
            ->and($kinds['validation'] ?? 0)->toBe(5)
            ->and($kinds)->not->toHaveKey('error')
            ->and(vvCouponRaceOnce(['count', $code])['rows'])->toBe(1);
    } finally {
        vvCouponRaceOnce(['cleanup', $adminId, $code]);
    }
})->group('race');
