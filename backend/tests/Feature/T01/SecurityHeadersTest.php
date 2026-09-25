<?php

use Illuminate\Support\Facades\Route;

test('response co X-Request-Id va X-Content-Type-Options', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

test('route co middleware no_store tra Cache-Control no-store private', function () {
    Route::domain(config('app.api_host'))
        ->middleware('no_store')
        ->get('/__test/no-store', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('http://'.config('app.api_host').'/__test/no-store');

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('route co middleware no_store tra Vary Cookie, Origin (S16)', function () {
    Route::domain(config('app.api_host'))
        ->middleware('no_store')
        ->get('/__test/no-store-vary', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('http://'.config('app.api_host').'/__test/no-store-vary');

    $response->assertOk();
    $response->assertHeader('Vary', 'Cookie, Origin');
});

test('Strict-Transport-Security chi xuat hien khi request qua HTTPS', function () {
    Route::domain(config('app.api_host'))
        ->get('/__test/https-only', fn () => response()->json(['ok' => true]));

    $plain = $this->getJson('http://'.config('app.api_host').'/__test/https-only');
    $plain->assertOk();
    $plain->assertHeaderMissing('Strict-Transport-Security');

    $secure = $this->get('https://'.config('app.api_host').'/__test/https-only', ['Accept' => 'application/json']);
    $secure->assertOk();
    expect($secure->headers->get('Strict-Transport-Security'))->not->toBeEmpty();
});
