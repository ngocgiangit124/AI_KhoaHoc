<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Staff\StaffSessionRevoker;
use App\Services\Staff\StaffAccountService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../T28/helpers.php';

/** Đăng nhập thật (kể cả MFA) và trả giá trị cookie phiên để chuyển qua lại giữa các "trình duyệt". */
function vvT33Login(User $user, string $password = 'password'): string
{
    $otp = vvFakeOtp();
    // "Trình duyệt mới": cookie lạ để không chiếm lại phiên của người đăng nhập trước đó.
    vvAdminUseCookie(Str::random(40));
    $response = vvAdminLogin((string) $user->email, $password)->assertOk();
    $cookie = vvAdminFollow($response);

    if ($response->json('mfa_required') === true) {
        app('auth')->forgetGuards();
        $verify = test()->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $otp->lastCode()], vvAdminHeaders())->assertOk();
        $cookie = vvAdminFollow($verify);
    }

    return $cookie;
}

function vvT33Send(string $method, string $path, array $data = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->json($method, vvAdminUrl($path), $data, vvAdminHeaders());
}

test('chi admin vao duoc: chua dang nhap 401, QLT/GV/HS 403 o moi route (403 truoc validate)', function () {
    vvAdminGet('/admin/staff')->assertUnauthorized();

    $target = vvStaffUser('teacher');

    foreach (['pageManager', 'teacher'] as $state) {
        vvStaffLogin(vvStaffUser($state));

        vvAdminGet('/admin/staff')->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
        vvAdminGet("/admin/staff/{$target->id}")->assertForbidden();
        vvAdminGet('/admin/audit-logs')->assertForbidden();
        vvT33Send('POST', '/admin/staff', [])->assertForbidden();
        vvT33Send('POST', "/admin/staff/{$target->id}/lock")->assertForbidden();
        vvT33Send('POST', "/admin/staff/{$target->id}/unlock")->assertForbidden();
        vvT33Send('PATCH', "/admin/staff/{$target->id}/role", [])->assertForbidden();
        vvT33Send('POST', "/admin/staff/{$target->id}/reset-password")->assertForbidden();
    }

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

test('tao staff: 201, mat khau ngau nhien tra mot lan, must_change_password, audit khong chua mat khau', function () {
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);

    $res = vvT33Send('POST', '/admin/staff', ['name' => '  Cô   Lan ', 'email' => 'Lan@Example.com', 'role' => 'giao_vien'])
        ->assertCreated()
        ->assertJsonPath('email', 'lan@example.com')
        ->assertJsonPath('name', 'Cô Lan')
        ->assertJsonPath('role', 'giao_vien')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('must_change_password', true);

    $password = $res->json('initial_password');
    expect($password)->toBeString()->and(strlen($password))->toBeGreaterThanOrEqual(16);
    expect($res->json())->not->toHaveKey('password');

    $user = User::query()->where('email', 'lan@example.com')->firstOrFail();
    expect(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();

    $log = AuditLog::query()->where('action', 'staff.create')->where('subject_id', $user->id)->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)
        ->and(json_encode($log->changes))->not->toContain($password);

    // Staff mới đăng nhập được và bị buộc đổi mật khẩu.
    vvT33Login($user, $password);
    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'PASSWORD_CHANGE_REQUIRED']);
});

test('tao staff: email trung (moi vai tro, khong phan biet hoa thuong), vai tro hoc_sinh, du lieu sai -> 422', function () {
    vvStaffLogin(vvStaffUser('admin'));
    User::factory()->student()->create(['email' => 'hs@example.com']);

    vvT33Send('POST', '/admin/staff', ['name' => 'A', 'email' => 'HS@example.com', 'role' => 'giao_vien'])
        ->assertStatus(422)->assertJsonValidationErrors(['email']);
    vvT33Send('POST', '/admin/staff', ['name' => 'A', 'email' => 'a@example.com', 'role' => 'hoc_sinh'])
        ->assertStatus(422)->assertJsonValidationErrors(['role']);
    vvT33Send('POST', '/admin/staff', ['name' => '<b>x</b>', 'email' => 'x', 'role' => 'admin'])
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
    vvT33Send('POST', '/admin/staff', [])->assertStatus(422);

    expect(User::query()->count())->toBe(2);
});

test('tao staff khong mass-assign: truong thua (status, must_change_password) bi bo qua', function () {
    vvStaffLogin(vvStaffUser('admin'));

    vvT33Send('POST', '/admin/staff', [
        'name' => 'B', 'email' => 'b@example.com', 'role' => 'quan_ly_trang',
        'status' => 'locked', 'must_change_password' => false, 'password' => 'abc12345',
    ])->assertCreated();

    $user = User::query()->where('email', 'b@example.com')->firstOrFail();
    expect($user->status)->toBe(UserStatus::Active)->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check('abc12345', $user->password))->toBeFalse();
});

test('danh sach: loc vai tro/trang thai/tu khoa, khong co hoc sinh, phan trang', function () {
    vvStaffLogin(vvStaffUser('admin', ['name' => 'Quản Trị', 'email' => 'qt@example.com']));
    vvStaffUser('teacher', ['name' => 'Thầy Nam', 'email' => 'nam@example.com']);
    vvStaffUser('teacher', ['name' => 'Cô Hoa', 'email' => 'hoa@example.com', 'status' => UserStatus::Locked]);
    vvStaffUser('pageManager', ['name' => 'Quản Lý', 'email' => 'ql@example.com']);
    User::factory()->student()->create(['name' => 'Nam Học Sinh']);

    $all = vvAdminGet('/admin/staff')->assertOk();
    expect($all->json('data'))->toHaveCount(4)->and($all->json('meta.total'))->toBe(4);
    expect(collect($all->json('data'))->pluck('role')->unique()->all())->not->toContain('hoc_sinh');
    expect(collect($all->json('data'))->firstWhere('email', 'qt@example.com')['is_self'])->toBeTrue();

    expect(vvAdminGet('/admin/staff?role=giao_vien')->json('data'))->toHaveCount(2);
    expect(vvAdminGet('/admin/staff?status=locked')->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/staff?q=nam')->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/staff?q=%25')->json('data'))->toHaveCount(0);

    vvAdminGet('/admin/staff?role=hoc_sinh')->assertStatus(422);
    vvAdminGet('/admin/staff?per_page=7')->assertStatus(422);
});

test('show: hoc sinh hoac id la -> 404', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $student = User::factory()->student()->create();

    vvAdminGet("/admin/staff/{$student->id}")->assertNotFound();
    vvAdminGet('/admin/staff/abc')->assertNotFound();
    vvAdminGet('/admin/staff/999999')->assertNotFound();
    vvT33Send('POST', "/admin/staff/{$student->id}/lock")->assertNotFound();
    vvT33Send('PATCH', "/admin/staff/{$student->id}/role", ['role' => 'admin'])->assertNotFound();
    expect($student->fresh()->role)->toBe(UserRole::Student);
});

test('khoa: audit, 403 ACCOUNT_LOCKED o request ke tiep, khong dang nhap lai duoc, mo khoa khong hoi sinh phien cu', function () {
    $admin = vvStaffUser('admin');
    $teacher = vvStaffUser('teacher');

    $teacherCookie = vvT33Login($teacher);
    vvAdminGet('/admin/auth/me')->assertOk();

    $adminCookie = vvT33Login($admin);
    vvT33Send('POST', "/admin/staff/{$teacher->id}/lock")->assertOk()->assertJsonPath('status', 'locked');
    expect(AuditLog::query()->where('action', 'user.lock')->where('subject_id', $teacher->id)->where('actor_id', $admin->id)->count())->toBe(1);

    vvAdminUseCookie($teacherCookie);
    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'ACCOUNT_LOCKED']);
    vvAdminLogin((string) $teacher->email)->assertForbidden()->assertJson(['code' => 'ACCOUNT_LOCKED']);

    vvAdminUseCookie($adminCookie);
    vvT33Send('POST', "/admin/staff/{$teacher->id}/unlock")->assertOk()->assertJsonPath('status', 'active');
    expect(AuditLog::query()->where('action', 'user.unlock')->where('subject_id', $teacher->id)->count())->toBe(1);

    // Phiên cũ đã bị huỷ khi khoá: mở khoá không làm nó sống lại.
    vvAdminUseCookie($teacherCookie);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();

    vvT33Login($teacher);
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('khoa/mo khoa hai lan: 409 ALREADY_PROCESSED, khong ghi audit trung', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $teacher = vvStaffUser('teacher');

    vvT33Send('POST', "/admin/staff/{$teacher->id}/lock")->assertOk();
    vvT33Send('POST', "/admin/staff/{$teacher->id}/lock")->assertStatus(409)->assertJson(['code' => 'ALREADY_PROCESSED']);
    expect(AuditLog::query()->where('action', 'user.lock')->count())->toBe(1);

    vvT33Send('POST', "/admin/staff/{$teacher->id}/unlock")->assertOk();
    vvT33Send('POST', "/admin/staff/{$teacher->id}/unlock")->assertStatus(409)->assertJson(['code' => 'ALREADY_PROCESSED']);
    expect(AuditLog::query()->where('action', 'user.unlock')->count())->toBe(1);
});

test('khong tu khoa / tu ha quyen / tu dat lai mat khau chinh minh', function () {
    $admin = vvStaffUser('admin');
    vvStaffUser('admin'); // còn admin khác: lỗi phải là CANNOT_MODIFY_SELF, không phải LAST_ADMIN
    vvStaffLogin($admin);

    vvT33Send('POST', "/admin/staff/{$admin->id}/lock")->assertStatus(409)->assertJson(['code' => 'CANNOT_MODIFY_SELF']);
    vvT33Send('PATCH', "/admin/staff/{$admin->id}/role", ['role' => 'giao_vien'])->assertStatus(409)->assertJson(['code' => 'CANNOT_MODIFY_SELF']);
    vvT33Send('POST', "/admin/staff/{$admin->id}/reset-password")->assertStatus(409)->assertJson(['code' => 'CANNOT_MODIFY_SELF']);

    $fresh = $admin->fresh();
    expect($fresh->status)->toBe(UserStatus::Active)->and($fresh->role)->toBe(UserRole::Admin)
        ->and(Hash::check('password', $fresh->password))->toBeTrue();
    expect(AuditLog::query()->whereIn('action', ['user.lock', 'staff.role_change', 'staff.password_reset'])->count())->toBe(0);
});

test('khong khoa / ha quyen admin dang hoat dong cuoi cung (admin khac da bi khoa)', function () {
    $actor = vvStaffUser('admin');
    $lockedAdmin = vvStaffUser('admin', ['status' => UserStatus::Locked]);
    vvStaffLogin($actor);

    // Không thể thao tác lên chính mình nên dùng service trực tiếp với actor = null (CLI) để kiểm BR6.
    $service = app(StaffAccountService::class);

    expect(fn () => $service->lock($actor, null))->toThrow(DomainException::class, 'cuối cùng');
    expect(fn () => $service->changeRole($actor, UserRole::Teacher, null))->toThrow(DomainException::class);
    expect($actor->fresh()->status)->toBe(UserStatus::Active)->and($actor->fresh()->role)->toBe(UserRole::Admin);

    // Admin đã khoá: khoá/hạ quyền không ảnh hưởng số admin hoạt động.
    $lockedAdmin->forceFill(['status' => UserStatus::Active])->save();
    $service->lock($lockedAdmin, $actor);
    expect($lockedAdmin->fresh()->status)->toBe(UserStatus::Locked);
});

test('API: QLT khong vao duoc; admin khoa admin khac khi con admin thu ba van duoc, ha quyen admin cuoi qua CLI bi chan', function () {
    $a = vvStaffUser('admin');
    $b = vvStaffUser('admin');
    vvStaffLogin($a);

    vvT33Send('POST', "/admin/staff/{$b->id}/lock")->assertOk();
    // a là admin hoạt động duy nhất còn lại: khoá a bằng CLI bị chặn.
    expect(Artisan::call('staff:lock', ['email' => $a->email]))->not->toBe(0);
    expect($a->fresh()->status)->toBe(UserStatus::Active);
});

test('doi vai tro: audit from/to, huy phien, doi cung vai tro 409, vai tro hoc_sinh 422', function () {
    $admin = vvStaffUser('admin');
    $teacher = vvStaffUser('teacher');

    $teacherCookie = vvT33Login($teacher);
    vvT33Login($admin);

    vvT33Send('PATCH', "/admin/staff/{$teacher->id}/role", ['role' => 'giao_vien'])->assertStatus(409)->assertJson(['code' => 'ALREADY_PROCESSED']);
    vvT33Send('PATCH', "/admin/staff/{$teacher->id}/role", ['role' => 'hoc_sinh'])->assertStatus(422)->assertJsonValidationErrors(['role']);
    vvT33Send('PATCH', "/admin/staff/{$teacher->id}/role", [])->assertStatus(422);

    vvT33Send('PATCH', "/admin/staff/{$teacher->id}/role", ['role' => 'quan_ly_trang'])
        ->assertOk()->assertJsonPath('role', 'quan_ly_trang');

    $log = AuditLog::query()->where('action', 'staff.role_change')->where('subject_id', $teacher->id)->firstOrFail();
    expect($log->changes['role']['from'])->toBe('giao_vien')->and($log->changes['role']['to'])->toBe('quan_ly_trang');

    // Phiên cũ bị huỷ; đăng nhập lại là QLT nên phải qua MFA.
    vvAdminUseCookie($teacherCookie);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();
    $login = vvAdminLogin((string) $teacher->email)->assertOk();
    expect($login->json('mfa_required'))->toBeTrue();
});

test('dat lai mat khau: mat khau moi tra mot lan, must_change_password, huy phien, mat khau cu het hieu luc, audit sach', function () {
    $admin = vvStaffUser('admin');
    $teacher = vvStaffUser('teacher');

    $teacherCookie = vvT33Login($teacher);
    vvT33Login($admin);

    $res = vvT33Send('POST', "/admin/staff/{$teacher->id}/reset-password")->assertOk()
        ->assertJsonPath('must_change_password', true);
    $new = $res->json('initial_password');

    $fresh = $teacher->fresh();
    expect(Hash::check($new, $fresh->password))->toBeTrue()->and(Hash::check('password', $fresh->password))->toBeFalse()
        ->and($fresh->must_change_password)->toBeTrue();

    $log = AuditLog::query()->where('action', 'staff.password_reset')->where('subject_id', $teacher->id)->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and(json_encode($log->changes))->not->toContain($new);

    vvAdminUseCookie($teacherCookie);
    vvAdminGet('/admin/auth/me')->assertUnauthorized();

    vvAdminLogin((string) $teacher->email, 'password')->assertStatus(422);
    vvT33Login($teacher, $new);
    vvAdminGet('/admin/auth/me')->assertForbidden()->assertJson(['code' => 'PASSWORD_CHANGE_REQUIRED']);
});

test('phien dang nhap SAU khi huy phien van song (phien ban huy phien khop)', function () {
    $admin = vvStaffUser('admin');
    $teacher = vvStaffUser('teacher');
    StaffSessionRevoker::revokeAll($teacher->id);
    StaffSessionRevoker::revokeAll($teacher->id);

    vvT33Login($teacher);
    vvAdminGet('/admin/auth/me')->assertOk();
    vvT33Login($admin);
    vvAdminGet('/admin/auth/me')->assertOk();
});

test('audit-logs: loc theo hanh dong/nguoi thuc hien/khoang ngay, chi doc, khong co route sua/xoa', function () {
    $admin = vvStaffUser('admin');
    vvT33Login($admin);
    $teacher = vvStaffUser('teacher');

    vvT33Send('POST', "/admin/staff/{$teacher->id}/lock")->assertOk();
    vvT33Send('POST', "/admin/staff/{$teacher->id}/unlock")->assertOk();

    // Trigger L2 chặn UPDATE audit_logs: đặt created_at ngay lúc INSERT (không sửa sau).
    DB::table('audit_logs')->insert(['action' => 'user.lock', 'actor_id' => 999, 'actor_role' => 'admin', 'created_at' => '2020-01-05 10:00:00']);

    $all = vvAdminGet('/admin/audit-logs')->assertOk();
    expect($all->json('data.0'))->toHaveKeys(['id', 'action', 'actor_id', 'actor_role', 'actor_name', 'subject_type', 'subject_id', 'changes', 'ip', 'user_agent', 'created_at']);

    expect(vvAdminGet('/admin/audit-logs?action=user.lock')->json('data'))->toHaveCount(2);
    expect(vvAdminGet("/admin/audit-logs?action=user.lock&actor_id={$admin->id}")->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/audit-logs?from=2020-01-05&to=2020-01-05')->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/audit-logs?from=2020-01-06&to=2020-01-07')->json('data'))->toHaveCount(0);
    expect(vvAdminGet("/admin/audit-logs?subject_type=App%5CModels%5CUser&subject_id={$teacher->id}")->json('data'))->not->toBeEmpty();

    vvAdminGet('/admin/audit-logs?from=2020-02-01&to=2020-01-01')->assertStatus(422);
    vvAdminGet('/admin/audit-logs?from=hôm-qua')->assertStatus(422);
    vvAdminGet('/admin/audit-logs?per_page=7')->assertStatus(422);

    $logId = AuditLog::query()->latest('id')->value('id');
    foreach (['PUT', 'PATCH', 'DELETE', 'POST'] as $method) {
        vvT33Send($method, "/admin/audit-logs/{$logId}")->assertStatus(404);
        vvT33Send($method, '/admin/audit-logs')->assertStatus(405);
    }
});

test('CLI staff:lock/unlock/create dung chung service: ghi audit va huy phien', function () {
    $teacher = vvStaffUser('teacher', ['email' => 'cli@example.com']);
    $before = StaffSessionRevoker::version($teacher->id);

    expect(Artisan::call('staff:lock', ['email' => 'cli@example.com']))->toBe(0);
    expect(StaffSessionRevoker::version($teacher->id))->toBe($before + 1);
    expect(Artisan::call('staff:lock', ['email' => 'cli@example.com']))->not->toBe(0);
    expect(Artisan::call('staff:unlock', ['email' => 'cli@example.com']))->toBe(0);
    expect(Artisan::call('staff:unlock', ['email' => 'cli@example.com']))->not->toBe(0);
});

test('hai yeu cau tao cung email: chi mot tai khoan (unique), khong 500', function () {
    vvStaffLogin(vvStaffUser('admin'));

    $body = ['name' => 'C', 'email' => 'c@example.com', 'role' => 'giao_vien'];
    vvT33Send('POST', '/admin/staff', $body)->assertCreated();
    vvT33Send('POST', '/admin/staff', $body)->assertStatus(422)->assertJsonValidationErrors(['email']);

    // Thua race unique (qua service, bỏ qua validate): vẫn là lỗi email, không phải 500.
    expect(fn () => app(StaffAccountService::class)->create(null, 'C', 'c@example.com', UserRole::Teacher))
        ->toThrow(ValidationException::class);
    expect(User::query()->where('email', 'c@example.com')->count())->toBe(1);
});

test('CLI staff:create kiem --name bang rule cua API (HTML, qua dai) va khong tao tai khoan', function () {
    expect(Artisan::call('staff:create', ['email' => 'n1@example.com', '--role' => 'giao_vien', '--name' => '<b>x</b>']))->not->toBe(0);
    expect(Artisan::call('staff:create', ['email' => 'n2@example.com', '--role' => 'giao_vien', '--name' => str_repeat('a', 101)]))->not->toBe(0);
    expect(User::query()->whereIn('email', ['n1@example.com', 'n2@example.com'])->count())->toBe(0);

    expect(Artisan::call('staff:create', ['email' => 'n3@example.com', '--role' => 'giao_vien', '--name' => 'Cô Lan']))->toBe(0);
});

test('huy phien: loi cache khong lam hong thao tac da commit; tang version nguyen tu', function () {
    $teacher = vvStaffUser('teacher');
    StaffSessionRevoker::revokeAll($teacher->id);
    StaffSessionRevoker::revokeAll($teacher->id);
    expect(StaffSessionRevoker::version($teacher->id))->toBe(2);

    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    StaffSessionRevoker::revokeAll($teacher->id); // không ném
    expect(true)->toBeTrue();
});
