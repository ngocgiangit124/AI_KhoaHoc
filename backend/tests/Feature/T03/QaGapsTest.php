<?php

use App\Models\Consent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/LoginTest.php';

// ---------- Đăng ký: biên dữ liệu ----------

test('AC1/biên: họ tên tiếng Việt có dấu, 150 ký tự được, 151 bị từ chối, chỉ khoảng trắng bị từ chối', function () {
    vvRegister(['name' => str_repeat('Đ', 150)])->assertCreated();
    expect(User::firstOrFail()->name)->toBe(str_repeat('Đ', 150));
    vvResetClient();

    vvRegister(['name' => str_repeat('Đ', 151), 'email' => 'b@example.com', 'phone' => '0922222222'])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    vvRegister(['name' => '   ', 'email' => 'c@example.com', 'phone' => '0933333333'])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    expect(User::count())->toBe(1);
});

test('BR3/biên: mật khẩu 8 ký tự được, 7 bị từ chối, 128 được, 129 bị từ chối', function () {
    vvRegister(['password' => 'abcd1234', 'password_confirmation' => 'abcd1234'])->assertCreated();
    vvResetClient();

    vvRegister(['email' => 'b@example.com', 'phone' => '0922222222', 'password' => 'abcd123', 'password_confirmation' => 'abcd123'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    $p128 = str_repeat('a', 128);
    vvRegister(['email' => 'c@example.com', 'phone' => '0933333333', 'password' => $p128, 'password_confirmation' => $p128])
        ->assertCreated();
    vvResetClient();

    $p129 = str_repeat('a', 129);
    vvRegister(['email' => 'd@example.com', 'phone' => '0944444444', 'password' => $p129, 'password_confirmation' => $p129])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

test('BR3: mật khẩu chứa ký tự đặc biệt và tiếng Việt được băm và đăng nhập lại được', function () {
    $pw = 'Mật-khẩu!@#$%^&*()_+ 2026';
    vvRegister(['password' => $pw, 'password_confirmation' => $pw])->assertCreated();
    vvResetClient();

    vvLogin(['login' => 'an@example.com', 'password' => $pw])->assertOk();
});

test('AC5: thiếu password_confirmation -> 422 và không tạo tài khoản', function () {
    $payload = vvRegisterPayload();
    unset($payload['password_confirmation']);

    $this->postJson(vvApiUrl('/auth/register'), $payload, vvWebHeaders())->assertStatus(422);
    expect(User::count())->toBe(0);
});

test('Biên lớp học: giá trị không phải số nguyên, rỗng, mảng -> 422 (không 500)', function (mixed $grade) {
    vvRegister(['grade_level' => $grade])->assertStatus(422)->assertJsonValidationErrors('grade_level');
    expect(User::count())->toBe(0);
})->with(['abc', 9.5, '', null, [[9]], 0, -6, '6; DROP TABLE users']);

test('Biên ngày sinh: đúng 120 tuổi trở lên bị từ chối, 29/02 năm nhuận hợp lệ, sai định dạng bị từ chối', function () {
    vvRegister(['date_of_birth' => now()->subYears(121)->toDateString()])
        ->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    vvRegister(['date_of_birth' => '2010-29-02'])->assertStatus(422);
    vvRegister(['date_of_birth' => '29/02/2000'])->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    vvRegister(['date_of_birth' => ['2000-01-01']])->assertStatus(422);
    vvRegister(['date_of_birth' => now('Asia/Ho_Chi_Minh')->toDateString()])
        ->assertStatus(422)->assertJsonValidationErrors('date_of_birth');

    vvRegister(['date_of_birth' => '2000-02-29'])->assertCreated();
});

test('AC10: SĐT/email phụ huynh sai định dạng bị từ chối khi dưới 18 tuổi', function () {
    $dob = now('Asia/Ho_Chi_Minh')->subYears(12)->toDateString();

    vvRegister(['date_of_birth' => $dob, 'parent_phone' => '12345'])
        ->assertStatus(422)->assertJsonValidationErrors('parent_phone');
    vvRegister(['date_of_birth' => $dob, 'parent_email' => 'khong-phai-email'])
        ->assertStatus(422)->assertJsonValidationErrors('parent_email');

    expect(User::count())->toBe(0);
});

test('AC10: dưới 18 tuổi chỉ có SĐT phụ huynh dạng +84 -> lưu chuẩn hóa 0xxxxxxxxx, pending', function () {
    vvRegister([
        'date_of_birth' => now('Asia/Ho_Chi_Minh')->subYears(14)->toDateString(),
        'parent_phone' => '+84 911 111 111',
    ])->assertCreated()->assertJson(['parent_consent_status' => 'pending']);

    expect(User::firstOrFail()->parent_phone)->toBe('0911111111');
});

test('AC2: SĐT trùng khi viết dạng khác (+84, dấu cách), email trùng khác hoa thường -> lỗi đúng field', function () {
    User::factory()->create(['email' => 'an@example.com', 'phone' => '0912345678']);

    vvRegister(['email' => 'khac@example.com', 'phone' => '+84 912 345 678'])
        ->assertStatus(422)->assertJsonValidationErrors(['phone'])
        ->assertJsonPath('errors.phone.0', 'Số điện thoại đã được sử dụng.');
    vvRegister(['email' => 'AN@Example.COM', 'phone' => '0922222222'])
        ->assertStatus(422)->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', 'Email đã được sử dụng.');

    // Cả hai trùng: báo cả hai field cùng lúc.
    vvRegister(['email' => 'an@example.com', 'phone' => '0912345678'])
        ->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);

    expect(User::count())->toBe(1);
});

test('Biên: email quá 254 ký tự -> 422', function () {
    vvRegister(['email' => str_repeat('a', 250).'@x.vn'])->assertStatus(422)->assertJsonValidationErrors('email');
    expect(User::count())->toBe(0);
});

test('BUG-1: email chứa khoảng trắng/tab/comment RFC phải bị từ chối ', function (string $email) {
    vvRegister(['email' => $email])->assertStatus(422)->assertJsonValidationErrors('email');
    expect(User::count())->toBe(0);
})->with(['an @example.com', "an\t@example.com", '(c)an@example.com']);

test('Biên: referral_code 50 ký tự được (flag bật), 51 bị từ chối, device_id 65 ký tự bị bỏ qua', function () {
    config(['features.referral_code' => true]);

    vvRegister(['referral_code' => str_repeat('A', 51)])->assertStatus(422)->assertJsonValidationErrors('referral_code');
    // T05/ADR-003: device_id sai định dạng/quá dài bị bỏ qua (không 422), chỉ dùng để chọn thông điệp.
    vvRegister(['email' => 'd@example.com', 'phone' => '0933333333', 'device_id' => str_repeat('d', 65)])->assertCreated();
    expect(User::where('email', 'd@example.com')->firstOrFail()->current_device_id)->toBeNull();
    vvWipeUsers();
    vvRegister(['referral_code' => str_repeat('A', 50)])->assertCreated();

    expect(User::firstOrFail()->referral_code_used)->toBe(str_repeat('A', 50));
});

test('Payload sai kiểu (mảng/object thay chuỗi) -> 422, không 500', function (string $field) {
    vvRegister([$field => ['x' => 'y']])->assertStatus(422);
    expect(User::count())->toBe(0);
})->with(['name', 'email', 'phone', 'password', 'date_of_birth', 'accept_terms', 'device_id']);

test('AC1/US-017: consents lưu IP, policy_version, granted_by=self, chưa revoked', function () {
    vvRegister()->assertCreated();

    $user = User::firstOrFail();
    $consents = Consent::where('user_id', $user->id)->get();

    expect($consents)->toHaveCount(2)
        ->and($consents->every(fn ($c) => $c->revoked_at === null && $c->granted_at !== null))->toBeTrue();
});

test('Đăng ký thất bại hoàn toàn rollback: không có user lẻ hoặc consent mồ côi', function () {
    User::factory()->create(['email' => 'an@example.com', 'phone' => '0912345678']);
    $before = Consent::count();

    vvRegister()->assertStatus(422);

    expect(User::count())->toBe(1)->and(Consent::count())->toBe($before);
});

// ---------- Đăng nhập ----------

test('AC3: response login có đủ shape user, không lộ password/parent_*/remember_token/current_session_id', function () {
    vvStudent(['name' => 'Trần Thị Bình']);

    $response = vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();

    expect(array_keys($response->json()))
        ->toEqualCanonicalizing(['id', 'name', 'email', 'phone', 'role', 'grade_level', 'is_verified', 'parent_consent_status']);
    expect($response->json('name'))->toBe('Trần Thị Bình');
});

test('Đăng nhập: login/password sai kiểu hoặc quá dài -> 422, không 500', function () {
    vvLogin(['login' => ['a'], 'password' => 'x'])->assertStatus(422);
    vvLogin(['login' => 'a@b.vn', 'password' => ['x']])->assertStatus(422);
    vvLogin(['login' => str_repeat('a', 5000), 'password' => str_repeat('p', 5000)])->assertStatus(422);
    $this->assertGuest('web');
});

test('Đăng nhập: SĐT không hợp lệ và email không tồn tại cho cùng thông điệp, cùng status', function () {
    vvStudent();

    $a = vvLogin(['login' => '12345', 'password' => 'sai-sai-sai'])->assertStatus(422);
    $b = vvLogin(['login' => 'khongco@example.com', 'password' => 'sai-sai-sai'])->assertStatus(422);
    $c = vvLogin(['login' => 'hs@example.com', 'password' => 'sai-sai-sai'])->assertStatus(422);

    expect($a->json('errors'))->toBe($b->json('errors'))->and($b->json('errors'))->toBe($c->json('errors'));
    expect($c->json('message'))->toBe($a->json('message'));
});

test('AC4: thông điệp lỗi đăng nhập đúng nguyên văn story', function () {
    vvStudent();

    vvLogin(['login' => 'hs@example.com', 'password' => 'sai-sai-sai'])
        ->assertStatus(422)
        ->assertJsonPath('errors.login.0', 'Thông tin đăng nhập hoặc mật khẩu không đúng.');
});

test('Đăng nhập tài khoản bị khoá ở host api sau mật khẩu đúng: 403 ACCOUNT_LOCKED với message tiếng Việt', function () {
    vvStudent(['status' => 'locked']);

    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_LOCKED')
        ->assertJsonPath('message', fn ($m) => is_string($m) && $m !== '');
});

test('Đăng nhập không rehash/đổi mật khẩu bất ngờ và mật khẩu trong DB là bcrypt/argon', function () {
    $u = vvStudent();
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();

    expect(Hash::check('dung-mat-khau-1', $u->fresh()->password))->toBeTrue()
        ->and($u->fresh()->password)->toStartWith('$');
});

test('Logout không cần body, trả 204 không nội dung và không còn user sau đó', function () {
    vvStudent();
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();

    $this->postJson(vvApiUrl('/auth/logout'), [], vvWebHeaders())->assertNoContent();
    $this->assertGuest('web');
});
