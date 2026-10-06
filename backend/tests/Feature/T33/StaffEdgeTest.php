<?php

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Staff\StaffSessionRevoker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../T28/helpers.php';

if (! function_exists('vvT33Login')) {
    require_once __DIR__.'/StaffAccountTest.php';
}

test('QA: email hoa/thuong/khoang trang duoc chuan hoa; trung voi hoc sinh -> 422', function () {
    vvT33Login(vvStaffUser('admin'));
    User::factory()->student()->create(['email' => 'hs@example.com']);

    vvT33Send('POST', '/admin/staff', ['name' => 'A', 'email' => '  Mixed.Case@Example.COM  ', 'role' => 'giao_vien'])
        ->assertCreated()->assertJsonPath('email', 'mixed.case@example.com');
    vvT33Send('POST', '/admin/staff', ['name' => 'A', 'email' => ' MIXED.case@example.com', 'role' => 'giao_vien'])
        ->assertStatus(422)->assertJsonValidationErrors(['email']);
    vvT33Send('POST', '/admin/staff', ['name' => 'A', 'email' => ' HS@Example.com ', 'role' => 'giao_vien'])
        ->assertStatus(422)->assertJsonValidationErrors(['email']);
    vvT33Send('POST', '/admin/staff', ['name' => 'A', 'email' => 'a b@example.com', 'role' => 'giao_vien'])
        ->assertStatus(422);
});

test('QA: ten co dau 100 ky tu OK, 101 -> 422, HTML/ky tu dieu khien -> 422, mang/so khong 500', function () {
    vvT33Login(vvStaffUser('admin'));

    vvT33Send('POST', '/admin/staff', ['name' => str_repeat('ế', 100), 'email' => 'n100@example.com', 'role' => 'giao_vien'])->assertCreated();
    vvT33Send('POST', '/admin/staff', ['name' => str_repeat('ế', 101), 'email' => 'n101@example.com', 'role' => 'giao_vien'])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);
    foreach (['<script>alert(1)</script>', 'a < b', "x\u{0007}y", 'Nguyễn <i>Văn</i>'] as $i => $bad) {
        vvT33Send('POST', '/admin/staff', ['name' => $bad, 'email' => "bad{$i}@example.com", 'role' => 'giao_vien'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }
    vvT33Send('POST', '/admin/staff', ['name' => ['x'], 'email' => ['y'], 'role' => 5])->assertStatus(422);
    vvT33Send('POST', '/admin/staff', ['name' => 'Nguyễn Thị Ánh', 'email' => 'vn@example.com', 'role' => 'quan_ly_trang'])
        ->assertCreated()->assertJsonPath('name', 'Nguyễn Thị Ánh');
});

test('QA: audit-logs to la ngay bien (trong ngay), per_page 25/50/100 hop le, ngoai tap -> 422', function () {
    vvT33Login(vvStaffUser('admin'));

    // Trigger L2 chặn UPDATE audit_logs: đặt created_at ngay lúc INSERT.
    $tz = config('app.timezone');
    foreach ([['edge.in', '2021-03-10 23:59:59'], ['edge.next', '2021-03-11 00:00:00'], ['edge.prev', '2021-03-09 23:59:59']] as [$action, $at]) {
        DB::table('audit_logs')->insert(['action' => $action, 'actor_id' => 1, 'actor_role' => 'admin', 'created_at' => $at]);
    }

    $ids = fn (string $q) => collect(vvAdminGet("/admin/audit-logs?{$q}")->assertOk()->json('data'))->pluck('action')->all();
    expect($ids('from=2021-03-10&to=2021-03-10'))->toBe(['edge.in']);
    expect($ids('to=2021-03-10&action=edge.in'))->toBe(['edge.in']);
    expect($ids('from=2021-03-11&to=2021-03-11'))->toBe(['edge.next']);

    foreach ([25, 50, 100] as $n) {
        vvAdminGet("/admin/audit-logs?per_page={$n}")->assertOk();
    }
    foreach ([0, 1, 24, 26, 101, 1000, 'abc', -25] as $n) {
        vvAdminGet("/admin/audit-logs?per_page={$n}")->assertStatus(422);
    }
    vvAdminGet('/admin/audit-logs?actor_id=abc')->assertStatus(422);
    vvAdminGet('/admin/audit-logs?to=2021-13-45')->assertStatus(422);
    expect($tz)->toBeString();
});

test('QA: audit changes khong chua mat khau / email tho sau tao, doi vai tro, dat lai mat khau, khoa', function () {
    $admin = vvStaffUser('admin');
    vvT33Login($admin);

    $created = vvT33Send('POST', '/admin/staff', ['name' => 'Cô Mai', 'email' => 'mai.secret@example.com', 'role' => 'giao_vien'])->assertCreated();
    $id = $created->json('id');
    $pw1 = $created->json('initial_password');
    $pw2 = vvT33Send('POST', "/admin/staff/{$id}/reset-password")->assertOk()->json('initial_password');
    vvT33Send('PATCH', "/admin/staff/{$id}/role", ['role' => 'quan_ly_trang'])->assertOk();
    vvT33Send('POST', "/admin/staff/{$id}/lock")->assertOk();

    $dump = json_encode(vvAdminGet('/admin/audit-logs?per_page=100')->assertOk()->json());
    $db = json_encode(AuditLog::query()->get()->map->only(['action', 'changes'])->all());
    foreach ([$dump, $db] as $blob) {
        expect($blob)->not->toContain($pw1)->and($blob)->not->toContain($pw2)
            ->and($blob)->not->toContain('mai.secret@example.com');
    }
    expect(vvAdminGet('/admin/audit-logs?action=staff.create')->json('data.0.actor_name'))->toBe($admin->name);
});

test('QA: admin dang dang nhap bi khoa -> 403 ACCOUNT_LOCKED; doi vai tro -> 403; dat lai mat khau -> 401', function () {
    $a = vvStaffUser('admin');
    $b = vvStaffUser('admin');
    $c = vvStaffUser('admin');
    $cookieA = vvT33Login($a);
    $cookieB = vvT33Login($b);
    $cookieC = vvT33Login($c);

    // a khoá b
    vvAdminUseCookie($cookieA);
    vvT33Send('POST', "/admin/staff/{$b->id}/lock")->assertOk();
    vvAdminUseCookie($cookieB);
    vvAdminGet('/admin/staff')->assertStatus(403)->assertJson(['code' => 'ACCOUNT_LOCKED']);

    // a hạ quyền c
    vvAdminUseCookie($cookieA);
    vvT33Send('PATCH', "/admin/staff/{$c->id}/role", ['role' => 'giao_vien'])->assertOk();
    vvAdminUseCookie($cookieC);
    expect(vvAdminGet('/admin/staff')->status())->toBeIn([401, 403]);

    // đặt lại mật khẩu cho admin d
    $d = vvStaffUser('admin');
    $cookieD = vvT33Login($d);
    vvAdminUseCookie($cookieA);
    vvT33Send('POST', "/admin/staff/{$d->id}/reset-password")->assertOk();
    vvAdminUseCookie($cookieD);
    expect(vvAdminGet('/admin/staff')->status())->toBeIn([401, 403]);
});

test('QA: xoa cache (R1) -> ghi nhan hanh vi: khoa/doi vai tro van bi chan boi lop DB, khong 500', function () {
    $a = vvStaffUser('admin');
    $b = vvStaffUser('admin');
    $c = vvStaffUser('admin');
    $cookieA = vvT33Login($a);
    $cookieB = vvT33Login($b);
    $cookieC = vvT33Login($c);

    vvAdminUseCookie($cookieA);
    vvT33Send('POST', "/admin/staff/{$b->id}/lock")->assertOk();
    vvT33Send('PATCH', "/admin/staff/{$c->id}/role", ['role' => 'giao_vien'])->assertOk();
    Cache::flush();

    vvAdminUseCookie($cookieB);
    vvAdminGet('/admin/staff')->assertStatus(403);
    vvAdminUseCookie($cookieC);
    $status = vvAdminGet('/admin/staff')->status();
    expect($status)->toBeIn([401, 403]);

    // Sau khi mở khoá + xoá cache: phiên cũ của b (phiên bản cũ >= 1) có sống lại không? Ghi nhận.
    vvAdminUseCookie($cookieA);
    vvT33Send('POST', "/admin/staff/{$b->id}/unlock")->assertOk();
    Cache::flush();
    vvAdminUseCookie($cookieB);
    $revived = vvAdminGet('/admin/staff')->status();
    fwrite(STDERR, "[QA-R1] phien cu sau unlock + flush cache: HTTP {$revived}\n");
    expect($revived)->not->toBe(500);
});

test('QA: CLI staff:lock admin cuoi bi chan; staff:create email trung/HTML khong 500', function () {
    $a = vvStaffUser('admin', ['email' => 'last@example.com']);
    vvStaffUser('admin', ['status' => UserStatus::Locked]);

    expect(Artisan::call('staff:lock', ['email' => 'last@example.com']))->not->toBe(0);
    expect(Artisan::output())->toContain('cuối');
    expect($a->fresh()->status)->toBe(UserStatus::Active);

    expect(Artisan::call('staff:create', ['email' => 'LAST@example.com', '--role' => 'giao_vien']))->not->toBe(0);
    User::factory()->student()->create(['email' => 'hs2@example.com']);
    expect(Artisan::call('staff:create', ['email' => 'hs2@example.com', '--role' => 'giao_vien']))->not->toBe(0);
    expect(Artisan::call('staff:create', ['email' => 'h@example.com', '--role' => 'giao_vien', '--name' => '<img src=x onerror=1>']))->not->toBe(0);
    expect(Artisan::call('staff:create', ['email' => 'h@example.com', '--role' => 'hoc_sinh']))->not->toBe(0);
    expect(User::query()->where('email', 'h@example.com')->exists())->toBeFalse();
    expect(Artisan::call('staff:lock', ['email' => 'khong-co@example.com']))->not->toBe(0);
});

test('QA BUG-1: CLI staff:lock khong duoc khoa tai khoan hoc sinh', function () {
    $hs = User::factory()->student()->create(['email' => 'hs3@example.com']);

    expect(Artisan::call('staff:lock', ['email' => 'hs3@example.com']))->not->toBe(0);
    expect($hs->fresh()->status)->toBe(UserStatus::Active);
});

test('QA: phan quyen - QLT/GV 403 truoc validate, hoc sinh 401/403, khach 401', function () {
    $target = vvStaffUser('teacher');
    // Khách kiểm trước khi đăng nhập (session store trong tiến trình test giữ trạng thái của lần đăng nhập trước).
    vvAdminUseCookie(Str::random(40));
    vvT33Send('POST', '/admin/staff', [])->assertUnauthorized();
    vvAdminGet('/admin/audit-logs')->assertUnauthorized();
    foreach (['pageManager', 'teacher'] as $state) {
        vvT33Login(vvStaffUser($state));
        vvT33Send('POST', '/admin/staff', [])->assertForbidden();
        vvT33Send('PATCH', "/admin/staff/{$target->id}/role", ['role' => 'x'])->assertForbidden();
        vvT33Send('POST', "/admin/staff/{$target->id}/lock")->assertForbidden();
        vvT33Send('POST', '/admin/staff/999999/reset-password')->assertForbidden();
        vvAdminGet('/admin/audit-logs?per_page=7')->assertForbidden();
        vvAdminGet('/admin/staff')->assertForbidden();
    }
});

test('QA: hoc sinh dang nhap cong admin -> 403 WRONG_PORTAL, khong tao phien', function () {
    $student = User::factory()->student()->create(['email' => 'stu@example.com']);

    vvAdminLogin('stu@example.com')->assertForbidden()->assertJson(['code' => 'WRONG_PORTAL']);
    expect(vvAdminGet('/admin/staff')->status())->toBe(401);
    expect(StaffSessionRevoker::version($student->id))->toBe(0);
});
