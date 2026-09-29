<?php

use App\Models\User;

/**
 * T28 — middleware `staff.idle` (ADR-004 §2.2, api-contract §1.7
 * STAFF_IDLE_TIMEOUT).
 */
test('khong hoat dong qua 120 phut tra 401 STAFF_IDLE_TIMEOUT', function () {
    $admin = User::factory()->admin()->create();

    $response = test()->actingAs($admin)
        ->withSession([
            'staff_login_at' => now()->subMinutes(30)->timestamp,
            'staff_last_activity' => now()->subMinutes(121)->timestamp,
            'staff_mfa_passed' => true,
        ])
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertStatus(401);
    $response->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);
});

test('tong thoi luong phien qua 12 gio tra 401 STAFF_IDLE_TIMEOUT du van hoat dong lien tuc', function () {
    $admin = User::factory()->admin()->create();

    $response = test()->actingAs($admin)
        ->withSession([
            'staff_login_at' => now()->subHours(13)->timestamp,
            'staff_last_activity' => now()->subMinute()->timestamp,
            'staff_mfa_passed' => true,
        ])
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertStatus(401);
    $response->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);
});

test('con trong han hoat dong va tong thoi luong thi request thanh cong', function () {
    $admin = User::factory()->admin()->create();

    $response = test()->actingAs($admin)
        ->withSession([
            'staff_login_at' => now()->subHours(2)->timestamp,
            'staff_last_activity' => now()->subMinutes(5)->timestamp,
            'staff_mfa_passed' => true,
        ])
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertOk();
});

test('thieu du lieu phien staff (chua tung dang nhap qua luong nay) tra 401 STAFF_IDLE_TIMEOUT', function () {
    $admin = User::factory()->admin()->create();

    // `actingAs()` (Tests\TestCase) tu dat san 1 phien "hop le" — flushSession()
    // xoa het de mo phong dung tinh huong THIEU staff_login_at/staff_last_activity.
    $response = test()->actingAs($admin)
        ->flushSession()
        ->withSession(['staff_mfa_passed' => true])
        ->getJson(vvAdminUrl('/admin/auth/me'), vvAdminHeaders());

    $response->assertStatus(401);
    $response->assertJson(['code' => 'STAFF_IDLE_TIMEOUT']);
});
