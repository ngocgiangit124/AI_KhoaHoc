<?php

use App\Enums\OtpPurpose;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * T04 — hoàn thiện TODO(T04) của T03: `POST /auth/register` phải gửi OTP xác
 * thực ngay sau khi tạo tài khoản (AC1, BR7, api-contract §2.2).
 */
function vvOtpRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nguyễn Văn A',
        'date_of_birth' => '2000-01-01',
        'email' => 'hocsinh-otp'.uniqid().'@example.com',
        'phone' => '09'.random_int(10000000, 99999999),
        'grade_level' => 10,
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'accept_privacy' => true,
        'captcha_token' => 'test-ok-token',
        'device_id' => 'device-'.uniqid(),
    ], $overrides);
}

test('dang ky thanh cong gui OTP xac thuc qua email (AC1, BR7)', function () {
    Mail::fake();

    $payload = vvOtpRegisterPayload();

    $response = test()->postJson('http://'.config('app.api_host').'/api/v1/auth/register', $payload, [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(201);

    $user = User::query()->where('email', mb_strtolower($payload['email']))->firstOrFail();

    $otp = OtpCode::query()
        ->where('user_id', $user->id)
        ->where('purpose', OtpPurpose::VerifyAccount->value)
        ->where('channel', 'email')
        ->first();

    expect($otp)->not->toBeNull();
    expect($otp->destination)->toBe($user->email);
    expect($otp->consumed_at)->toBeNull();

    Mail::assertQueued(OtpMail::class);
});
