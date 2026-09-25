<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('EnsureAccountActive tra 403 ACCOUNT_LOCKED cho tai khoan bi khoa', function () {
    Route::domain(config('app.api_host'))
        ->middleware(['auth:sanctum', 'account.active'])
        ->get('/__test/active-only', fn () => response()->json(['ok' => true]));

    $user = User::factory()->locked()->create();

    $response = $this->actingAs($user)->getJson('http://'.config('app.api_host').'/__test/active-only');

    $response->assertStatus(403);
    $response->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('EnsureAccountActive cho qua tai khoan active', function () {
    Route::domain(config('app.api_host'))
        ->middleware(['auth:sanctum', 'account.active'])
        ->get('/__test/active-only-2', fn () => response()->json(['ok' => true]));

    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('http://'.config('app.api_host').'/__test/active-only-2');

    $response->assertOk();
});

test('EnsureRole chan dung vai tro khong duoc phep', function () {
    Route::domain(config('app.admin_api_host'))
        ->middleware(['auth:sanctum', 'role:admin'])
        ->get('/__test/admin-only', fn () => response()->json(['ok' => true]));

    $teacher = User::factory()->teacher()->create();

    $response = $this->actingAs($teacher)
        ->getJson('http://'.config('app.admin_api_host').'/__test/admin-only', [
            'Origin' => config('app.admin_url'),
        ]);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('EnsureRole cho qua dung vai tro', function () {
    Route::domain(config('app.admin_api_host'))
        ->middleware(['auth:sanctum', 'role:admin,quan_ly_trang'])
        ->get('/__test/staff-only', fn () => response()->json(['ok' => true]));

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->getJson('http://'.config('app.admin_api_host').'/__test/staff-only', [
            'Origin' => config('app.admin_url'),
        ]);

    $response->assertOk();
});

test('EnsureRole tra 403 khi tham so role khong ton tai trong enum (khong crash 500)', function () {
    Route::domain(config('app.admin_api_host'))
        ->middleware(['auth:sanctum', 'role:khong_ton_tai'])
        ->get('/__test/bogus-role', fn () => response()->json(['ok' => true]));

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->getJson('http://'.config('app.admin_api_host').'/__test/bogus-role', [
            'Origin' => config('app.admin_url'),
        ]);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('EnsureRole tra 403 khi chua dang nhap (guest)', function () {
    Route::domain(config('app.admin_api_host'))
        ->middleware(['auth:sanctum', 'role:admin'])
        ->get('/__test/guest-role', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('http://'.config('app.admin_api_host').'/__test/guest-role', [
        'Origin' => config('app.admin_url'),
    ]);

    // auth:sanctum chan truoc (401) vi chua dang nhap.
    $response->assertStatus(401);
});

test('Gate manage-system chi cho admin', function () {
    $admin = User::factory()->admin()->create();
    $pageManager = User::factory()->pageManager()->create();

    expect($admin->can('manage-system'))->toBeTrue();
    expect($pageManager->can('manage-system'))->toBeFalse();
});

test('Gate access-admin-area cho staff va giao vien, khong cho hoc sinh', function () {
    $teacher = User::factory()->teacher()->create();
    $student = User::factory()->student()->create();

    expect($teacher->can('access-admin-area'))->toBeTrue();
    expect($student->can('access-admin-area'))->toBeFalse();
});

test('UserRole enum co dung 4 vai tro co dinh', function () {
    $values = array_map(fn (UserRole $r) => $r->value, UserRole::cases());

    expect($values)->toEqualCanonicalizing(['admin', 'quan_ly_trang', 'giao_vien', 'hoc_sinh']);
});
