<?php

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Mail\StaffNewDeviceMail;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\StaffDevice;
use App\Models\User;
use App\Services\Auth\Otp\OtpSender;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvFakeOtp();
    config(['features.staff_mfa' => true]);
});

test('login admin-api voi Origin cua web hoc sinh bi 403 ORIGIN_NOT_ALLOWED', function () {
    $user = vvStaffUser('teacher');

    $response = test()->postJson(vvAdminUrl('/admin/auth/login'), ['login' => $user->email, 'password' => 'password'], [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertForbidden()->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
    expect(AuditLog::query()->where('action', 'like', 'staff.login%')->count())->toBe(0);
});

test('login admin-api thieu Origin bi 403', function () {
    $user = vvStaffUser('teacher');

    test()->postJson(vvAdminUrl('/admin/auth/login'), ['login' => $user->email, 'password' => 'password'])
        ->assertForbidden()->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('hoc sinh dang nhap admin dung mat khau -> WRONG_PORTAL, khong co phien, co audit', function () {
    $student = User::factory()->create();

    $response = vvAdminLogin((string) $student->email);

    $response->assertForbidden()->assertJson(['code' => 'WRONG_PORTAL']);
    vvAdminFollow($response);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();
    $log = AuditLog::query()->where('action', 'staff.login_failed')->first();
    expect($log)->not->toBeNull()
        ->and($log->changes['reason'])->toBe('wrong_portal')
        ->and($log->subject_id)->toBe($student->id);

    // Mật khẩu SAI thì không được biết vai trò: thông điệp chung 422.
    vvAdminLogin((string) $student->email, 'sai-mat-khau')
        ->assertStatus(422)->assertJsonValidationErrors(['login']);
});

test('sai mat khau / tai khoan khong ton tai cung 422 thong diep chung, ghi audit staff.login_failed', function () {
    $user = vvStaffUser('teacher');

    $a = vvAdminLogin((string) $user->email, 'sai-mat-khau');
    $b = vvAdminLogin('khong-co@example.com', 'sai-mat-khau');

    $a->assertStatus(422)->assertJsonValidationErrors(['login']);
    expect($b->json('errors.login'))->toBe($a->json('errors.login'));
    vvAdminFollow($a);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();

    $logs = AuditLog::query()->where('action', 'staff.login_failed')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->subject_id)->toBe($user->id)
        ->and($logs[1]->subject_id)->toBeNull()
        ->and(json_encode($logs->pluck('changes')))->not->toContain('sai-mat-khau');
});

test('tai khoan bi khoa: chi bao ACCOUNT_LOCKED khi mat khau dung (S20)', function () {
    $user = vvStaffUser('teacher', ['status' => UserStatus::Locked]);
    $user->forceFill(['status' => UserStatus::Locked])->save();

    vvAdminLogin((string) $user->email, 'sai')->assertStatus(422);
    vvAdminLogin((string) $user->email)->assertForbidden()->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('giao vien dang nhap: 200, khong MFA, user staff phang, co audit staff.login', function () {
    Mail::fake();
    $teacher = vvStaffUser('teacher');

    $response = vvAdminLogin((string) $teacher->email);
    vvAdminFollow($response);

    $response->assertOk()
        ->assertJsonPath('mfa_required', false)
        ->assertJsonPath('user.id', $teacher->id)
        ->assertJsonPath('user.role', 'giao_vien')
        ->assertJsonPath('user.must_change_password', false)
        ->assertJsonPath('user.permissions.manage_system', false)
        ->assertJsonStructure(['user' => ['name', 'email', 'permissions', 'session' => ['idle_timeout_minutes', 'expires_at']]])
        ->assertJsonMissingPath('user.password')
        ->assertHeader('Cache-Control');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    expect(AuditLog::query()->where('action', 'staff.login')->where('actor_id', $teacher->id)->count())->toBe(1);
    expect($teacher->fresh()->last_login_at)->not->toBeNull();

    vvAdminGet('/admin/auth/me')->assertOk()->assertJsonPath('id', $teacher->id);
});

test('cookie phien admin la vv_admin_session, HttpOnly, SameSite=Strict, khong Domain', function () {
    $teacher = vvStaffUser('teacher');

    $response = vvAdminLogin((string) $teacher->email)->assertOk();

    $cookie = $response->getCookie('vv_admin_session');
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getExpiresTime())->toBe(0); // expire_on_close
    expect($response->getCookie('vv_session'))->toBeNull();
});

test('giao vien: thiet bi moi gui email canh bao; cung thiet bi khong gui lai; thiet bi khac gui', function () {
    Mail::fake();
    $teacher = vvStaffUser('teacher');
    $deviceA = '11111111-1111-4111-8111-111111111111';
    $deviceB = '22222222-2222-4222-8222-222222222222';

    vvAdminLogin((string) $teacher->email, headers: ['X-Device-Id' => $deviceA])->assertOk();
    Mail::assertQueuedCount(1);
    Mail::assertQueued(StaffNewDeviceMail::class, fn ($m) => $m->hasTo($teacher->email));

    app('auth')->forgetGuards();
    vvAdminLogin((string) $teacher->email, headers: ['X-Device-Id' => $deviceA])->assertOk();
    Mail::assertQueuedCount(1);

    app('auth')->forgetGuards();
    vvAdminLogin((string) $teacher->email, extra: ['device_id' => $deviceB])->assertOk();
    Mail::assertQueuedCount(2);

    expect(StaffDevice::query()->where('user_id', $teacher->id)->count())->toBe(2);
    // Không lưu UUID gốc.
    expect(StaffDevice::query()->where('device_hash', $deviceA)->exists())->toBeFalse();
});

test('loi gui email canh bao thiet bi moi khong chan dang nhap', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    $teacher = vvStaffUser('teacher');

    vvAdminLogin((string) $teacher->email)->assertOk()->assertJsonPath('mfa_required', false);
    // R3: gửi lỗi thì không để lại bản ghi thiết bị (lần sau vẫn được cảnh báo).
    expect(StaffDevice::query()->where('user_id', $teacher->id)->count())->toBe(0);
});

test('limiter login flood tach theo host (hoc sinh cung NAT khong lam staff bi 429)', function () {
    $teacher = vvStaffUser('teacher');

    foreach (range(1, 120) as $i) {
        test()->postJson(vvApiUrl('/auth/login'), ['login' => 'x@example.com', 'password' => 'sai'], vvWebHeaders());
    }
    test()->postJson(vvApiUrl('/auth/login'), ['login' => 'x@example.com', 'password' => 'sai'], vvWebHeaders())->assertStatus(429);

    vvAdminLogin((string) $teacher->email)->assertOk();
});

test('admin bat MFA: dang nhap tra mfa_required va gui OTP purpose staff_login_mfa, chua co user', function () {
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin');

    $response = vvAdminLogin((string) $admin->email);
    vvAdminFollow($response);

    $response->assertOk()
        ->assertJsonPath('mfa_required', true)
        ->assertJsonMissingPath('user')
        ->assertJsonStructure(['resend_available_at']);
    expect($otp->sent)->toHaveCount(1)
        ->and($otp->sent[0]['purpose'])->toBe('staff_login_mfa')
        ->and($otp->sent[0]['channel'])->toBe('email')
        ->and($otp->sent[0]['destination'])->toBe($admin->email);

    // Chưa qua MFA: mọi route nhóm staff bị chặn.
    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'MFA_REQUIRED']);
    expect(AuditLog::query()->where('action', 'staff.login')->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'staff.login_mfa_sent')->count())->toBe(1);
});

test('quan ly trang cung phai MFA', function () {
    vvFakeOtp();
    $manager = vvStaffUser('pageManager');

    vvAdminLogin((string) $manager->email)->assertOk()->assertJsonPath('mfa_required', true);
});

test('FEATURE_STAFF_MFA tat: admin dang nhap thang, khong gui OTP', function () {
    config(['features.staff_mfa' => false]);
    $otp = vvFakeOtp();
    $admin = vvStaffUser('admin');

    $response = vvAdminLogin((string) $admin->email);
    vvAdminFollow($response);

    $response->assertOk()->assertJsonPath('mfa_required', false)->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('user.permissions.manage_system', true);
    expect($otp->sent)->toBe([]);
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('gui OTP that bai (503) thi khong cap phien', function () {
    app()->instance(OtpSender::class, new class implements OtpSender
    {
        public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
        {
            throw new RuntimeException('smtp down');
        }
    });
    $admin = vvStaffUser('admin');

    $response = vvAdminLogin((string) $admin->email);

    $response->assertStatus(503)->assertJson(['code' => 'OTP_DELIVERY_FAILED']);
    vvAdminFollow($response);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();
    expect(OtpCode::query()->count())->toBe(0);
});

test('11 lan sai: lan thu 11 bi 429 TOO_MANY_ATTEMPTS ke ca khi dung mat khau (account key chuan hoa)', function () {
    $teacher = vvStaffUser('teacher');

    foreach (range(1, 10) as $i) {
        vvAdminLogin($i % 2 ? mb_strtoupper((string) $teacher->email) : (string) $teacher->email, 'sai')->assertStatus(422);
    }

    vvAdminLogin((string) $teacher->email)->assertStatus(429)->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
});

test('dang nhap dung xoa bo dem sai theo tai khoan', function () {
    $teacher = vvStaffUser('teacher');

    foreach (range(1, 9) as $i) {
        vvAdminLogin((string) $teacher->email, 'sai')->assertStatus(422);
    }
    vvAdminLogin((string) $teacher->email)->assertOk();
    app('auth')->forgetGuards();

    foreach (range(1, 9) as $i) {
        vvAdminLogin((string) $teacher->email, 'sai')->assertStatus(422);
    }
});

test('bo dem sai cua host admin tach biet host api hoc sinh', function () {
    $teacher = vvStaffUser('teacher');

    foreach (range(1, 10) as $i) {
        vvAdminLogin((string) $teacher->email, 'sai')->assertStatus(422);
    }

    // Host api học sinh dùng khoá riêng (`login-fail:`) — không bị ảnh hưởng, chỉ báo WRONG_PORTAL.
    test()->postJson(vvApiUrl('/auth/login'), ['login' => $teacher->email, 'password' => 'password'], vvWebHeaders())
        ->assertForbidden()->assertJson(['code' => 'WRONG_PORTAL']);
});

test('dang nhap lai khi cookie cu con song khong bi chan boi guest', function () {
    $teacher = vvStaffUser('teacher');

    $first = vvAdminLogin((string) $teacher->email);
    $cookie1 = vvAdminFollow($first);

    $second = vvAdminLogin((string) $teacher->email)->assertOk();
    $cookie2 = $second->getCookie('vv_admin_session')->getValue();

    expect($cookie2)->not->toBe($cookie1);
});

test('validation: thieu login/password 422', function () {
    test()->postJson(vvAdminUrl('/admin/auth/login'), [], vvAdminHeaders())
        ->assertStatus(422)->assertJsonValidationErrors(['login', 'password']);
});
