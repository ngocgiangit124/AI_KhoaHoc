<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * T28 — PUT /admin/auth/password (api-contract §2.5).
 */
function vvAdminSessionLoggedIn(): array
{
    return [
        'staff_login_at' => now()->timestamp,
        'staff_last_activity' => now()->timestamp,
        'staff_mfa_passed' => true,
    ];
}

function vvPutAdminPassword(User $user, array $payload)
{
    return test()->actingAs($user)
        ->withSession(vvAdminSessionLoggedIn())
        ->putJson(vvAdminUrl('/admin/auth/password'), $payload, vvAdminHeaders());
}

test('doi mat khau dung, xoa co must_change_password, ghi audit', function () {
    $admin = User::factory()->admin()->create([
        'password' => Hash::make('matkhaucu123'),
        'must_change_password' => true,
    ]);

    $response = vvPutAdminPassword($admin, [
        'current_password' => 'matkhaucu123',
        'password' => 'matkhaumoi456',
        'password_confirmation' => 'matkhaumoi456',
    ]);

    $response->assertOk();

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse();
    expect(Hash::check('matkhaumoi456', $admin->password))->toBeTrue();
    expect($admin->password_changed_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', 'staff.password_changed')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

test('sai mat khau hien tai tra 422', function () {
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhaucu123')]);

    $response = vvPutAdminPassword($admin, [
        'current_password' => 'sai-mat-khau',
        'password' => 'matkhaumoi456',
        'password_confirmation' => 'matkhaumoi456',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['current_password']);
});

test('mat khau moi khong xac nhan khop tra 422', function () {
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhaucu123')]);

    $response = vvPutAdminPassword($admin, [
        'current_password' => 'matkhaucu123',
        'password' => 'matkhaumoi456',
        'password_confirmation' => 'khac',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['password']);
});

test('goi duoc PUT /admin/auth/password ke ca khi dang bi must_change_password chan', function () {
    // Chinh route nay KHONG mang middleware staff.password_fresh (xem
    // routes/admin.php) — nguoc lai la loi thoat duy nhat se tu khoa chinh no.
    $admin = User::factory()->admin()->create([
        'password' => Hash::make('matkhaucu123'),
        'must_change_password' => true,
    ]);

    vvPutAdminPassword($admin, [
        'current_password' => 'matkhaucu123',
        'password' => 'matkhaumoi456',
        'password_confirmation' => 'matkhaumoi456',
    ])->assertOk();
});

test('must_change_password=true chan cac route staff khac bang PASSWORD_CHANGE_REQUIRED', function () {
    $admin = User::factory()->admin()->create(['must_change_password' => true]);

    $blocked = test()->actingAs($admin)
        ->withSession(vvAdminSessionLoggedIn())
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $blocked->assertStatus(403);
    $blocked->assertJson(['code' => 'PASSWORD_CHANGE_REQUIRED']);
});

/**
 * R1 (review-T28.md, [BLOCKER]) — biet dung current_password nhung CHUA qua
 * MFA (staff_mfa_passed=false) khong duoc phep doi mat khau: neu khong, bat
 * ky ai lo/do duoc mat khau cua 1 tai khoan admin/QLT deu vo hieu hoa hoan
 * toan tac dung cua MFA bang cach doi luon mat khau ma khong can nhap ma OTP.
 */
test('admin chua qua MFA (staff_mfa_passed=false) goi PUT /admin/auth/password tra 403 MFA_REQUIRED, mat khau KHONG doi', function () {
    $admin = User::factory()->admin()->create(['password' => Hash::make('matkhaucu123')]);

    $response = test()->actingAs($admin)
        ->withSession([
            'staff_login_at' => now()->timestamp,
            'staff_last_activity' => now()->timestamp,
            'staff_mfa_passed' => false,
        ])
        ->putJson(vvAdminUrl('/admin/auth/password'), [
            'current_password' => 'matkhaucu123',
            'password' => 'matkhaumoi456',
            'password_confirmation' => 'matkhaumoi456',
        ], vvAdminHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'MFA_REQUIRED']);

    expect(Hash::check('matkhaucu123', $admin->fresh()->password))->toBeTrue();
    expect(AuditLog::query()->where('action', 'staff.password_changed')->where('subject_id', $admin->id)->exists())->toBeFalse();
});

/**
 * R1 — doi xung voi truong hop tren: Giao Vien KHONG can MFA
 * (`EnsureStaffMfaPassed` tu bo qua theo vai tro) nen van doi mat khau duoc
 * ngay sau dang nhap, du session chua tung danh dau staff_mfa_passed=true.
 */
test('giao vien khong can MFA nen van doi duoc mat khau ngay sau dang nhap', function () {
    $teacher = User::factory()->teacher()->create(['password' => Hash::make('matkhaucu123')]);

    $response = test()->actingAs($teacher)
        ->withSession([
            'staff_login_at' => now()->timestamp,
            'staff_last_activity' => now()->timestamp,
            'staff_mfa_passed' => false,
        ])
        ->putJson(vvAdminUrl('/admin/auth/password'), [
            'current_password' => 'matkhaucu123',
            'password' => 'matkhaumoi456',
            'password_confirmation' => 'matkhaumoi456',
        ], vvAdminHeaders());

    $response->assertOk();
    expect(Hash::check('matkhaumoi456', $teacher->fresh()->password))->toBeTrue();
});
