<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../T04/helpers.php';

/**
 * QA "Sửa lỗi nhỏ 3" — limiter `cart` (60 request/phút/người dùng) dùng chung cho mọi route giỏ hàng.
 */
test('limiter cart: 60 request dau 200, request thu 61 -> 429 TOO_MANY_ATTEMPTS co Retry-After', function () {
    $student = vvActAsStudent(User::factory()->student()->create());
    RateLimiter::clear('cart:'.$student->getKey());

    foreach (range(1, 60) as $i) {
        test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk();
    }

    $over = test()->getJson(vvApiUrl('/cart'), vvWebHeaders());
    $over->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');
    expect((int) $over->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

test('limiter cart: chung khoa cho cac route gio hang (them/xoa coupon cung bi 429), nguoi khac khong bi anh huong', function () {
    $a = vvActAsStudent(User::factory()->student()->create());
    foreach (range(1, 60) as $i) {
        test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk();
    }

    test()->deleteJson(vvApiUrl('/cart/coupon'), [], vvWebHeaders())->assertStatus(429);
    test()->postJson(vvApiUrl('/cart/items'), ['course_id' => 1], vvWebHeaders())->assertStatus(429);

    app('auth')->forgetGuards();
    vvActAsStudent(User::factory()->student()->create());
    test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk();
    expect($a->getKey())->not->toBeNull();
});

test('limiter cart: het cua so 60 giay thi dung lai duoc', function () {
    $student = vvActAsStudent(User::factory()->student()->create());
    foreach (range(1, 60) as $i) {
        test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk();
    }
    test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertStatus(429);

    $this->travel(61)->seconds();

    test()->getJson(vvApiUrl('/cart'), vvWebHeaders())->assertOk();
    expect($student->getKey())->not->toBeNull();
});
