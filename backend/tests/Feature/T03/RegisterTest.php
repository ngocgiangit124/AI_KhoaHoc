<?php

use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Models\Consent;
use App\Models\User;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use Illuminate\Support\Facades\Auth;

function vvRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nguyễn Văn A',
        'date_of_birth' => '2000-01-01', // trưởng thành (>= 18 tuổi)
        'email' => 'hocsinh'.uniqid().'@example.com',
        'phone' => '09'.random_int(10000000, 99999999),
        'grade_level' => 10,
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'accept_privacy' => true,
        'captcha_token' => 'test-ok-token',
        'device_id' => 'device-'.uniqid(),
    ], $overrides);
}

function vvPostRegister(array $payload)
{
    return test()->postJson('http://'.config('app.api_host').'/api/v1/auth/register', $payload, [
        'Origin' => config('app.frontend_url'),
    ]);
}

/**
 * Đăng ký tự động đăng nhập (`Auth::login()`), và guard `web` được cache theo
 * container trong SUỐT 1 hàm test (không tái tạo giữa các lệnh gọi HTTP mô
 * phỏng nối tiếp trong Pest — khác hành vi trình duyệt thật). Test nào gọi
 * `/auth/register` nhiều lần liên tiếp phải tự đăng xuất giữa các lần để
 * middleware `guest` không chặn lần gọi sau (403).
 */
function vvLogoutGuard(): void
{
    Auth::guard('web')->logout();
}

test('AC1: dang ky thanh cong tao tai khoan hoc_sinh, tu dong dang nhap, ghi 2 ban ghi dong y', function () {
    $payload = vvRegisterPayload();

    $response = vvPostRegister($payload);

    $response->assertStatus(201);
    $response->assertJson([
        'name' => 'Nguyễn Văn A',
        'email' => mb_strtolower($payload['email']),
        'role' => 'hoc_sinh',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    $user = User::query()->where('email', mb_strtolower($payload['email']))->firstOrFail();

    expect($user->role)->toBe(UserRole::Student);
    expect($user->email_verified_at)->toBeNull();
    expect($user->parent_consent_status)->toBe(ParentConsentStatus::NotRequired);

    expect(Consent::query()->where('user_id', $user->id)->count())->toBe(2);
    expect(Consent::query()->where('user_id', $user->id)->where('type', 'terms')->exists())->toBeTrue();
    expect(Consent::query()->where('user_id', $user->id)->where('type', 'privacy_policy')->exists())->toBeTrue();

    // Tự động đăng nhập ngay sau khi đăng ký — kiểm bằng guard trực tiếp
    // (đáng tin hơn gọi tiếp 1 request mô phỏng: trong CÙNG 1 hàm test, guard
    // `web` là singleton còn giữ `$user` gốc từ RegistrationService, mà
    // `wasRecentlyCreated=true` của model đó khiến JsonResource tự set 201 —
    // hành vi CHỈ xảy ra trong khung test, không phản ánh HTTP thật. Dùng
    // `actingAs` với bản ghi vừa truy vấn lại (wasRecentlyCreated=false) để
    // /auth/me trả đúng 200 như một request thật.
    expect(auth('web')->check())->toBeTrue();
    expect(auth('web')->id())->toBe($user->id);

    $me = test()->actingAs($user)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);
    $me->assertOk();
    $me->assertJson(['id' => $user->id]);
});

test('AC2: email da ton tai tra loi 422 dung field, khong tao tai khoan moi', function () {
    $existing = User::factory()->create(['email' => 'trung@example.com']);

    $response = vvPostRegister(vvRegisterPayload(['email' => 'trung@example.com']));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
    expect(User::query()->where('email', 'trung@example.com')->count())->toBe(1);
});

test('AC2: so dien thoai da ton tai tra loi 422 dung field', function () {
    User::factory()->create(['phone' => '0911222333']);

    $response = vvPostRegister(vvRegisterPayload(['phone' => '0911222333']));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['phone']);
});

test('AC5: mat khau xac nhan khong khop tra 422 tai field password', function () {
    $response = vvPostRegister(vvRegisterPayload(['password_confirmation' => 'khac-nhau']));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['password']);
});

test('AC7: ma gioi thieu duoc luu kem tai khoan', function () {
    $payload = vvRegisterPayload(['referral_code' => 'BAN-BE-2026']);

    vvPostRegister($payload)->assertStatus(201);

    $user = User::query()->where('email', mb_strtolower($payload['email']))->firstOrFail();
    expect($user->referral_code_used)->toBe('BAN-BE-2026');
});

test('bien gioi lop hoc: 5 loi, 6 hop le, 12 hop le, 13 loi', function () {
    vvPostRegister(vvRegisterPayload(['grade_level' => 5]))->assertJsonValidationErrors(['grade_level']);
    vvPostRegister(vvRegisterPayload(['grade_level' => 6]))->assertStatus(201);
    vvLogoutGuard();
    vvPostRegister(vvRegisterPayload(['grade_level' => 12]))->assertStatus(201);
    vvLogoutGuard();
    vvPostRegister(vvRegisterPayload(['grade_level' => 13]))->assertJsonValidationErrors(['grade_level']);
});

test('role=admin trong body bi bo qua (S17) — tai khoan van tao voi role hoc_sinh', function () {
    $payload = vvRegisterPayload([
        'role' => 'admin',
        'status' => 'active',
        'email_verified_at' => now()->toIso8601String(),
    ]);

    $response = vvPostRegister($payload);

    $response->assertStatus(201);
    $response->assertJson(['role' => 'hoc_sinh']);

    $user = User::query()->where('email', mb_strtolower($payload['email']))->firstOrFail();
    expect($user->role)->toBe(UserRole::Student);
    expect($user->email_verified_at)->toBeNull();
});

test('thieu dong y dieu khoan hoac chinh sach rieng tu tra 422 (S7)', function () {
    $response = vvPostRegister(vvRegisterPayload(['accept_terms' => false]));
    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['accept_terms']);

    $response2 = vvPostRegister(vvRegisterPayload(['accept_privacy' => false]));
    $response2->assertStatus(422);
    $response2->assertJsonValidationErrors(['accept_privacy']);
});

test('captcha sai tra 422 CAPTCHA_FAILED va khong tao tai khoan', function () {
    $payload = vvRegisterPayload(['captcha_token' => FakeCaptchaVerifier::INVALID_TOKEN]);

    $response = vvPostRegister($payload);

    $response->assertStatus(422);
    $response->assertJson(['code' => 'CAPTCHA_FAILED']);
    expect(User::query()->where('email', mb_strtolower($payload['email']))->exists())->toBeFalse();
});

/**
 * M4 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY `RegisterRequest`
 * kiểm `unique`/`exists` TRƯỚC khi Service kiểm captcha: 1 request captcha
 * SAI + email/SĐT đã tồn tại vẫn nhận `errors.email`/`errors.phone` ngay —
 * dò được tài khoản có tồn tại hay không mà không cần giải captcha (oracle
 * độc lập với M1). Giờ captcha luôn được kiểm TRƯỚC — captcha sai phải trả
 * CHỈ `CAPTCHA_FAILED`, không kèm bất kỳ `errors.email`/`errors.phone` nào,
 * dù email/SĐT gửi lên THẬT SỰ đã tồn tại.
 */
test('captcha sai + email/SDT da ton tai chi tra CAPTCHA_FAILED, khong kem errors.email/phone (M4)', function () {
    $existing = User::factory()->create(['email' => 'da-ton-tai-m4@example.com', 'phone' => '0977000111']);

    $payload = vvRegisterPayload([
        'email' => 'da-ton-tai-m4@example.com',
        'phone' => '0977000111',
        'captcha_token' => FakeCaptchaVerifier::INVALID_TOKEN,
    ]);

    $response = vvPostRegister($payload);

    $response->assertStatus(422);
    $response->assertJson(['code' => 'CAPTCHA_FAILED']);
    expect($response->json('errors.email'))->toBeNull();
    expect($response->json('errors.phone'))->toBeNull();
    // Không tạo thêm tài khoản nào (chỉ còn đúng bản ghi gốc).
    expect(User::query()->where('email', 'da-ton-tai-m4@example.com')->count())->toBe(1);
    expect($existing->fresh())->not->toBeNull();
});

/**
 * M4 — vẫn giữ AC2 khi captcha ĐÚNG: email/SĐT trùng phải báo đúng field như
 * trước (chỉ đổi NƠI kiểm — từ FormRequest sang Service, sau captcha).
 */
test('captcha dung + email/SDT da ton tai van bao dung field nhu AC2 (M4)', function () {
    User::factory()->create(['email' => 'da-ton-tai-m4b@example.com', 'phone' => '0977000222']);

    $payload = vvRegisterPayload([
        'email' => 'da-ton-tai-m4b@example.com',
        'phone' => '0977000222',
        'captcha_token' => 'test-ok-token',
    ]);

    $response = vvPostRegister($payload);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email', 'phone']);
});

test('AC10: duoi nguong tuoi thieu lien he phu huynh tra 422', function () {
    $payload = vvRegisterPayload([
        'date_of_birth' => now()->subYears(15)->toDateString(),
        'parent_phone' => null,
        'parent_email' => null,
    ]);

    $response = vvPostRegister($payload);

    $response->assertStatus(422);
    expect(User::query()->where('email', mb_strtolower($payload['email']))->exists())->toBeFalse();
});

test('duoi nguong tuoi co SDT phu huynh thi dang ky thanh cong, parent_consent_status = pending', function () {
    $payload = vvRegisterPayload([
        'date_of_birth' => now()->subYears(15)->toDateString(),
        'parent_phone' => '0987654321',
        'parent_email' => null,
    ]);

    vvPostRegister($payload)->assertStatus(201);

    $user = User::query()->where('email', mb_strtolower($payload['email']))->firstOrFail();
    expect($user->parent_consent_status)->toBe(ParentConsentStatus::Pending);
    expect($user->parent_phone)->toBe('0987654321');
});

test('bien gioi tuoi: 17 tuoi 364 ngay can lien he phu huynh, du 18 tuoi thi khong can', function () {
    $almostAdult = now()->subYears(18)->addDay()->toDateString();
    $exactlyAdult = now()->subYears(18)->toDateString();

    vvPostRegister(vvRegisterPayload([
        'date_of_birth' => $almostAdult,
        'parent_phone' => null,
        'parent_email' => null,
    ]))->assertStatus(422);

    vvPostRegister(vvRegisterPayload([
        'date_of_birth' => $exactlyAdult,
        'parent_phone' => null,
        'parent_email' => null,
    ]))->assertStatus(201);
});

/**
 * L1 (review docs/security/review-T03-FW1.md) — thiếu Origin/Referer hợp lệ
 * (không "stateful" theo Sanctum) thì không có session. TRƯỚC ĐÂY Service vẫn
 * chạy hết — tạo user + 2 bản ghi consents — rồi mới vỡ 500 ở
 * `session()->regenerate()`. Middleware `stateful` (đặt trước `guest`) phải
 * chặn NGAY, không chạm Service/DB.
 */
test('dang ky khong co Origin hop le tra 400 ORIGIN_NOT_ALLOWED, khong tao user (L1)', function () {
    $payload = vvRegisterPayload();

    $response = test()->postJson('http://'.config('app.api_host').'/api/v1/auth/register', $payload);

    $response->assertStatus(400);
    $response->assertJson(['code' => 'ORIGIN_NOT_ALLOWED']);
    expect(User::query()->where('email', mb_strtolower($payload['email']))->exists())->toBeFalse();
});

test('email co ky tu khong phai ASCII tra 422 voi thong diep tieng Viet (M2)', function () {
    $response = vvPostRegister(vvRegisterPayload(['email' => 'ｖictim@example.com']));

    $response->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Email chỉ được chứa ký tự không dấu.');
});
