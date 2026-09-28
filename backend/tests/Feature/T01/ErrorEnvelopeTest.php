<?php

use App\Exceptions\DomainException;
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

    // L6 (review bảo mật T01/T02) — giữ header `Allow` của MethodNotAllowedHttpException.
    expect($response->headers->get('Allow'))->not->toBeNull();
});

test('429 TooManyRequests (throttle) tra dung envelope JSON va khong lo noi bo', function () {
    // BUG-2 (QA T01/T02) da duoc va o goc: phpunit.xml gio ep CACHE_LIMITER=array
    // (store trong tien trinh, khong persist qua cac lan chay test) — khong con
    // can flush() Redis truoc moi test throttle nhu ban va tam truoc day.
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

    // L6 (review bảo mật T01/T02) — giữ header `Retry-After` của ThrottleRequestsException.
    expect($second->headers->get('Retry-After'))->not->toBeNull();
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
