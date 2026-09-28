<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

test('staff:create sinh mat khau ngau nhien, in ra mot lan, buoc doi mat khau', function () {
    Artisan::call('staff:create', [
        'email' => 'qlt@vitaminvui.test',
        '--role' => 'quan_ly_trang',
    ]);

    $output = Artisan::output();

    $user = User::query()->where('email', 'qlt@vitaminvui.test')->first();

    expect($user)->not->toBeNull();
    expect($user->role)->toBe(UserRole::PageManager);
    expect($user->status)->toBe(UserStatus::Active);
    expect($user->must_change_password)->toBeTrue();
    expect($output)->toContain('Mật khẩu');

    expect(AuditLog::query()->where('action', 'staff.create')->where('subject_id', $user->id)->exists())
        ->toBeTrue();
});

test('staff:create van chay khi APP_ENV=production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(app()->environment('production'))->toBeTrue();

    $exitCode = Artisan::call('staff:create', [
        'email' => 'admin-prod@vitaminvui.test',
        '--role' => 'admin',
    ]);

    expect($exitCode)->toBe(0);
    expect(User::query()->where('email', 'admin-prod@vitaminvui.test')->exists())->toBeTrue();
});

test('seeder demo KHONG chay khi khong o moi truong local', function () {
    expect(app()->environment('local'))->toBeFalse();

    (new DatabaseSeeder)->run();

    expect(User::query()->where('email', 'admin@vitaminvui.test')->exists())->toBeFalse();
});

test('staff:create tu choi role ngoai danh sach cho phep (vd hoc_sinh hoac chuoi la)', function () {
    $exitCode = Artisan::call('staff:create', [
        'email' => 'should-not-exist@vitaminvui.test',
        '--role' => 'hoc_sinh',
    ]);

    expect($exitCode)->not->toBe(0);
    expect(User::query()->where('email', 'should-not-exist@vitaminvui.test')->exists())->toBeFalse();
});

test('staff:create tu choi role khong ton tai trong enum UserRole', function () {
    $exitCode = Artisan::call('staff:create', [
        'email' => 'ghost-role@vitaminvui.test',
        '--role' => 'super_admin_hacker',
    ]);

    expect($exitCode)->not->toBe(0);
    expect(User::query()->where('email', 'ghost-role@vitaminvui.test')->exists())->toBeFalse();
});

test('staff:create tu choi email khong hop le', function () {
    $exitCode = Artisan::call('staff:create', [
        'email' => 'khong-phai-email',
        '--role' => 'quan_ly_trang',
    ]);

    expect($exitCode)->not->toBe(0);
});

test('staff:create tu choi email da ton tai (khong tao trung)', function () {
    $existing = User::factory()->pageManager()->create(['email' => 'da-ton-tai@vitaminvui.test']);

    $exitCode = Artisan::call('staff:create', [
        'email' => 'da-ton-tai@vitaminvui.test',
        '--role' => 'admin',
    ]);

    expect($exitCode)->not->toBe(0);
    // Vai trò tài khoản gốc không bị ghi đè thành admin.
    expect($existing->fresh()->role)->toBe(UserRole::PageManager);
    expect(User::query()->where('email', 'da-ton-tai@vitaminvui.test')->count())->toBe(1);
});

test('staff:lock voi email khong ton tai tra loi va khong ghi audit', function () {
    $exitCode = Artisan::call('staff:lock', ['email' => 'khong-ton-tai@vitaminvui.test']);

    expect($exitCode)->not->toBe(0);
    expect(AuditLog::query()->where('action', 'user.lock')->exists())->toBeFalse();
});

test('staff:unlock voi email khong ton tai tra loi va khong ghi audit', function () {
    $exitCode = Artisan::call('staff:unlock', ['email' => 'khong-ton-tai@vitaminvui.test']);

    expect($exitCode)->not->toBe(0);
    expect(AuditLog::query()->where('action', 'user.unlock')->exists())->toBeFalse();
});

test('staff:lock va staff:unlock doi status va ghi audit', function () {
    $user = User::factory()->pageManager()->create(['email' => 'lockme@vitaminvui.test']);

    Artisan::call('staff:lock', ['email' => 'lockme@vitaminvui.test']);
    expect($user->fresh()->status)->toBe(UserStatus::Locked);
    expect(AuditLog::query()->where('action', 'user.lock')->exists())->toBeTrue();

    Artisan::call('staff:unlock', ['email' => 'lockme@vitaminvui.test']);
    expect($user->fresh()->status)->toBe(UserStatus::Active);
    expect(AuditLog::query()->where('action', 'user.unlock')->exists())->toBeTrue();
});

/**
 * L4 còn lại (review bảo mật T01/T02, xác minh lại) — `staff:lock`/`unlock`
 * trước đây ghi `changes` RỖNG, không có giá trị trước/sau.
 */
test('staff:lock ghi changes co gia tri truoc/sau cua status', function () {
    $user = User::factory()->pageManager()->create(['email' => 'lockme2@vitaminvui.test']);

    Artisan::call('staff:lock', ['email' => 'lockme2@vitaminvui.test']);

    $log = AuditLog::query()->where('action', 'user.lock')->where('subject_id', $user->id)->latest('id')->first();

    expect($log)->not->toBeNull();
    // Không so sánh cả mảng bằng toBe(): cột JSON của MySQL không đảm bảo giữ
    // đúng thứ tự khoá ban đầu khi đọc lại (đã xác minh thực tế — không phải
    // giả định), nên kiểm từng giá trị thay vì so khớp chính xác thứ tự.
    expect($log->changes['status']['from'] ?? null)->toBe('active');
    expect($log->changes['status']['to'] ?? null)->toBe('locked');
});

test('staff:unlock ghi changes co gia tri truoc/sau cua status', function () {
    $user = User::factory()->pageManager()->locked()->create(['email' => 'unlockme2@vitaminvui.test']);

    Artisan::call('staff:unlock', ['email' => 'unlockme2@vitaminvui.test']);

    $log = AuditLog::query()->where('action', 'user.unlock')->where('subject_id', $user->id)->latest('id')->first();

    expect($log)->not->toBeNull();
    // Xem ghi chú ở test staff:lock phía trên (thứ tự khoá JSON không đảm bảo).
    expect($log->changes['status']['from'] ?? null)->toBe('locked');
    expect($log->changes['status']['to'] ?? null)->toBe('active');
});

/**
 * L4 còn lại — audit `staff.create` phải nằm CÙNG transaction với việc tạo
 * user (không tách rời tầng ứng dụng), xác nhận gián tiếp bằng cách kiểm cả
 * user lẫn audit log cùng tồn tại/khớp sau khi lệnh chạy thành công.
 */
test('staff:create ghi audit staff.create dung subject va trong 1 lan goi', function () {
    Artisan::call('staff:create', [
        'email' => 'tx-check@vitaminvui.test',
        '--role' => 'giao_vien',
    ]);

    $user = User::query()->where('email', 'tx-check@vitaminvui.test')->firstOrFail();
    $log = AuditLog::query()->where('action', 'staff.create')->where('subject_id', $user->id)->first();

    expect($log)->not->toBeNull();
    expect($log->changes)->toBe(['role' => 'giao_vien']);
});
