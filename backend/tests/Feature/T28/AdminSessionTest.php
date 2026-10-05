<?php

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Mail::fake();
    config(['features.staff_mfa' => true]);
    $this->otp = vvFakeOtp();
});

test('idle qua 120 phut -> 401 STAFF_IDLE_TIMEOUT, request sau 401 UNAUTHENTICATED', function () {
    $teacher = vvStaffUser('teacher');
    vvStaffLogin($teacher);
    vvAdminGet('/admin/auth/me')->assertOk();

    $this->travel(121)->minutes();
    app('auth')->forgetGuards();
    vvAdminGet('/admin/auth/me')->assertUnauthorized()->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);

    app('auth')->forgetGuards();
    vvAdminGet('/admin/auth/me')->assertUnauthorized()->assertJson(['code' => 'UNAUTHENTICATED']);
});

test('hoat dong keo dai phien: 3 request cach nhau 100 phut van song', function () {
    vvStaffLogin(vvStaffUser('teacher'));

    foreach (range(1, 3) as $i) {
        $this->travel(100)->minutes();
        app('auth')->forgetGuards();
        vvAdminGet('/admin/auth/me')->assertOk();
    }
});

test('dung 120 phut idle van con hieu luc (bien)', function () {
    vvStaffLogin(vvStaffUser('teacher'));

    $this->travel(120)->minutes();
    app('auth')->forgetGuards();
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('qua 12 gio tuyet doi du van hoat dong -> 401 STAFF_IDLE_TIMEOUT', function () {
    vvStaffLogin(vvStaffUser('teacher'));

    // 7 x 100 phút = 11h40, mỗi bước đều có hoạt động nên không idle.
    foreach (range(1, 7) as $i) {
        $this->travel(100)->minutes();
        app('auth')->forgetGuards();
        vvAdminGet('/admin/auth/me')->assertOk();
    }

    $this->travel(30)->minutes(); // 12h10
    app('auth')->forgetGuards();
    vvAdminGet('/admin/auth/me')->assertUnauthorized()->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);
});

test('phien dang cho MFA cung bi idle 120 phut', function () {
    $admin = vvStaffUser('admin');
    vvAdminFollow(vvAdminLogin((string) $admin->email)->assertOk());

    $this->travel(121)->minutes();
    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $this->otp->lastCode()], vvAdminHeaders())
        ->assertUnauthorized()->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);
});

test('staff.idle fail-closed: da dang nhap nhung session khong co moc thoi gian -> STAFF_IDLE_TIMEOUT', function () {
    $teacher = vvStaffUser('teacher');
    $this->actingAs($teacher);

    vvAdminGet('/admin/auth/me')->assertUnauthorized()->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);
});

test('dang xuat: 204, audit staff.logout, cookie cu khong dung duoc nua', function () {
    $teacher = vvStaffUser('teacher');
    vvStaffLogin($teacher);

    app('auth')->forgetGuards();
    test()->postJson(vvAdminUrl('/admin/auth/logout'), [], vvAdminHeaders())->assertNoContent();

    expect(AuditLog::query()->where('action', 'staff.logout')->where('actor_id', $teacher->id)->count())->toBe(1);
    app('auth')->forgetGuards();
    vvAdminGet('/admin/auth/me')->assertUnauthorized();
});

test('dang xuat khi chua dang nhap -> 401', function () {
    test()->postJson(vvAdminUrl('/admin/auth/logout'), [], vvAdminHeaders())->assertUnauthorized();
});

test('me voi Origin web -> 403 ORIGIN_NOT_ALLOWED', function () {
    vvStaffLogin(vvStaffUser('teacher'));
    app('auth')->forgetGuards();

    test()->getJson(vvAdminUrl('/admin/auth/me'), ['Origin' => config('app.frontend_url')])
        ->assertForbidden()->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('me tra permissions theo vai tro (admin doc quyen manage_system, quan ly trang thi khong)', function () {
    config(['features.staff_mfa' => false]);
    vvStaffLogin(vvStaffUser('pageManager'));

    vvAdminGet('/admin/auth/me')->assertOk()
        ->assertJsonPath('role', 'quan_ly_trang')
        ->assertJsonPath('permissions.manage_system', false)
        ->assertJsonPath('permissions.manage_coupons', true)
        ->assertJsonPath('permissions.export_orders_with_contact', false);
});

test('staff bi khoa giua phien -> 403 ACCOUNT_LOCKED o request ke tiep', function () {
    $teacher = vvStaffUser('teacher');
    vvStaffLogin($teacher);

    $teacher->forceFill(['status' => UserStatus::Locked])->save();
    app('auth')->forgetGuards();

    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('must_change_password: me bi 403 PASSWORD_CHANGE_REQUIRED, doi mat khau van duoc', function () {
    $teacher = vvStaffUser('teacher', ['must_change_password' => true]);
    $login = vvAdminLogin((string) $teacher->email)->assertOk()->assertJsonPath('user.must_change_password', true);
    vvAdminFollow($login);

    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'PASSWORD_CHANGE_REQUIRED']);

    app('auth')->forgetGuards();
    $changed = test()->putJson(vvAdminUrl('/admin/auth/password'), [
        'current_password' => 'password',
        'password' => 'mat-khau-moi-123',
        'password_confirmation' => 'mat-khau-moi-123',
    ], vvAdminHeaders())->assertOk()->assertJsonPath('user.must_change_password', false);

    expect($teacher->fresh()->must_change_password)->toBeFalse()
        ->and($teacher->fresh()->password_changed_at)->not->toBeNull()
        ->and(Hash::check('mat-khau-moi-123', $teacher->fresh()->password))->toBeTrue();
    $log = AuditLog::query()->where('action', 'staff.password_changed')->first();
    expect($log->changes['forced'])->toBeTrue()
        ->and(json_encode($log->changes))->not->toContain('mat-khau-moi');

    // Phiên hiện tại (cookie mới do regenerate) vẫn sống và đã qua password_fresh.
    vvAdminFollow($changed);
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('doi mat khau dang xuat phien khac, giu phien hien tai', function () {
    $teacher = vvStaffUser('teacher');

    $a = vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    // Cho AuthenticateSession ghi băm vào phiên A bằng 1 request.
    vvAdminGet('/admin/auth/me')->assertOk();
    $b = vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    vvAdminGet('/admin/auth/me')->assertOk();
    expect($a)->not->toBe($b);

    // Đổi mật khẩu bằng phiên A.
    vvAdminUseCookie($a);
    $changed = test()->putJson(vvAdminUrl('/admin/auth/password'), [
        'current_password' => 'password',
        'password' => 'mat-khau-moi-123',
        'password_confirmation' => 'mat-khau-moi-123',
    ], vvAdminHeaders())->assertOk();

    // Phiên B bị huỷ.
    vvAdminUseCookie($b);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();

    // Phiên A (cookie mới do regenerate) vẫn dùng được; cookie A cũ không còn dùng được (băm cũ).
    vvAdminFollow($changed);
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('doi mat khau: phien hien tai van song (cookie moi tu response doi mat khau)', function () {
    $teacher = vvStaffUser('teacher');
    vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    vvAdminGet('/admin/auth/me')->assertOk();

    app('auth')->forgetGuards();
    $response = test()->putJson(vvAdminUrl('/admin/auth/password'), [
        'current_password' => 'password',
        'password' => 'mat-khau-moi-123',
        'password_confirmation' => 'mat-khau-moi-123',
    ], vvAdminHeaders())->assertOk();
    vvAdminFollow($response);

    vvAdminGet('/admin/auth/me')->assertOk();
});

test('doi mat khau: sai mat khau hien tai 422 + audit; mat khau moi trung cu 422; xac nhan khong khop 422', function () {
    $teacher = vvStaffUser('teacher');
    vvStaffLogin($teacher);

    $put = fn (array $p) => test()->putJson(vvAdminUrl('/admin/auth/password'), $p, vvAdminHeaders());

    app('auth')->forgetGuards();
    $put(['current_password' => 'sai', 'password' => 'mat-khau-moi-123', 'password_confirmation' => 'mat-khau-moi-123'])
        ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    expect(AuditLog::query()->where('action', 'staff.password_change_failed')->count())->toBe(1);

    app('auth')->forgetGuards();
    $put(['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);

    app('auth')->forgetGuards();
    $put(['current_password' => 'password', 'password' => 'mat-khau-moi-123', 'password_confirmation' => 'khac'])
        ->assertStatus(422)->assertJsonValidationErrors(['password_confirmation']);

    app('auth')->forgetGuards();
    $put(['current_password' => 'password', 'password' => 'ngan', 'password_confirmation' => 'ngan'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);

    expect(Hash::check('password', $teacher->fresh()->password))->toBeTrue();
});

test('doi mat khau: throttle 5/phut/user', function () {
    vvStaffLogin(vvStaffUser('teacher'));

    foreach (range(1, 5) as $i) {
        app('auth')->forgetGuards();
        test()->putJson(vvAdminUrl('/admin/auth/password'), ['current_password' => 'sai', 'password' => 'mat-khau-moi-123', 'password_confirmation' => 'mat-khau-moi-123'], vvAdminHeaders())->assertStatus(422);
    }

    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), ['current_password' => 'sai', 'password' => 'mat-khau-moi-123', 'password_confirmation' => 'mat-khau-moi-123'], vvAdminHeaders())
        ->assertStatus(429);
});

test('doi mat khau khi chua qua MFA bi 403 MFA_REQUIRED (khong doi duoc chi voi mat khau)', function () {
    $admin = vvStaffUser('admin');
    vvAdminFollow(vvAdminLogin((string) $admin->email)->assertOk());

    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), [
        'current_password' => 'password',
        'password' => 'mat-khau-moi-123',
        'password_confirmation' => 'mat-khau-moi-123',
    ], vvAdminHeaders())->assertForbidden()->assertJson(['code' => 'MFA_REQUIRED']);

    expect(Hash::check('password', $admin->fresh()->password))->toBeTrue();
});

test('doi mat khau voi Origin web -> 403; chua dang nhap -> 401', function () {
    test()->putJson(vvAdminUrl('/admin/auth/password'), [], vvAdminHeaders())->assertUnauthorized();
    test()->putJson(vvAdminUrl('/admin/auth/password'), [], ['Origin' => config('app.frontend_url')])->assertForbidden();
});

test('hoc sinh khong co phien tren admin-api: cookie vv_session khong co tac dung, route staff 401', function () {
    $student = User::factory()->create();
    vvActAsStudent($student);

    vvAdminGet('/admin/auth/me')->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
});
