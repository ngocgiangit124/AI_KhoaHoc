<?php

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * T04 — POST /auth/otp/verify (US-001 AC8/AC9, api-contract §2.2).
 */
function vvOtpVerify(User $user, string $code)
{
    return test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/otp/verify', [
        'code' => $code,
    ], [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('AC8: nhap dung OTP trong han hieu luc thi xac thuc thanh cong, tra 200 user da xac thuc', function () {
    $user = User::factory()->create();
    OtpCode::factory()->for($user)->create(['code_hash' => Hash::make('654321')]);

    $response = vvOtpVerify($user, '654321');

    $response->assertOk();
    $response->assertJson(['id' => $user->id, 'is_verified' => true]);
    expect($response->json('email_verified_at'))->not->toBeNull();
});

test('sai ma tra 422 voi thong diep tieng Viet, khong xac thuc', function () {
    $user = User::factory()->create();
    OtpCode::factory()->for($user)->create(['code_hash' => Hash::make('654321')]);

    $response = vvOtpVerify($user, '000000');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['code']);
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('ma khong dung dinh dang 6 so tra 422 (validate truoc khi cham Service)', function () {
    $user = User::factory()->create();

    vvOtpVerify($user, 'abcdef')->assertStatus(422)->assertJsonValidationErrors(['code']);
    vvOtpVerify($user, '12345')->assertStatus(422)->assertJsonValidationErrors(['code']);
});

test('ma da het han tra 422', function () {
    $user = User::factory()->create();
    OtpCode::factory()->for($user)->expired()->create(['code_hash' => Hash::make('654321')]);

    $response = vvOtpVerify($user, '654321');

    $response->assertStatus(422);
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('ma khong ton tai (chua tung gui) tra 422', function () {
    $user = User::factory()->create();

    vvOtpVerify($user, '654321')->assertStatus(422);
});

test('khong dang nhap khong goi duoc (auth:sanctum)', function () {
    $response = test()->postJson('http://'.config('app.api_host').'/api/v1/auth/otp/verify', ['code' => '654321'], [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(401);
});

/**
 * api-contract §1.6 — otp-verify: 5 lần/phút/user; vượt → 429 (Retry-After
 * theo header chuẩn của `throttle:` — không cần trường riêng trong body).
 */
test('vuot 5 lan/phut tra 429 TOO_MANY_ATTEMPTS kem Retry-After', function () {
    $user = User::factory()->create();
    OtpCode::factory()->for($user)->create(['code_hash' => Hash::make('654321')]);

    for ($i = 0; $i < 5; $i++) {
        vvOtpVerify($user, '000000')->assertStatus(422);
    }

    $blocked = vvOtpVerify($user, '000000');

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
    expect($blocked->headers->has('Retry-After'))->toBeTrue();
});
