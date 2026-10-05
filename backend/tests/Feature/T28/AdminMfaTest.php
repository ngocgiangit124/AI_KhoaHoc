<?php

use App\Enums\OtpPurpose;
use App\Mail\OtpMail;
use App\Models\AuditLog;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config(['features.staff_mfa' => true]);
    $this->otp = vvFakeOtp();
    $this->admin = vvStaffUser('admin');
});

function vvMfaPending(): void
{
    $response = vvAdminLogin((string) test()->admin->email)->assertOk()->assertJsonPath('mfa_required', true);
    vvAdminFollow($response);
}

function vvMfaVerify(string $code)
{
    app('auth')->forgetGuards();

    return test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $code], vvAdminHeaders());
}

test('MFA dung ma: cap phien day du, doi cookie, audit staff.login, route staff dung duoc', function () {
    vvMfaPending();

    $response = vvMfaVerify($this->otp->lastCode());
    $response->assertOk()
        ->assertJsonPath('mfa_required', false)
        ->assertJsonPath('user.id', $this->admin->id)
        ->assertJsonPath('user.role', 'admin');
    vvAdminFollow($response);

    vvAdminGet('/admin/auth/me')->assertOk();
    $log = AuditLog::query()->where('action', 'staff.login')->first();
    expect($log)->not->toBeNull()->and($log->changes['mfa'])->toBeTrue();
    expect(OtpCode::query()->whereNotNull('consumed_at')->count())->toBe(1);
    expect($this->admin->fresh()->last_login_at)->not->toBeNull();
});

test('MFA bam hai lan: lan hai van 200 (idempotent)', function () {
    vvMfaPending();
    $code = $this->otp->lastCode();
    vvAdminFollow(vvMfaVerify($code)->assertOk());

    vvMfaVerify($code)->assertOk();
});

test('MFA sai ma: 422 field code, audit staff.mfa_failed, van chua vao duoc', function () {
    vvMfaPending();

    vvMfaVerify($this->otp->lastCode() === '000000' ? '111111' : '000000')
        ->assertStatus(422)->assertJsonValidationErrors(['code']);

    $log = AuditLog::query()->where('action', 'staff.mfa_failed')->first();
    expect($log)->not->toBeNull()
        ->and($log->changes['reason'])->toBe('wrong_code')
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and(json_encode($log->changes))->not->toContain($this->otp->lastCode());

    app('auth')->forgetGuards();
    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'MFA_REQUIRED']);
});

test('MFA het han: 422 field code, audit reason expired', function () {
    vvMfaPending();
    $code = $this->otp->lastCode();

    $this->travel((int) config('auth.otp.ttl_minutes') + 1)->minutes();
    // Giữ phiên còn hạn idle (120 phút) — chỉ mã hết hạn.
    vvMfaVerify($code)->assertStatus(422)->assertJsonValidationErrors(['code']);

    expect(AuditLog::query()->where('action', 'staff.mfa_failed')->first()->changes['reason'])->toBe('expired');
});

test('MFA: 5 lan sai cua mot ma -> 429 TOO_MANY_ATTEMPTS, ma dung cung khong dung duoc nua', function () {
    vvMfaPending();
    $right = $this->otp->lastCode();
    $wrong = $right === '123456' ? '654321' : '123456';

    foreach (range(1, 5) as $i) {
        vvMfaVerify($wrong)->assertStatus(422);
    }
    // throttle otp-verify cho phép 5/phút nên sang phút kế tiếp.
    $this->travel(2)->minutes();
    vvMfaVerify($right)->assertStatus(429)->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);

    expect(AuditLog::query()->where('action', 'staff.mfa_failed')->count())->toBe(6);
});

test('MFA: throttle otp-verify 5/phut/user -> request thu 6 bi 429 co Retry-After', function () {
    vvMfaPending();
    $wrong = $this->otp->lastCode() === '123456' ? '654321' : '123456';

    foreach (range(1, 5) as $i) {
        vvMfaVerify($wrong);
    }

    vvMfaVerify($wrong)->assertStatus(429)->assertHeader('Retry-After');
});

test('MFA: ma sai dinh dang 422', function () {
    vvMfaPending();

    vvMfaVerify('12ab')->assertStatus(422)->assertJsonValidationErrors(['code']);
});

test('MFA verify can dang nhap: khong co phien -> 401', function () {
    test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => '123456'], vvAdminHeaders())
        ->assertUnauthorized();
});

test('MFA verify voi Origin web bi 403', function () {
    vvMfaPending();
    app('auth')->forgetGuards();

    test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $this->otp->lastCode()], ['Origin' => config('app.frontend_url')])
        ->assertForbidden()->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('gui lai ma MFA: cooldown 60s -> 429; sau 61s gui duoc ma moi, ma cu vo hieu', function () {
    vvMfaPending();
    $first = $this->otp->lastCode();

    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/mfa/resend'), [], vvAdminHeaders())
        ->assertStatus(429)->assertHeader('Retry-After');

    $this->travel(61)->seconds();
    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/mfa/resend'), [], vvAdminHeaders())
        ->assertStatus(202)->assertJsonStructure(['resend_available_at']);

    expect($this->otp->sent)->toHaveCount(2);
    $second = $this->otp->lastCode();
    if ($first !== $second) {
        vvMfaVerify($first)->assertStatus(422);
    }
    vvMfaVerify($second)->assertOk();
});

test('gui lai ma khi da qua MFA -> 409 ALREADY_PROCESSED', function () {
    vvMfaPending();
    vvAdminFollow(vvMfaVerify($this->otp->lastCode())->assertOk());

    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/mfa/resend'), [], vvAdminHeaders())
        ->assertStatus(409)->assertJson(['code' => 'ALREADY_PROCESSED']);
});

test('OTP MFA luu bcrypt, khong lo ma ro trong DB va audit', function () {
    vvMfaPending();

    $row = OtpCode::query()->first();
    expect($row->purpose->value)->toBe('staff_login_mfa')
        ->and($row->code_hash)->not->toContain($this->otp->lastCode());
    expect(json_encode(AuditLog::query()->pluck('changes')))->not->toContain($this->otp->lastCode());
});

test('email OTP MFA dung tieu de quan tri', function () {
    $mail = new OtpMail('Admin', '123456', OtpPurpose::StaffLoginMfa, 10);

    expect($mail->envelope()->subject)->toContain('quản trị');
    $mail->assertSeeInHtml('đăng nhập trang quản trị');
    $mail->assertSeeInHtml('123456');
});

test('giao vien khong bi hoi MFA du co OTP', function () {
    Mail::fake();
    $teacher = vvStaffUser('teacher');

    vvAdminLogin((string) $teacher->email)->assertOk()->assertJsonPath('mfa_required', false);
    expect($this->otp->sent)->toBe([]);

    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/mfa/resend'), [], vvAdminHeaders())->assertStatus(409);
});
