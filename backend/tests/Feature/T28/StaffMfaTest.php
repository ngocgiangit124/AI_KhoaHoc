<?php

use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * T28 — POST /admin/auth/mfa/verify (api-contract §2.5).
 *
 * Phiên "chờ MFA" được mô phỏng bằng `withSession()` (đặt trực tiếp vào
 * store phiên của container test — KHÔNG giống 2 lệnh gọi HTTP rời rạc, dữ
 * liệu KHÔNG tự luân chuyển giữa các request thật, xem ghi chú ở
 * `tests/Feature/T03/LoginTest.php`): mô phỏng đúng trạng thái mà
 * `Admin\Auth\LoginController::store()` đã đặt SAU KHI mật khẩu đúng.
 */
function vvAdminPendingMfaSession(): array
{
    return [
        'staff_login_at' => now()->timestamp,
        'staff_last_activity' => now()->timestamp,
        'staff_mfa_passed' => false,
    ];
}

function vvPostAdminMfaVerify(User $user, string $code)
{
    return test()->actingAs($user)
        ->withSession(vvAdminPendingMfaSession())
        ->postJson(vvAdminUrl('/admin/auth/mfa/verify'), ['code' => $code], vvAdminHeaders());
}

test('nhap dung ma MFA thi xac thuc thanh cong, tra ve user', function () {
    $admin = User::factory()->admin()->create();
    OtpCode::factory()->for($admin)->create([
        'purpose' => OtpPurpose::StaffLoginMfa,
        'destination' => $admin->email,
        'code_hash' => Hash::make('654321'),
    ]);

    $response = vvPostAdminMfaVerify($admin, '654321');

    $response->assertOk();
    $response->assertJson(['id' => $admin->id]);
    expect(AuditLog::query()->where('action', 'staff.mfa_verified')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

test('nhap sai ma MFA tra 422 va ghi audit staff.mfa_failed', function () {
    $admin = User::factory()->admin()->create();
    OtpCode::factory()->for($admin)->create([
        'purpose' => OtpPurpose::StaffLoginMfa,
        'destination' => $admin->email,
        'code_hash' => Hash::make('654321'),
    ]);

    $response = vvPostAdminMfaVerify($admin, '000000');

    $response->assertStatus(422);
    expect(AuditLog::query()->where('action', 'staff.mfa_failed')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

test('da qua MFA (session staff_mfa_passed=true) thi goi duoc route staff khac', function () {
    $admin = User::factory()->admin()->create();

    // Mo phong TRANG THAI SAU KHI xac thuc MFA thanh cong (do
    // `Admin\Auth\MfaController::verify()` dat) — khong xau chuoi 2 request
    // that (session khong tu luan chuyen giua cac lenh goi HTTP roi rac trong
    // 1 ham test, xem ghi chu o dau file).
    $response = test()->actingAs($admin)
        ->withSession([
            'staff_login_at' => now()->timestamp,
            'staff_last_activity' => now()->timestamp,
            'staff_mfa_passed' => true,
        ])
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertOk();
    $response->assertJson(['id' => $admin->id, 'role' => 'admin']);
});

test('chua qua MFA thi goi route staff khac tra 403 MFA_REQUIRED', function () {
    $admin = User::factory()->admin()->create();

    $response = test()->actingAs($admin)
        ->withSession(vvAdminPendingMfaSession())
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'MFA_REQUIRED']);
});

test('giao vien khong bi yeu cau MFA du session chua danh dau mfa_passed', function () {
    $teacher = User::factory()->teacher()->create();

    $response = test()->actingAs($teacher)
        ->withSession([
            'staff_login_at' => now()->timestamp,
            'staff_last_activity' => now()->timestamp,
            'staff_mfa_passed' => true,
        ])
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertOk();
});
