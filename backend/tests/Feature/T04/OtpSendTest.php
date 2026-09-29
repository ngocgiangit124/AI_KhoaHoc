<?php

use App\Enums\OtpPurpose;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * T04 — POST /auth/otp/send (US-001 AC8/AC9, api-contract §2.2).
 */
afterEach(function () {
    Carbon::setTestNow();
});

function vvOtpSend(User $user, array $payload = ['channel' => 'email'])
{
    return test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/otp/send', $payload, [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('gui OTP thanh cong tra 202 kem resend_available_at ISO8601 co offset', function () {
    Mail::fake();
    $user = User::factory()->create();

    $response = vvOtpSend($user);

    $response->assertStatus(202);
    $response->assertJsonStructure(['resend_available_at']);
    expect($response->json('resend_available_at'))->toMatch('/[+\-]\d{2}:\d{2}$/');

    $otp = OtpCode::query()->where('user_id', $user->id)->where('purpose', OtpPurpose::VerifyAccount->value)->first();
    expect($otp)->not->toBeNull();
    expect($otp->channel)->toBe('email');
    expect($otp->destination)->toBe($user->email);

    Mail::assertQueued(OtpMail::class);
});

test('kenh sms bi tu choi 422 khi AUTH_OTP_CHANNELS chi co email (production MVP)', function () {
    config(['auth.otp.channels' => ['email']]);
    $user = User::factory()->create();

    $response = vvOtpSend($user, ['channel' => 'sms']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['channel']);
});

test('thieu channel tra 422', function () {
    $user = User::factory()->create();

    vvOtpSend($user, [])->assertStatus(422)->assertJsonValidationErrors(['channel']);
});

test('khong dang nhap khong goi duoc (auth:sanctum)', function () {
    $response = test()->postJson('http://'.config('app.api_host').'/api/v1/auth/otp/send', ['channel' => 'email'], [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(401);
});

test('teacher khong the goi tren host api (role:hoc_sinh)', function () {
    $teacher = User::factory()->teacher()->create();

    $response = vvOtpSend($teacher);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('goi lai truoc cooldown (60s) bi throttle:otp-send tra 429', function () {
    Mail::fake();
    $user = User::factory()->create();

    vvOtpSend($user)->assertStatus(202);
    vvOtpSend($user)->assertStatus(429);
});

/**
 * T04 review R5 — trần 5/giờ (api-contract §1.6, `auth.otp.max_per_hour`)
 * phải có hiệu lực thật qua HTTP, không chỉ khai đúng số ở `AppServiceProvider`.
 */
test('vuot tran 5/gio (max_per_hour) qua HTTP tra 429 TOO_MANY_ATTEMPTS', function () {
    Mail::fake();
    $user = User::factory()->create();
    $maxPerHour = (int) config('auth.otp.max_per_hour');

    for ($i = 0; $i < $maxPerHour; $i++) {
        vvOtpSend($user)->assertStatus(202);
        // Nhích qua cooldown 60s nhưng vẫn trong cùng giờ.
        Carbon::setTestNow(now()->addSeconds(61));
    }

    $blocked = vvOtpSend($user);

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
    expect(OtpCode::query()->where('user_id', $user->id)->count())->toBe($maxPerHour);
});

test('response da xac thuc co Cache-Control no-store (S16)', function () {
    Mail::fake();
    $user = User::factory()->create();

    $response = vvOtpSend($user);

    $response->assertHeader('Cache-Control', 'no-store, private');
});
