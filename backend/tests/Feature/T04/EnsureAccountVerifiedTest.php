<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureAccountVerified;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * T04 — middleware `account.verified` (api-contract §1.7 `403
 * ACCOUNT_NOT_VERIFIED`). Chưa gắn vào route nào ở T04 (những route cần nó —
 * checkout, đăng ký học miễn phí — thuộc các task sau); test trực tiếp
 * middleware để đảm bảo hành vi đúng khi được gắn sau này.
 */
test('tai khoan chua xac thuc (ca 2 kenh) bi chan 403 ACCOUNT_NOT_VERIFIED', function () {
    $user = User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => null]);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureAccountVerified;

    $error = null;

    try {
        $middleware->handle($request, fn () => response()->json(['ok' => true]));
    } catch (DomainException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->code())->toBe('ACCOUNT_NOT_VERIFIED');
    expect($error->status())->toBe(403);
});

test('tai khoan da xac thuc email thi duoc di qua', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureAccountVerified;
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

test('tai khoan da xac thuc SDT (khong can email) thi duoc di qua', function () {
    $user = User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => now()]);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureAccountVerified;
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

test('chua dang nhap thi bi chan', function () {
    $request = Request::create('/test');
    $request->setUserResolver(fn () => null);

    $middleware = new EnsureAccountVerified;

    expect(fn () => $middleware->handle($request, fn () => response()->json(['ok' => true])))
        ->toThrow(DomainException::class);
});
