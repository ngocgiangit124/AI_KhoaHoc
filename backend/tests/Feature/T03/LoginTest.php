<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function vvPostLogin(array $payload)
{
    return test()->postJson('http://'.config('app.api_host').'/api/v1/auth/login', $payload, [
        'Origin' => config('app.frontend_url'),
    ]);
}

/**
 * M1 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY `$user === null`
 * làm đoản mạch `||`, `Hash::check()` KHÔNG bao giờ chạy khi không tìm thấy
 * tài khoản — đo thực tế: ~4 ms (không tồn tại) so với ~220 ms (tồn tại, sai
 * mật khẩu), lộ tài khoản có tồn tại hay không qua thời gian phản hồi (S20,
 * BR5). `Hash::check()` giờ PHẢI luôn chạy (với dummy hash cùng cost cấu
 * hình) — kiểm bằng cách đếm số lần gọi, không đo thời gian (dễ chập chờn).
 */
test('M1: khong tim thay tai khoan van goi Hash::check dung 1 lan (khong do thoi gian de tranh chap chon)', function () {
    // Hash::shouldReceive('check') thay THẲNG instance đã resolve của facade
    // bằng 1 Mockery mock (không phải partial) — bất kỳ method nào khác
    // (`make()`, dùng trong `dummyHash()`) cũng phải được khai rõ, nếu không
    // Mockery ném "method does not exist". Lấy hasher THẬT trước khi mock để
    // uỷ quyền lại — vẫn kiểm được hành vi thật (đúng/sai mật khẩu), chỉ thêm
    // phép đếm số lần gọi `check()`.
    $realHasher = app('hash');

    Hash::shouldReceive('check')->once()->andReturnUsing(
        fn ($value, $hashedValue) => $realHasher->check($value, $hashedValue)
    );
    Hash::shouldReceive('make')->andReturnUsing(
        fn ($value, $options = []) => $realHasher->make($value, $options)
    );

    $response = vvPostLogin(['login' => 'khong-ton-tai-hash-check@example.com', 'password' => 'bat-ky']);

    $response->assertStatus(422);
});

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

/**
 * R1 (docs/qa/review-T03-FW1.md) — frontend hiển thị `message` TOP-LEVEL làm
 * banner (không phải `errors.login`). Trước khi sửa, `ApiExceptionRenderer`
 * hard-code "Dữ liệu gửi lên không hợp lệ." cho MỌI `ValidationException`,
 * khiến người dùng thấy thông điệp vô nghĩa thay vì lý do thật (dù đúng
 * BR5/S20 — không tiết lộ tài khoản có tồn tại hay không).
 */
test('R1: 422 sai mat khau co message top-level la thong diep chung, khong phai thong diep validate mac dinh', function () {
    User::factory()->create(['email' => 'em3@example.com', 'password' => Hash::make('matkhau123')]);

    $response = vvPostLogin(['login' => 'em3@example.com', 'password' => 'sai-mat-khau']);

    $response->assertStatus(422);
    $response->assertJson([
        'message' => 'Thông tin đăng nhập hoặc mật khẩu không đúng.',
        'code' => 'VALIDATION_ERROR',
    ]);
    expect($response->json('message'))->not->toBe('Dữ liệu gửi lên không hợp lệ.');
});

test('AC4: tai khoan khong ton tai tra 422 thong diep chung giong het truong hop sai mat khau', function () {
    $response = vvPostLogin(['login' => 'khong-ton-tai@example.com', 'password' => 'bat-ky']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['login']);
    $response->assertJson(['message' => 'Thông tin đăng nhập hoặc mật khẩu không đúng.']);
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

/**
 * L1 (review docs/security/review-T03-FW1.md) — thiếu Origin/Referer hợp lệ
 * thì không có session; middleware `stateful` (đặt trước `guest`/`throttle`)
 * phải chặn 400 ngay, không chạy `LoginService`/chạm DB.
 */
test('dang nhap khong co Origin hop le tra 400 ORIGIN_NOT_ALLOWED (L1)', function () {
    User::factory()->create(['email' => 'no-origin@example.com', 'password' => Hash::make('matkhau123')]);

    $response = test()->postJson('http://'.config('app.api_host').'/api/v1/auth/login', [
        'login' => 'no-origin@example.com',
        'password' => 'matkhau123',
    ]);

    $response->assertStatus(400);
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});

test('dang xuat khong co Origin hop le tra 400 ORIGIN_NOT_ALLOWED (L1)', function () {
    $user = User::factory()->create();

    $response = test()->actingAs($user)->postJson('http://'.config('app.api_host').'/api/v1/auth/logout', []);

    $response->assertStatus(400);
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
});
