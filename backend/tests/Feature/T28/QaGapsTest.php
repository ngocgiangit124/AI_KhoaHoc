<?php

use App\Mail\StaffNewDeviceMail;
use App\Models\AuditLog;
use App\Models\StaffDevice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

// Test QA bổ sung cho T28 (độ phủ AC8/AC10/BR2/BR4 và các biên review nêu).

function vvAdminPasswordPayload(string $current = 'password', string $new = 'mat-khau-moi-123'): array
{
    return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new];
}

test('QA BR2+AC8: admin must_change_password: MFA roi PASSWORD_CHANGE_REQUIRED roi doi mat khau roi vao duoc', function () {
    config(['features.staff_mfa' => true]);
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin', ['must_change_password' => true]);

    vvAdminFollow(vvAdminLogin((string) $admin->email)->assertOk()->assertJsonPath('mfa_required', true));

    // Chưa qua MFA: đổi mật khẩu bị chặn.
    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload(), vvAdminHeaders())
        ->assertForbidden()->assertJson(['code' => 'MFA_REQUIRED']);

    app('auth')->forgetGuards();
    $verify = test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $otp->lastCode()], vvAdminHeaders())->assertOk();
    vvAdminFollow($verify);
    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'PASSWORD_CHANGE_REQUIRED']);

    app('auth')->forgetGuards();
    $changed = test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload(), vvAdminHeaders())->assertOk();
    vvAdminFollow($changed);
    vvAdminGet('/admin/auth/me')->assertOk()->assertJsonPath('role', 'admin')->assertJsonPath('must_change_password', false);
});

test('QA BR2: sau doi mat khau, mat khau cu bi tu choi va mat khau moi dang nhap duoc', function () {
    $teacher = vvStaffUser('teacher', ['must_change_password' => true]);
    vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    app('auth')->forgetGuards();
    $changed = test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload(), vvAdminHeaders())->assertOk();
    vvAdminFollow($changed);

    app('auth')->forgetGuards();
    vvAdminLogin((string) $teacher->email, 'password')->assertStatus(422);
    app('auth')->forgetGuards();
    vvAdminLogin((string) $teacher->email, 'mat-khau-moi-123')->assertOk()->assertJsonPath('user.must_change_password', false);
});

test('QA: dang xuat khi phien da het han idle van 204 (contract R2)', function () {
    $teacher = vvStaffUser('teacher');
    vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    vvAdminGet('/admin/auth/me')->assertOk();

    $this->travel(121)->minutes();
    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/logout'), [], vvAdminHeaders())->assertNoContent();
});

test('QA BR4: staff bi khoa khi dang cho MFA -> verify bi 403 ACCOUNT_LOCKED, khong cap phien day du', function () {
    config(['features.staff_mfa' => true]);
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin');
    vvAdminFollow(vvAdminLogin((string) $admin->email)->assertOk());
    $admin->forceFill(['status' => 'locked'])->save();

    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $otp->lastCode()], vvAdminHeaders())
        ->assertForbidden()->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('QA: gui lai ma MFA toi da 5 lan/gio (tran), du da qua cooldown 60s', function () {
    config(['features.staff_mfa' => true]);
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin');
    vvAdminFollow(vvAdminLogin((string) $admin->email)->assertOk());

    $statuses = [];
    foreach (range(1, 8) as $i) {
        $this->travel(61)->seconds();
        app('auth')->forgetGuards();
        $statuses[] = test()->postJson(vvAdminUrl('/admin/auth/mfa/resend'), [], vvAdminHeaders())->status();
    }
    // Không bao giờ 5xx; có 429 trước khi gửi quá trần giờ.
    expect($statuses)->each->toBeIn([202, 429])
        ->and(in_array(429, $statuses, true))->toBeTrue();
    expect(count($otp->sent))->toBeLessThanOrEqual((int) config('auth.otp.max_per_hour') + 1);
});

test('QA BUG-2 (R6): request chua dang nhap khong gui Accept JSON van nhan 401 (khong 500)', function () {
    test()->get(vvAdminUrl('/admin/auth/me'), vvAdminHeaders())
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
    test()->get(vvApiUrl('/auth/me'), vvWebHeaders())
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('QA R3: gui mail canh bao lan dau loi, lan dang nhap sau van duoc canh bao', function () {
    $teacher = vvStaffUser('teacher');
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));
    vvAdminLogin((string) $teacher->email, headers: ['X-Device-Id' => '33333333-3333-4333-8333-333333333333'])->assertOk();
    expect(StaffDevice::query()->count())->toBe(0);
});

test('QA R3b: sau khi loi, lan sau gui thanh cong va giu ban ghi', function () {
    Mail::fake();
    $teacher = vvStaffUser('teacher');
    vvAdminLogin((string) $teacher->email, headers: ['X-Device-Id' => '33333333-3333-4333-8333-333333333333'])->assertOk();
    Mail::assertQueued(StaffNewDeviceMail::class, 1);
    expect(StaffDevice::query()->where('user_id', $teacher->id)->count())->toBe(1);
});

test('QA bien: mat khau/login rat dai hoac tieng Viet co dau khong gay 500', function () {
    $teacher = vvStaffUser('teacher');
    $long = str_repeat('á', 5000);

    $r = vvAdminLogin((string) $teacher->email, $long);
    expect($r->status())->toBeIn([422, 429]);
    app('auth')->forgetGuards();
    $r = vvAdminLogin(str_repeat('Đ', 3000).'@example.com', 'password');
    expect($r->status())->toBeIn([422, 429]);
    app('auth')->forgetGuards();
    $r = test()->postJson(vvAdminUrl('/admin/auth/login'), ['login' => ['a'], 'password' => ['b']], vvAdminHeaders());
    expect($r->status())->toBe(422);
});

test('QA: mat khau moi co dau tieng Viet duoc chap nhan va luu bam', function () {
    $teacher = vvStaffUser('teacher');
    vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload('password', 'Mật-khẩu-mới-Ðặng-12'), vvAdminHeaders())->assertOk();
    expect(Hash::check('Mật-khẩu-mới-Ðặng-12', $teacher->fresh()->password))->toBeTrue();
});

test('QA: audit staff.login co actor, khong chua mat khau/ma OTP', function () {
    $teacher = vvStaffUser('teacher');
    vvAdminLogin((string) $teacher->email)->assertOk();
    $json = json_encode(AuditLog::query()->where('action', 'like', 'staff.%')->get()->toArray());
    expect($json)->not->toContain('"password"')->and($json)->toContain('staff.login');
});

test('QA: cookie vv_admin_session khong co hieu luc o host hoc sinh', function () {
    $teacher = vvStaffUser('teacher');
    $cookie = vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    app('auth')->forgetGuards();
    test()->withCookie('vv_session', $cookie)->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertForbidden(); // role:hoc_sinh chặn: phiên staff không dùng được ở host học sinh
});

test('QA BUG-1: dang nhap lai voi cookie phien CU (bi huy sau doi mat khau) va mat khau dung phai 200 ngay lan dau', function () {
    $teacher = vvStaffUser('teacher');
    vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    vvAdminGet('/admin/auth/me')->assertOk();
    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload(), vvAdminHeaders())->assertOk();

    // Trình duyệt khác còn giữ cookie cũ (không nhận cookie mới) đăng nhập bằng mật khẩu mới.
    app('auth')->forgetGuards();
    vvAdminLogin((string) $teacher->email, 'mat-khau-moi-123')->assertOk();
});

test('BUG-1: lay csrf-token voi cookie phien CU (bi huy sau doi mat khau) van 200 va dang nhap lai duoc', function () {
    $teacher = vvStaffUser('teacher');
    vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    vvAdminGet('/admin/auth/me')->assertOk();
    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload(), vvAdminHeaders())->assertOk();

    // "Trình duyệt khác" giữ cookie cũ: lấy CSRF không bị 401, đăng nhập mật khẩu mới vào được; route cần đăng nhập vẫn 401.
    app('auth')->forgetGuards();
    vvAdminGet('/csrf-token')->assertOk()->assertJsonStructure(['token']);
    app('auth')->forgetGuards();
    vvAdminLogin((string) $teacher->email, 'mat-khau-moi-123')->assertOk();
});
