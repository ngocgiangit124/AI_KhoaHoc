<?php

use App\Mail\OtpMail;
use App\Mail\StaffNewDeviceMail;
use App\Models\AuditLog;
use App\Models\StaffKnownDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * T28 — POST /admin/auth/login, POST /admin/auth/logout (api-contract §2.5).
 */
function vvAdminUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1'.$path;
}

/**
 * @return array<string, string>
 */
function vvAdminHeaders(array $extra = []): array
{
    return array_merge(['Origin' => config('app.admin_url')], $extra);
}

function vvPostAdminLogin(array $payload, array $headers = [])
{
    return test()->postJson(vvAdminUrl('/admin/auth/login'), $payload, vvAdminHeaders($headers));
}

test('admin dang nhap dung mat khau: phien cho MFA, gui OTP, tra mfa_required true', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $admin->email, 'password' => 'matkhau123']);

    $response->assertOk();
    $response->assertExactJson(['mfa_required' => true]);

    Mail::assertQueued(OtpMail::class);

    expect(AuditLog::query()->where('action', 'staff.login')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

test('quan ly trang dang nhap dung mat khau cung phai qua MFA', function () {
    Mail::fake();
    $pageManager = User::factory()->pageManager()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $pageManager->email, 'password' => 'matkhau123']);

    $response->assertOk();
    $response->assertExactJson(['mfa_required' => true]);
});

test('giao vien dang nhap dung mat khau vao ngay, khong can MFA', function () {
    Mail::fake();
    $teacher = User::factory()->teacher()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $teacher->email, 'password' => 'matkhau123']);

    $response->assertOk();
    $response->assertJson(['id' => $teacher->id, 'role' => 'giao_vien']);
    expect($response->json('mfa_required'))->toBeNull();

    Mail::assertNotQueued(OtpMail::class);
});

test('admin dang nhap khong can MFA khi feature staff_mfa tat', function () {
    Mail::fake();
    config(['features.staff_mfa' => false]);
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $admin->email, 'password' => 'matkhau123']);

    $response->assertOk();
    $response->assertJson(['id' => $admin->id]);
    Mail::assertNotQueued(OtpMail::class);
});

test('giao vien lan dau dang nhap tu 1 thiet bi khong bi canh bao (chua co thiet bi nao khac)', function () {
    Mail::fake();
    $teacher = User::factory()->teacher()->create(['password' => Hash::make('matkhau123')]);

    vvPostAdminLogin(
        ['login' => $teacher->email, 'password' => 'matkhau123'],
        ['X-Device-Id' => (string) Str::uuid()],
    )->assertOk();

    Mail::assertNotQueued(StaffNewDeviceMail::class);
    expect(StaffKnownDevice::query()->where('user_id', $teacher->id)->count())->toBe(1);
});

test('giao vien dang nhap tu thiet bi thu 2 nhan email canh bao thiet bi moi', function () {
    Mail::fake();
    $teacher = User::factory()->teacher()->create(['password' => Hash::make('matkhau123')]);
    StaffKnownDevice::factory()->for($teacher)->create(['device_id' => (string) Str::uuid()]);

    vvPostAdminLogin(
        ['login' => $teacher->email, 'password' => 'matkhau123'],
        ['X-Device-Id' => (string) Str::uuid()],
    )->assertOk();

    Mail::assertQueued(StaffNewDeviceMail::class);
});

test('giao vien dang nhap tu thiet bi da biet khong bi canh bao lai', function () {
    Mail::fake();
    $teacher = User::factory()->teacher()->create(['password' => Hash::make('matkhau123')]);
    $deviceId = (string) Str::uuid();
    StaffKnownDevice::factory()->for($teacher)->create(['device_id' => $deviceId]);
    StaffKnownDevice::factory()->for($teacher)->create(['device_id' => (string) Str::uuid()]);

    vvPostAdminLogin(
        ['login' => $teacher->email, 'password' => 'matkhau123'],
        ['X-Device-Id' => $deviceId],
    )->assertOk();

    Mail::assertNotQueued(StaffNewDeviceMail::class);
});

test('hoc sinh dang nhap dung mat khau o host admin tra 403 WRONG_PORTAL', function () {
    $student = User::factory()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $student->email, 'password' => 'matkhau123']);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'WRONG_PORTAL']);
});

test('sai mat khau tra 422 thong diep chung', function () {
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $admin->email, 'password' => 'sai']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['login']);
});

test('tai khoan bi khoa + dung mat khau tra 403 ACCOUNT_LOCKED', function () {
    $admin = User::factory()->admin()->locked()->create(['password' => Hash::make('matkhau123')]);

    $response = vvPostAdminLogin(['login' => $admin->email, 'password' => 'matkhau123']);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('dang nhap admin thieu Origin dung tra 403 ORIGIN_NOT_ALLOWED', function () {
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhau123')]);

    $response = test()->postJson(vvAdminUrl('/admin/auth/login'), [
        'login' => $admin->email,
        'password' => 'matkhau123',
    ]);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('da dang nhap ma goi lai dang nhap (guest) bi tu choi', function () {
    $admin = User::factory()->admin()->create();

    $response = test()->actingAs($admin)->postJson(vvAdminUrl('/admin/auth/login'), [
        'login' => $admin->email,
        'password' => 'password',
    ], vvAdminHeaders());

    $response->assertStatus(403);
});

test('dang xuat tra 204 va huy phien', function () {
    $admin = User::factory()->admin()->create();

    $response = test()->actingAs($admin)->postJson(vvAdminUrl('/admin/auth/logout'), [], vvAdminHeaders());

    $response->assertStatus(204);
    expect(auth('web')->check())->toBeFalse();
    expect(AuditLog::query()->where('action', 'staff.logout')->where('subject_id', $admin->id)->exists())->toBeTrue();
});
