<?php

use App\Enums\ConsentType;
use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

test('AC1 dang ky thanh cong: role hoc_sinh, chua xac thuc, tu dang nhap, co consents', function () {
    $response = vvRegister();

    $response->assertCreated()
        ->assertJson([
            'name' => 'Nguyễn Văn An',
            'email' => 'an@example.com',
            'phone' => '0912345678',
            'role' => 'hoc_sinh',
            'grade_level' => 9,
            'is_verified' => false,
            'parent_consent_status' => 'not_required',
        ])
        ->assertJsonMissingPath('password')
        ->assertJsonMissingPath('parent_email');

    $user = User::where('email', 'an@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::Student)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->phone_verified_at)->toBeNull()
        ->and($user->password)->not->toBe('matkhau-123');

    $consents = Consent::where('user_id', $user->id)->get();
    expect($consents->pluck('type')->map->value->sort()->values()->all())
        ->toBe([ConsentType::PrivacyPolicy->value, ConsentType::Terms->value])
        ->and($consents->every(fn ($c) => $c->granted_by === 'self' && $c->policy_version === config('privacy.policy_version')))->toBeTrue();

    // T05: login không còn `guest` (đăng nhập lại là hợp lệ, sai mật khẩu vẫn là 422 thông điệp chung).
    $this->postJson(vvApiUrl('/auth/login'), ['login' => 'an@example.com', 'password' => 'x'], vvWebHeaders())
        ->assertStatus(422);
});

test('S17 role/status/verified_at/parent_consent_status trong body bi bo qua', function () {
    vvRegister([
        'role' => 'admin',
        'status' => 'locked',
        'email_verified_at' => now()->toDateTimeString(),
        'phone_verified_at' => now()->toDateTimeString(),
        'parent_consent_status' => 'granted',
        'must_change_password' => true,
        'current_session_id' => 'x',
    ])->assertCreated();

    $user = User::firstOrFail();
    expect($user->role)->toBe(UserRole::Student)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->phone_verified_at)->toBeNull()
        ->and($user->parent_consent_status)->toBe(ParentConsentStatus::NotRequired)
        ->and($user->must_change_password)->toBeFalse();
});

test('thieu dong y dieu khoan hoac chinh sach -> 422 va khong tao tai khoan', function (string $field) {
    vvRegister([$field => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(User::count())->toBe(0)->and(Consent::count())->toBe(0);
})->with(['accept_terms', 'accept_privacy']);

test('thieu truong dong y (khong gui) -> 422', function () {
    $payload = vvRegisterPayload();
    unset($payload['accept_terms'], $payload['accept_privacy']);

    $this->postJson(vvApiUrl('/auth/register'), $payload, vvWebHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['accept_terms', 'accept_privacy']);
});

test('AC2 email/SDT da ton tai -> loi dung field, khong tao them', function () {
    vvRegister()->assertCreated();
    vvResetClient();

    vvRegister(['email' => 'AN@Example.com', 'phone' => '0987654321'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Email đã được sử dụng.')
        ->assertJsonMissingPath('errors.phone');

    vvRegister(['email' => 'khac@example.com', 'phone' => '+84912345678'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', 'Số điện thoại đã được sử dụng.');

    expect(User::count())->toBe(1);
});

/** Chèn user trùng NGAY trước câu INSERT của đăng ký (mô phỏng race sau khi qua rule unique). */
function vvSimulateRace(array $row): void
{
    $inserted = false;
    DB::listen(function ($query) use (&$inserted, $row) {
        if (! $inserted && str_starts_with($query->sql, 'insert into `users`')) {
            $inserted = true;
            DB::table('users')->insert(array_merge([
                'name' => 'Race', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
            ], $row));
        }
    });
}

test('race: unique violation nhanh email -> 422 dung field (khong 500)', function () {
    vvSimulateRace(['email' => 'an@example.com', 'phone' => '0900000001']);

    vvRegister()
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors('email')
        ->assertJsonMissingPath('errors.phone');

    expect(Consent::count())->toBe(0);
});

test('race: unique violation nhanh phone -> 422 loi o field phone', function () {
    vvSimulateRace(['email' => 'khac@example.com', 'phone' => '0912345678']);

    vvRegister()
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone')
        ->assertJsonMissingPath('errors.email');
});

test('race: email chua chuoi users_phone_unique van bao dung field email', function () {
    vvSimulateRace(['email' => 'users_phone_unique@x.vn', 'phone' => '0900000002']);

    vvRegister(['email' => 'users_phone_unique@x.vn'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email')
        ->assertJsonMissingPath('errors.phone');
});

test('captcha fake tu choi token rong', function () {
    vvRegister(['captcha_token' => ''])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
});

test('khong co Origin hop le thi khong goi Cloudflare (R4)', function () {
    config(['captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app', 'services.turnstile.secret' => 's']);
    Http::fake();

    $this->postJson(vvApiUrl('/auth/register'), vvRegisterPayload(['captcha_token' => 'tok']))
        ->assertStatus(400)->assertJsonPath('code', 'ORIGIN_NOT_ALLOWED');
    Http::assertNothingSent();
});

test('response register co Cache-Control no-store (R5)', function () {
    $response = vvRegister()->assertCreated();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('AC5 xac nhan mat khau khong khop -> loi o field password_confirmation', function () {
    vvRegister(['password_confirmation' => 'khac-hoan-toan'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password_confirmation')
        ->assertJsonMissingPath('errors.password');

    expect(User::count())->toBe(0);
});

test('BR3 mat khau duoi 8 ky tu -> 422', function () {
    vvRegister(['password' => 'abc1234', 'password_confirmation' => 'abc1234'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

test('bien lop hoc 5 loi, 6 va 12 hop le, 13 loi', function (int $grade, bool $ok) {
    $response = vvRegister(['grade_level' => $grade]);
    $ok ? $response->assertCreated() : $response->assertStatus(422)->assertJsonValidationErrors('grade_level');
})->with([[5, false], [6, true], [12, true], [13, false]]);

test('SDT sai dinh dang, email sai dinh dang, thieu truong bat buoc -> 422', function () {
    vvRegister(['phone' => '12345', 'email' => 'khong-phai-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone', 'email']);

    $this->postJson(vvApiUrl('/auth/register'), ['captcha_token' => 'ok', 'accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'phone', 'password', 'grade_level', 'date_of_birth']);
});

test('ho ten chua HTML duoc luu nguyen van (escape khi hien thi o frontend), ky tu dieu khien bi tu choi', function () {
    vvRegister(['name' => '<script>alert(1)</script>'])->assertCreated();
    expect(User::firstOrFail()->name)->toBe('<script>alert(1)</script>');

    vvWipeUsers();
    vvResetClient();
    vvRegister(['name' => "An\x00Nguyen"])->assertStatus(422)->assertJsonValidationErrors('name');
});

test('AC7 ma gioi thieu duoc luu; flag tat thi khong luu', function () {
    vvRegister(['referral_code' => 'GV-ANH_01'])->assertCreated();
    expect(User::firstOrFail()->referral_code_used)->toBe('GV-ANH_01');

    vvWipeUsers();
    vvResetClient();
    config(['features.referral_code' => false]);
    vvRegister(['referral_code' => 'GV-ANH_01'])->assertCreated();
    expect(User::firstOrFail()->referral_code_used)->toBeNull();
});

test('ma gioi thieu chua ky tu la -> 422', function () {
    vvRegister(['referral_code' => "x' OR 1=1 --"])->assertStatus(422)->assertJsonValidationErrors('referral_code');
});

describe('lien he phu huynh tuy chon (AC10, ADR-006)', function () {
    test('duoi 18 tuoi khong nhap lien he phu huynh -> 201, not_required', function () {
        vvRegister(['date_of_birth' => now('Asia/Ho_Chi_Minh')->subYears(15)->toDateString()])
            ->assertCreated()->assertJson(['parent_consent_status' => 'not_required']);

        expect(User::count())->toBe(1);
    });

    test('duoi 18 tuoi co 1 lien he phu huynh -> not_required, luu ma hoa', function () {
        vvRegister([
            'date_of_birth' => now('Asia/Ho_Chi_Minh')->subYears(15)->toDateString(),
            'parent_email' => 'PhuHuynh@Example.com',
        ])->assertCreated()->assertJson(['parent_consent_status' => 'not_required'])
            ->assertJsonMissingPath('parent_email');

        $user = User::firstOrFail();
        expect($user->parent_email)->toBe('phuhuynh@example.com');

        $raw = DB::table('users')->where('id', $user->id)->value('parent_email');
        expect($raw)->not->toContain('example.com');
    });

    test('ranh gioi tuoi 18 khong con y nghia: ai cung dang ky khong can phu huynh', function () {
        $today = now('Asia/Ho_Chi_Minh')->startOfDay();

        vvRegister(['date_of_birth' => $today->copy()->subYears(18)->addDay()->toDateString()])
            ->assertCreated()->assertJson(['parent_consent_status' => 'not_required']);
    });

    test('nguoi lon gui lien he phu huynh thi van duoc luu', function () {
        vvRegister(['parent_email' => 'ph@example.com', 'parent_phone' => '0911111111'])->assertCreated();

        $user = User::firstOrFail();
        expect($user->parent_email)->toBe('ph@example.com')->and($user->parent_phone)->toBe('0911111111');
    });

    test('ngay sinh o tuong lai hoac khong ton tai -> 422', function () {
        vvRegister(['date_of_birth' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
        vvRegister(['date_of_birth' => '2010-02-31'])
            ->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    });
});

describe('captcha', function () {
    test('token bi tu choi -> 422 CAPTCHA_FAILED, khong tao tai khoan, khong lo email ton tai', function () {
        vvRegister()->assertCreated();
        vvResetClient();

        // Email trùng + captcha sai: phải ra CAPTCHA_FAILED, không phải lỗi unique (S20).
        vvRegister(['captcha_token' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAPTCHA_FAILED')
            ->assertJsonMissingPath('errors');

        expect(User::count())->toBe(1);
    });

    test('driver turnstile: thieu token -> CAPTCHA_FAILED; Cloudflare tra success=false -> CAPTCHA_FAILED; success=true -> 201', function () {
        config(['captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app', 'services.turnstile.secret' => 'secret-test']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true])]);

        $payload = vvRegisterPayload();
        unset($payload['captcha_token']);
        $this->postJson(vvApiUrl('/auth/register'), $payload, vvWebHeaders())
            ->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
        Http::assertNothingSent();

        vvRegister(['captcha_token' => 'tok'])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
        vvRegister(['captcha_token' => 'tok'])->assertCreated();

        Http::assertSent(fn ($r) => $r['secret'] === 'secret-test' && $r['response'] === 'tok');
    });

    test('driver turnstile: loi mang -> fail-closed', function () {
        config(['captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app', 'services.turnstile.secret' => 'secret-test']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response('boom', 500)]);

        vvRegister(['captcha_token' => 'tok'])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
    });

    test('driver turnstile khong co secret -> fail-closed', function () {
        config(['captcha.driver' => 'turnstile', 'services.turnstile.secret' => 'ts-secret', 'services.turnstile.site_key' => 'ts-site', 'mail.default' => 'smtp', 'database.redis.default.password' => 'redis-secret', 'database.redis.video.password' => 'redis-secret', 'database.connections.mysql.username' => 'vv_app', 'services.turnstile.secret' => '']);
        Http::fake();

        vvRegister(['captcha_token' => 'tok'])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
        Http::assertNothingSent();
    });
});

test('throttle register theo IP: 30/gio, request thu 31 -> 429; X-Forwarded-For gia khong tron duoc', function () {
    for ($i = 1; $i <= 30; $i++) {
        vvRegister(['email' => "u{$i}@example.com", 'phone' => '09'.str_pad((string) $i, 8, '0', STR_PAD_LEFT)], ['X-Forwarded-For' => "10.0.0.{$i}"]);
        vvResetClient();
    }

    vvRegister(['email' => 'last@example.com'], ['X-Forwarded-For' => '10.9.9.9'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
});

test('khong co Origin hop le -> 400 ORIGIN_NOT_ALLOWED, khong 500', function () {
    $this->postJson(vvApiUrl('/auth/register'), vvRegisterPayload())
        ->assertStatus(400)
        ->assertJsonPath('code', 'ORIGIN_NOT_ALLOWED');
});

test('register khong ton tai tren host admin-api', function () {
    $this->postJson('http://'.config('app.admin_api_host').'/api/v1/auth/register', vvRegisterPayload(), ['Origin' => config('app.admin_url')])
        ->assertNotFound();
});
