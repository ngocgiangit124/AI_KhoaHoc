<?php

use App\Models\Coupon;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../T04/helpers.php';

function vvIpCapApply(string $code)
{
    return test()->putJson(vvApiUrl('/cart/coupon'), ['code' => $code], vvWebHeaders());
}

beforeEach(function () {
    RateLimiter::clear('coupon-fail-ip:127.0.0.1');
    config(['orders.coupon_fails_per_ip_per_day' => 3]);
});

test('T16-1/2: nhieu tai khoan cung 1 IP nhap sai qua tran theo IP -> 429 TOO_MANY_ATTEMPTS, ke ca hoc sinh khac', function () {
    vvActAsStudent(User::factory()->student()->create());
    for ($i = 0; $i < 3; $i++) {
        vvIpCapApply('NOPE0000'.$i)->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
    }

    // Tài khoản khác (mới tạo), cùng IP: bị chặn dù chưa sai lần nào theo tài khoản.
    app('auth')->forgetGuards();
    $second = vvActAsStudent(User::factory()->student()->create());
    vvIpCapApply('NOPE00009')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');
    expect(RateLimiter::attempts('coupon-fail:'.$second->id))->toBe(0)
        ->and(RateLimiter::attempts('coupon-fail-ip:127.0.0.1'))->toBe(3);
});

test('T16-1/2: ap ma dung khong ton luot IP; dat 0 de tat tran IP', function () {
    vvActAsStudent(User::factory()->student()->create());
    test()->postJson(vvApiUrl('/cart/items'), ['course_id' => Course::factory()->published()->paid(100000)->create()->id], vvWebHeaders())->assertSuccessful();
    Coupon::factory()->percent(10)->create(['code' => 'GOODIP123']);

    for ($i = 0; $i < 4; $i++) {
        vvIpCapApply('GOODIP123')->assertOk();
    }
    expect(RateLimiter::attempts('coupon-fail-ip:127.0.0.1'))->toBe(0);

    vvIpCapApply('NOPE00000')->assertStatus(422);
    expect(RateLimiter::attempts('coupon-fail-ip:127.0.0.1'))->toBe(1);

    config(['orders.coupon_fails_per_ip_per_day' => 0]);
    RateLimiter::clear('coupon-fail-ip:127.0.0.1');
    for ($i = 0; $i < 4; $i++) {
        vvIpCapApply('NOPE0000'.$i)->assertStatus(422);
    }
    expect(RateLimiter::attempts('coupon-fail-ip:127.0.0.1'))->toBe(0);
});

test('R3: IP cham tran ghi log warning chi co IP bam, khong co ma giam gia/IP that; da vuot tran thi khong hit them', function () {
    $logs = [];
    Log::listen(function ($e) use (&$logs): void {
        $logs[] = $e;
    });
    vvActAsStudent(User::factory()->student()->create());
    for ($i = 0; $i < 3; $i++) {
        vvIpCapApply('NOPE0000'.$i)->assertStatus(422);
    }

    vvIpCapApply('SECRETCODE1')->assertStatus(429);
    vvIpCapApply('SECRETCODE2')->assertStatus(429);

    // Kiểm chỉ-đọc trước: bị chặn thì không tăng bộ đếm nào.
    expect(RateLimiter::attempts('coupon-fail-ip:127.0.0.1'))->toBe(3);
    $warn = collect($logs)->filter(fn ($e) => $e->message === 'coupon.ip_fail_cap_reached');
    expect($warn)->toHaveCount(2);
    $dump = json_encode($warn->map(fn ($e) => $e->context)->all());
    // Cụm 3 L3: HMAC theo APP_KEY (không dò ngược được bằng SHA-256 thô), ổn định với cùng IP.
    $hashes = $warn->map(fn ($e) => $e->context['ip_hash'])->unique();
    expect($hashes)->toHaveCount(1)
        ->and($hashes->first())->toBe(substr(hash_hmac('sha256', 'coupon-fail-ip:127.0.0.1', (string) config('app.key')), 0, 16))
        ->and($hashes->first())->not->toBe(substr(hash('sha256', 'coupon-fail-ip:127.0.0.1'), 0, 16));
    expect($dump)->toContain('ip_hash')->not->toContain('SECRETCODE')->not->toContain('127.0.0.1');
});

test('L2 cum 3: limiter cart 60/phut/nguoi -> request thu 61 vao route gio hang tra 429 kem Retry-After', function () {
    vvActAsStudent(User::factory()->student()->create());

    for ($i = 0; $i < 60; $i++) {
        test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk();
    }

    test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertStatus(429)->assertHeader('Retry-After');
    test()->deleteJson(vvApiUrl('/cart/coupon'), [], vvWebHeaders())->assertStatus(429);
});
