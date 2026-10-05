<?php

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';

/*
 * Test bổ sung của QA (T27): biên dữ liệu, ẩn danh hoá, tiếng Việt có dấu, không tự đăng nhập sau reset,
 * mã reset không dùng được chéo purpose, đổi mật khẩu 2 tab, mã reset còn lại sau change.
 */

beforeEach(function () {
    Cache::flush();
});

function vvQaCode(User $user): string
{
    $sender = vvFakeOtp();
    (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => $user->email, 'captcha_token' => 'ok'])->assertStatus(202);
    Cache::flush();

    return $sender->lastCode();
}

test('AC8 tai khoan da an danh hoa: forgot 202 chung, khong gui; reset khong hoan tat', function () {
    $user = vvPwStudent(['anonymized_at' => now()]);
    $sender = vvFakeOtp();

    vvForgot()->assertStatus(202);
    expect($sender->sent)->toBe([]);

    // Có mã hợp lệ được cấy sẵn (mô phỏng ẩn danh hoá SAU khi gửi) vẫn không đổi được mật khẩu.
    OtpCode::query()->create([
        'user_id' => $user->id, 'purpose' => 'reset_password', 'channel' => 'email', 'destination' => $user->email,
        'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
    ]);
    vvReset('123456')->assertStatus(422)->assertJsonValidationErrors('code');
    expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
});

test('mat khau tieng Viet co dau + khoang trang: reset roi dang nhap bang dung chuoi do', function () {
    $user = vvPwStudent();
    $code = vvQaCode($user);
    $pw = 'Mật khẩu mới Việt Nam 2026';

    vvReset($code, ['password' => $pw, 'password_confirmation' => $pw])->assertOk();

    (new VvPwBrowser)->login($pw)->assertOk();
    (new VvPwBrowser)->login('Mat khau moi Viet Nam 2026')->assertStatus(422);
});

test('bien do dai mat khau: 128 ky tu OK, 129 ky tu 422 (reset va change)', function () {
    $user = vvPwStudent();
    $code = vvQaCode($user);

    $long = str_repeat('a', 129);
    vvReset($code, ['password' => $long, 'password_confirmation' => $long])->assertStatus(422)->assertJsonValidationErrors('password');

    $ok = str_repeat('b', 128);
    vvReset($code, ['password' => $ok, 'password_confirmation' => $ok])->assertOk();
    (new VvPwBrowser)->login($ok)->assertOk();

    $a = new VvPwBrowser;
    $a->login($ok)->assertOk();
    $a->changePassword($ok, $long)->assertStatus(422)->assertJsonValidationErrors('password');
    $a->changePassword(str_repeat('c', 129), 'mat-khau-moi-2')->assertStatus(422)->assertJsonValidationErrors('current_password');
});

test('forgot: login rong / qua dai / kieu mang -> 422, khong 500', function () {
    vvForgot(['login' => ''])->assertStatus(422)->assertJsonValidationErrors('login');
    vvForgot(['login' => str_repeat('a', 255)])->assertStatus(422)->assertJsonValidationErrors('login');
    vvForgot(['login' => ['x']])->assertStatus(422);
});

test('reset: ma co khoang trang duoc chuan hoa; ma khong phai chuoi (mang) -> 422', function () {
    $user = vvPwStudent();
    $code = vvQaCode($user);

    test()->postJson(vvApiUrl('/auth/password/reset'), [
        'login' => 'hs@example.com', 'code' => [$code], 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'mat-khau-moi-2',
    ], vvWebHeaders())->assertStatus(422)->assertJsonValidationErrors('code');
    vvReset(substr($code, 0, 3).' '.substr($code, 3))->assertOk();
});

test('reset thanh cong KHONG tu dang nhap: /auth/me van 401', function () {
    $user = vvPwStudent();
    $code = vvQaCode($user);
    $b = new VvPwBrowser;

    $b->call('POST', '/auth/password/reset', [
        'login' => 'hs@example.com', 'code' => $code, 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'mat-khau-moi-2',
    ])->assertOk();

    $b->me()->assertStatus(401);
    expect($user->fresh()->current_session_id)->toBe('logged_out');
});

test('ma OTP verify_account khong dung duoc de reset mat khau (khac purpose)', function () {
    $user = vvPwStudent();
    OtpCode::query()->create([
        'user_id' => $user->id, 'purpose' => 'verify_account', 'channel' => 'email', 'destination' => $user->email,
        'code_hash' => Hash::make('654321'), 'expires_at' => now()->addMinutes(10),
    ]);

    vvReset('654321')->assertStatus(422)->assertJsonValidationErrors('code');
    expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
});

test('reset: mat khau moi trung mat khau cu van duoc chap nhan (cau hoi mo cua story, ghi nhan hanh vi)', function () {
    $user = vvPwStudent();
    $code = vvQaCode($user);

    vvReset($code, ['password' => 'mat-khau-cu-1', 'password_confirmation' => 'mat-khau-cu-1'])->assertOk();
    (new VvPwBrowser)->login('mat-khau-cu-1')->assertOk();
});

test('doi mat khau 2 lan lien tiep tu cung 1 trinh duyet: lan 2 dung cookie moi, van thanh cong', function () {
    vvPwStudent();
    $a = new VvPwBrowser;
    $a->login()->assertOk();

    $a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2')->assertOk()->assertJsonPath('session_kept', true);
    $a->changePassword('mat-khau-moi-2', 'mat-khau-moi-3')->assertOk()->assertJsonPath('session_kept', true);
    $a->me()->assertOk();

    (new VvPwBrowser)->login('mat-khau-moi-3')->assertOk();
});

test('R6 ghi nhan: sau change, ma reset_password con hieu luc cua chinh tai khoan VAN dung duoc (khong bi vo hieu)', function () {
    $user = vvPwStudent();
    $code = vvQaCode($user);

    $a = new VvPwBrowser;
    $a->login()->assertOk();
    $a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2')->assertOk();

    $stillActive = OtpCode::where('user_id', $user->id)->where('purpose', 'reset_password')
        ->whereNull('consumed_at')->whereNull('invalidated_at')->count();

    // Hành vi hiện tại (nit R6 của reviewer): mã còn sống. Nếu Dev sửa để vô hiệu hoá thì đổi expectation này về 0.
    expect($stillActive)->toBe(1);
    vvReset($code, ['password' => 'mat-khau-moi-4', 'password_confirmation' => 'mat-khau-moi-4'])->assertOk();
});

test('change: audit khong chua mat khau, response/cookie khong lo mat khau', function () {
    vvPwStudent();
    $a = new VvPwBrowser;
    $a->login()->assertOk();
    $res = $a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2');

    expect($res->getContent())->not->toContain('mat-khau')
        ->and(json_encode(AuditLog::all()->toArray()))->not->toContain('mat-khau');
});

test('AC6 han muc theo IP: 30 forgot/gio tu 1 IP (moi lan 1 tai khoan khac) -> yeu cau thu 31 bi 429 + Retry-After', function () {
    foreach (range(1, 30) as $i) {
        vvForgot(['login' => "khong-co-{$i}@example.com"])->assertStatus(202);
    }

    $res = vvForgot(['login' => 'khong-co-31@example.com'])->assertStatus(429);
    expect((int) $res->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('R2 captcha sai nhieu lan khong chan nan nhan: 7 lan sai captcha roi 1 lan dung van 202 va gui ma', function () {
    $user = vvPwStudent();
    $sender = vvFakeOtp();

    foreach (range(1, 7) as $_) {
        vvForgot(['captcha_token' => ''])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
    }

    vvForgot()->assertStatus(202);
    // OTP gửi bằng defer(): trong test chạy ngay sau response.
    expect($sender->sent)->toHaveCount(1)->and($sender->sent[0]['user_id'])->toBe($user->id);
});
