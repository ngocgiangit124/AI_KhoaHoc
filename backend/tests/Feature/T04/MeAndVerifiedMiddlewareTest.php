<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/helpers.php';

function vvMe()
{
    return test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders());
}

test('GET /auth/me tra dung shape user cua contract, phang, khong lo field khac', function () {
    $user = vvOtpStudent(['grade_level' => 9]);

    vvMe()->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => 'hoc_sinh',
            'grade_level' => 9,
            'is_verified' => false,
            'parent_consent_status' => 'not_required',
        ])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('GET /auth/me khong lo thong tin phu huynh du la hoc sinh duoi tuoi', function () {
    vvOtpStudent(['parent_email' => 'ph@example.com', 'parent_phone' => '0911222333']);

    $body = vvMe()->assertOk()->getContent();

    expect($body)->not->toContain('ph@example.com')->not->toContain('parent_email')->not->toContain('parent_phone');
});

test('is_verified phan anh OTP: email hoac SDT da xac thuc', function (array $attrs, bool $expected) {
    vvOtpStudent($attrs);

    vvMe()->assertOk()->assertJson(['is_verified' => $expected]);
})->with([
    'chua xac thuc' => [[], false],
    'email da xac thuc' => [['email_verified_at' => now()], true],
    'chi SDT da xac thuc' => [['phone_verified_at' => now()], true],
]);

test('GET /auth/me: khach -> 401; giao vien -> 403; bi khoa -> 403', function () {
    vvMe()->assertStatus(401)->assertJson(['code' => 'UNAUTHENTICATED']);

    $this->actingAs(User::factory()->teacher()->create());
    vvMe()->assertStatus(403);

    $this->actingAs(User::factory()->locked()->create());
    vvMe()->assertStatus(403)->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('route T04 nam trong nhom student (middleware chuan + role:hoc_sinh)', function () {
    $expected = [
        'api/v1/auth/me' => 'GET',
        'api/v1/auth/otp/send' => 'POST',
        'api/v1/auth/otp/verify' => 'POST',
        'api/v1/auth/contact' => 'PUT',
    ];

    foreach ($expected as $uri => $method) {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));
        expect($route)->not->toBeNull("Thiếu route {$method} {$uri}");

        $middleware = $route->gatherMiddleware();
        foreach (['auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh'] as $alias) {
            expect($middleware)->toContain($alias);
        }
    }
});

describe('account.verified', function () {
    beforeEach(function () {
        Route::domain(config('app.api_host'))
            ->middleware(['auth:sanctum', 'account.active', 'account.verified'])
            ->get('/__test/verified-only', fn () => response()->json(['ok' => true]));
    });

    test('AC9 chua xac thuc -> 403 ACCOUNT_NOT_VERIFIED', function () {
        $this->actingAs(User::factory()->create());

        $this->getJson('http://'.config('app.api_host').'/__test/verified-only')
            ->assertStatus(403)->assertJson(['code' => 'ACCOUNT_NOT_VERIFIED']);
    });

    test('da xac thuc (email hoac SDT) -> cho qua', function (string $column) {
        $this->actingAs(User::factory()->create([$column => now()]));

        $this->getJson('http://'.config('app.api_host').'/__test/verified-only')->assertOk();
    })->with(['email_verified_at', 'phone_verified_at']);

    test('doi email -> mat xac thuc -> bi chan lai', function () {
        vvFakeOtp();
        $user = vvOtpStudent(['email_verified_at' => now()]);
        $url = 'http://'.config('app.api_host').'/__test/verified-only';

        $this->getJson($url)->assertOk();
        vvContactUpdate(['email' => 'doi@example.com'])->assertOk();
        $this->actingAs($user->fresh());
        $this->getJson($url)->assertStatus(403);
    });

    test('khach -> 401 (auth:sanctum chay truoc)', function () {
        $this->getJson('http://'.config('app.api_host').'/__test/verified-only')->assertStatus(401);
    });
});
