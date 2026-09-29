<?php

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * T04 — PUT /auth/contact (US-001, api-contract §2.2, data-model §3.1 S9):
 * đổi email/SĐT huỷ mã OTP cũ của kênh tương ứng, reset `*_verified_at`, gửi
 * mã mới.
 */
function vvUpdateContact(User $user, array $payload)
{
    return test()->actingAs($user)->putJson('http://'.config('app.api_host').'/api/v1/auth/contact', $payload, [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('doi email: reset email_verified_at, huy ma cu, gui ma moi toi email moi', function () {
    Mail::fake();
    $user = User::factory()->verified()->create(['email' => 'cu@example.com']);
    // T04 R1 — created_at đặt 2 giờ trước để KHÔNG chạm cooldown 60s/trần giờ
    // của `OtpService::assertUnderSendLimits()` (test này kiểm hành vi huỷ mã
    // cũ khi đổi liên hệ, không phải test trần gửi — trần gửi có test riêng ở
    // `OtpServiceTest.php` và `ContactUpdateThrottleTest.php`).
    $oldOtp = OtpCode::factory()->for($user)->create([
        'channel' => 'email',
        'destination' => 'cu@example.com',
        'created_at' => now()->subHours(2),
    ]);

    $response = vvUpdateContact($user, ['email' => 'moi@example.com']);

    $response->assertOk();
    $response->assertJson(['email' => 'moi@example.com']);

    $user->refresh();
    expect($user->email)->toBe('moi@example.com');
    expect($user->email_verified_at)->toBeNull();
    // phone_verified_at KHÔNG bị ảnh hưởng (chỉ đổi email).
    expect($user->phone_verified_at)->not->toBeNull();

    expect($oldOtp->fresh()->invalidated_at)->not->toBeNull();

    $newOtp = OtpCode::query()
        ->where('user_id', $user->id)
        ->where('channel', 'email')
        ->whereNull('invalidated_at')
        ->first();
    expect($newOtp)->not->toBeNull();
    expect($newOtp->destination)->toBe('moi@example.com');

    Mail::assertQueued(OtpMail::class);
});

test('doi phone: reset phone_verified_at, khong dung email_verified_at', function () {
    Mail::fake();
    $user = User::factory()->verified()->create(['phone' => '0911111111']);

    $response = vvUpdateContact($user, ['phone' => '0922222222']);

    $response->assertOk();
    $user->refresh();
    expect($user->phone)->toBe('0922222222');
    expect($user->phone_verified_at)->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
});

test('email/SDT trung voi tai khoan khac tra 422 dung field', function () {
    User::factory()->create(['email' => 'da-dung@example.com']);
    $user = User::factory()->create();

    $response = vvUpdateContact($user, ['email' => 'da-dung@example.com']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
});

test('khong gui gi ca tra 422', function () {
    $user = User::factory()->create();

    vvUpdateContact($user, [])->assertStatus(422);
});

test('gia tri giong het hien tai thi khong lam gi (khong reset verified, khong gui OTP)', function () {
    Mail::fake();
    $user = User::factory()->verified()->create(['email' => 'khong-doi@example.com']);

    $response = vvUpdateContact($user, ['email' => 'khong-doi@example.com']);

    $response->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

test('doi email khi kenh sms khong duoc bat van tra ve 200, phone_verified_at duoc reset nhung khong gui duoc OTP that', function () {
    config(['auth.otp.channels' => ['email']]);
    $user = User::factory()->verified()->create(['phone' => '0911111111']);

    $response = vvUpdateContact($user, ['phone' => '0933333333']);

    $response->assertOk();
    expect($user->fresh()->phone_verified_at)->toBeNull();
    // Không có OtpSender nào cho 'sms' được bật ở production giả lập này —
    // OtpService::sendAfterContactChange() tự lọc bỏ kênh chưa bật khỏi
    // $channels trước khi tạo mã, không tạo otp_codes cho kênh này.
    expect(OtpCode::query()->where('user_id', $user->id)->where('channel', 'sms')->exists())->toBeFalse();
});

test('khong dang nhap khong goi duoc (auth:sanctum)', function () {
    $response = test()->putJson('http://'.config('app.api_host').'/api/v1/auth/contact', ['email' => 'x@example.com'], [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(401);
});
