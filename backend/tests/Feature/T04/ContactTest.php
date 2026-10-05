<?php

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;

require_once __DIR__.'/helpers.php';

test('doi email: luu lowercase, reset email_verified_at, huy ma cu, gui ma moi toi email moi (S9)', function () {
    $sender = vvFakeOtp();
    $user = vvOtpStudent(['email' => 'cu@example.com', 'email_verified_at' => now()]);
    // Mã cũ còn hiệu lực gửi tới email cũ.
    OtpCode::factory()->withCode('111111')->create(['user_id' => $user->id, 'destination' => 'cu@example.com']);

    $res = vvContactUpdate(['email' => 'Moi@Example.COM '])->assertOk()->assertJsonStructure(['resend_available_at']);

    expect(Carbon\Carbon::parse($res->json('resend_available_at'))->isFuture())->toBeTrue();

    $fresh = $user->fresh();
    expect($fresh->email)->toBe('moi@example.com')
        ->and($fresh->email_verified_at)->toBeNull()
        ->and($fresh->isVerified())->toBeFalse()
        ->and($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['destination'])->toBe('moi@example.com');

    // Mã cũ chết, mã mới dùng được.
    expect(OtpCode::where('destination', 'cu@example.com')->whereNull('invalidated_at')->count())->toBe(0);
    vvOtpVerify('111111')->assertStatus(422);
    vvOtpVerify($sender->lastCode())->assertOk()->assertJson(['is_verified' => true]);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('doi email ngay sau dang ky (con cooldown) van duoc -> khong ap cooldown 60s', function () {
    $sender = vvFakeOtp();
    vvOtpStudent();
    vvOtpSend()->assertStatus(202);

    vvContactUpdate(['email' => 'dung@example.com'])->assertOk();

    expect($sender->sent)->toHaveCount(2)->and($sender->sent[1]['destination'])->toBe('dung@example.com');
});

test('doi email van bi tran gio/ngay chan (khong thanh duong spam mail)', function () {
    vvFakeOtp();
    config(['auth.otp.max_per_hour' => 2]);
    vvOtpStudent();

    vvContactUpdate(['email' => 'a1@example.com'])->assertOk();
    vvContactUpdate(['email' => 'a2@example.com'])->assertOk();
    $res = vvContactUpdate(['email' => 'a3@example.com']);

    // Bị chặn thì KHÔNG đổi gì: email vẫn là a2, không có OTP thứ 3.
    $res->assertStatus(429);
    expect(OtpCode::count())->toBe(2)->and(User::first()->email)->toBe('a2@example.com');
});

test('email da co nguoi dung -> 422 field email, khong doi, khong huy ma', function () {
    vvFakeOtp();
    User::factory()->create(['email' => 'trung@example.com']);
    $user = vvOtpStudent(['email' => 'toi@example.com']);
    $otp = OtpCode::factory()->create(['user_id' => $user->id]);

    vvContactUpdate(['email' => 'TRUNG@example.com'])->assertStatus(422)->assertJsonValidationErrors('email');

    expect($user->fresh()->email)->toBe('toi@example.com')
        ->and($otp->fresh()->invalidated_at)->toBeNull();
});

test('SDT da co nguoi dung -> 422 field phone', function () {
    vvFakeOtp();
    User::factory()->create(['phone' => '0911111111']);
    vvOtpStudent();

    vvContactUpdate(['phone' => '+84 911 111 111'])->assertStatus(422)->assertJsonValidationErrors('phone');
});

test('giu nguyen email/SDT cua chinh minh -> 200, khong gui ma, khong reset xac thuc', function () {
    $sender = vvFakeOtp();
    $user = vvOtpStudent(['email' => 'toi@example.com', 'email_verified_at' => now()]);

    vvContactUpdate(['email' => 'toi@example.com', 'phone' => $user->phone])
        ->assertOk()->assertExactJson(['resend_available_at' => null]);

    expect($sender->sent)->toBe([])->and($user->fresh()->email_verified_at)->not->toBeNull();
});

test('khong gui field nao -> 422; dinh dang sai -> 422', function () {
    vvOtpStudent();

    vvContactUpdate([])->assertStatus(422);
    vvContactUpdate(['email' => 'khong-hop-le'])->assertStatus(422)->assertJsonValidationErrors('email');
    vvContactUpdate(['phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
});

test('doi SDT khi kenh sms tat: reset phone_verified_at, khong gui OTP, resend null', function () {
    config(['auth.otp.channels' => ['email']]);
    $sender = vvFakeOtp();
    $user = vvOtpStudent(['phone_verified_at' => now(), 'email_verified_at' => now()]);

    vvContactUpdate(['phone' => '0987654321'])->assertOk()->assertExactJson(['resend_available_at' => null]);

    $fresh = $user->fresh();
    expect($fresh->phone)->toBe('0987654321')
        ->and($fresh->phone_verified_at)->toBeNull()
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($sender->sent)->toBe([]);
});

test('doi SDT khi kenh sms bat: gui ma sms toi SDT moi, verify ghi phone_verified_at', function () {
    config(['auth.otp.channels' => ['email', 'sms']]);
    $sender = vvFakeOtp();
    $user = vvOtpStudent(['email_verified_at' => null]);

    vvContactUpdate(['phone' => '0987654321'])->assertOk();

    expect($sender->sent[0]['channel'])->toBe('sms')->and($sender->sent[0]['destination'])->toBe('0987654321');

    app(OtpService::class);
    vvOtpVerify($sender->lastCode())->assertOk();
    $fresh = $user->fresh();
    expect($fresh->phone_verified_at)->not->toBeNull()->and($fresh->email_verified_at)->toBeNull();
});

test('mass assignment: payload them role/status/*_verified_at bi bo qua', function () {
    vvFakeOtp();
    $user = vvOtpStudent();

    vvContactUpdate([
        'email' => 'ok@example.com',
        'role' => 'admin',
        'status' => 'locked',
        'email_verified_at' => now()->toDateTimeString(),
    ])->assertOk();

    $fresh = $user->fresh();
    expect($fresh->role->value)->toBe('hoc_sinh')
        ->and($fresh->status->value)->toBe('active')
        ->and($fresh->email_verified_at)->toBeNull();
});

test('audit account.contact_changed khong chua email/SDT moi', function () {
    vvFakeOtp();
    vvOtpStudent();

    vvContactUpdate(['email' => 'bi-mat@example.com'])->assertOk();

    $log = AuditLog::where('action', 'account.contact_changed')->firstOrFail();
    expect(json_encode($log->changes))->not->toContain('bi-mat')->toContain('email');
});

test('chua dang nhap -> 401; giao vien -> 403', function () {
    vvContactUpdate(['email' => 'a@example.com'])->assertStatus(401);

    $this->actingAs(User::factory()->teacher()->create());
    vvContactUpdate(['email' => 'a@example.com'])->assertStatus(403);
});

test('R1 production (chi email): doi SDT KHONG huy ma email dang cho; resend null', function () {
    config(['auth.otp.channels' => ['email']]);
    $sender = vvFakeOtp();
    $user = vvOtpStudent();
    vvOtpSend()->assertStatus(202);
    $code = $sender->lastCode();

    vvContactUpdate(['phone' => '0987654321'])->assertOk()->assertExactJson(['resend_available_at' => null]);

    expect(OtpCode::whereNull('invalidated_at')->count())->toBe(1);
    vvOtpVerify($code)->assertOk()->assertJson(['is_verified' => true]);
});
