<?php

use App\Exceptions\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

test('404 tra dung envelope JSON', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/khong-ton-tai');

    $response->assertNotFound();
    $response->assertJsonStructure(['message', 'code', 'request_id']);
    $response->assertJson(['code' => 'NOT_FOUND']);
});

test('422 ValidationException tra dung envelope VALIDATION_ERROR', function () {
    Route::domain(config('app.api_host'))->post('/__test/validate', function () {
        request()->validate(['name' => 'required|string']);

        return response()->json(['ok' => true]);
    });

    $response = $this->postJson('http://'.config('app.api_host').'/__test/validate', []);

    $response->assertStatus(422);
    $response->assertJson(['code' => 'VALIDATION_ERROR']);
    $response->assertJsonStructure(['message', 'code', 'errors' => ['name'], 'request_id']);
});

test('DomainException tra dung code va status tuy chinh', function () {
    Route::domain(config('app.api_host'))->get('/__test/domain-exception', function () {
        throw new DomainException('COUPON_INVALID', 'Mã giảm giá không hợp lệ.', 422);
    });

    $response = $this->getJson('http://'.config('app.api_host').'/__test/domain-exception');

    $response->assertStatus(422);
    $response->assertJson([
        'code' => 'COUPON_INVALID',
        'message' => 'Mã giảm giá không hợp lệ.',
    ]);
});

test('405 Method Not Allowed tra dung envelope JSON', function () {
    Route::domain(config('app.api_host'))->get('/__test/only-get', fn () => response()->json(['ok' => true]));

    $response = $this->postJson('http://'.config('app.api_host').'/__test/only-get', []);

    $response->assertStatus(405);
    $response->assertJsonStructure(['message', 'code', 'request_id']);
    $response->assertJson(['code' => 'METHOD_NOT_ALLOWED']);

    $body = $response->getContent();
    expect($body)->not->toContain('.php')
        ->and($body)->not->toContain('Stack trace')
        ->and($body)->not->toContain('Illuminate\\');
});

test('429 TooManyRequests (throttle) tra dung envelope JSON va khong lo noi bo', function () {
    // BUG (xem docs/qa/T01-T02.md BUG-2): middleware `throttle` mac dinh dung
    // Cache store config('cache.limiter') = 'redis-limiter' — Redis THAT
    // (REDIS_LIMITER_DB) — KHONG duoc phpunit.xml cach ly nhu DB_DATABASE
    // (T01 "DB test rieng biet" chi ap dung cho MySQL). Va khoa rate-limit mac
    // dinh cua ThrottleRequests chi la sha1(domain|ip) — KHONG gom URI — nen
    // moi route throttle:x,y tren cung host/IP dung chung 1 bo dem, du duong
    // dan test co random hay khong. Phai flush store nay truoc khi test de co
    // ket qua on dinh — KHONG khac phuc duoc goc trong pham vi tests/ (can
    // tach CACHE_LIMITER rieng cho testing trong phpunit.xml, ngoai quyen QA).
    Cache::store(config('cache.limiter'))->flush();

    Route::domain(config('app.api_host'))
        ->middleware('throttle:1,1')
        ->get('/__test/throttled', fn () => response()->json(['ok' => true]));

    $first = $this->getJson('http://'.config('app.api_host').'/__test/throttled');
    $first->assertOk();

    $second = $this->getJson('http://'.config('app.api_host').'/__test/throttled');

    $second->assertStatus(429);
    $second->assertJsonStructure(['message', 'code', 'request_id']);
    $second->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);

    $body = $second->getContent();
    expect($body)->not->toContain('.php')
        ->and($body)->not->toContain('Stack trace');
});

test('500 co request_id va khong lo stack trace khi APP_DEBUG=false', function () {
    config(['app.debug' => false]);

    Route::domain(config('app.api_host'))->get('/__test/boom', function () {
        throw new RuntimeException('chi tiet nhay cam khong duoc lo ra ngoai');
    });

    $response = $this->getJson('http://'.config('app.api_host').'/__test/boom');

    $response->assertStatus(500);
    $response->assertJsonStructure(['message', 'code', 'request_id']);

    $body = $response->getContent();
    expect($body)->not->toContain('chi tiet nhay cam')
        ->and($body)->not->toContain('.php')
        ->and($body)->not->toContain('Stack trace');
});
