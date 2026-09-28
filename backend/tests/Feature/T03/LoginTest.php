<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function vvPostLogin(array $payload)
{
    return test()->postJson('http://'.config('app.api_host').'/api/v1/auth/login', $payload, [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('AC3: dang nhap thanh cong bang email', function () {
    $user = User::factory()->create(['email' => 'em@example.com', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => 'em@example.com', 'password' => 'matkhau123']);

    $response->assertOk();
    $response->assertJson(['id' => $user->id, 'email' => 'em@example.com']);
});

test('AC3: dang nhap thanh cong bang so dien thoai (chap nhan dinh dang +84)', function () {
    $user = User::factory()->create(['phone' => '0912345678', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => '+84912345678', 'password' => 'matkhau123']);

    $response->assertOk();
    $response->assertJson(['id' => $user->id]);
});

test('AC4: sai mat khau tra 422 thong diep chung', function () {
    User::factory()->create(['email' => 'em2@example.com', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => 'em2@example.com', 'password' => 'sai-mat-khau']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['login']);
});

test('AC4: tai khoan khong ton tai tra 422 thong diep chung giong het truong hop sai mat khau', function () {
    $response = vvPostLogin(['login' => 'khong-ton-tai@example.com', 'password' => 'bat-ky']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['login']);
});

test('khoa tai khoan + sai mat khau van tra 422 chung, KHONG lo bi khoa (S20)', function () {
    User::factory()->locked()->create(['email' => 'bikhoa@example.com', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => 'bikhoa@example.com', 'password' => 'sai-mat-khau']);

    $response->assertStatus(422);
    $response->assertJson(['code' => 'VALIDATION_ERROR']);
});

test('khoa tai khoan + dung mat khau tra 403 ACCOUNT_LOCKED (S20)', function () {
    User::factory()->locked()->create(['email' => 'bikhoa2@example.com', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => 'bikhoa2@example.com', 'password' => 'matkhau123']);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'ACCOUNT_LOCKED']);
});

test('staff dang nhap host api tra 403 WRONG_PORTAL sau khi mat khau dung', function () {
    User::factory()->teacher()->create(['email' => 'gv@example.com', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => 'gv@example.com', 'password' => 'matkhau123']);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'WRONG_PORTAL']);
});

test('dang xuat tra 204 va huy phien', function () {
    $user = User::factory()->create();

    $logout = test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/logout', [], [
        'Origin' => config('app.frontend_url'),
    ]);

    $logout->assertStatus(204);

    // Kiểm trực tiếp trên guard thay vì gọi tiếp 1 request mô phỏng: Sanctum
    // dựng lại ngữ cảnh xác thực stateful theo session của TỪNG request (dữ
    // liệu session không thực sự luân chuyển giữa các lệnh gọi HTTP rời rạc
    // trong 1 hàm test Pest — khác `actingAs()`, vốn set thẳng lên guard mà
    // không qua session), nên `guard('web')->check()` là cách đáng tin để
    // khẳng định `logout()` đã huỷ trạng thái đăng nhập ngay lập tức.
    expect(auth('web')->check())->toBeFalse();
});

test('da dang nhap ma goi lai /auth/login (guest) bi tu choi', function () {
    $user = User::factory()->create();

    $response = test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/login', [
        'login' => $user->email,
        'password' => 'password',
    ], [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(403);
});
