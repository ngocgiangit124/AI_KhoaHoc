<?php

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\Otp\OtpSender;
use App\Services\Auth\StudentSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Cache::flush();
});

describe('forgot', function () {
    test('AC1 tai khoan ton tai: 202 thong diep chung + OTP reset_password gui toi email', function () {
        $sender = vvFakeOtp();
        vvPwStudent();

        vvForgot()->assertStatus(202)->assertJsonPath('message', 'Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.');

        expect($sender->sent)->toHaveCount(1)
            ->and($sender->sent[0]['purpose'])->toBe('reset_password')
            ->and($sender->sent[0]['destination'])->toBe('hs@example.com')
            ->and(OtpCode::where('purpose', 'reset_password')->count())->toBe(1);
    });

    test('BR2 tai khoan khong ton tai: cung status + cung body (tru resend_available_at theo giay), khong gui', function () {
        $sender = vvFakeOtp();
        vvPwStudent();

        $a = vvForgot();
        $b = vvForgot(['login' => 'khongco@example.com']);

        expect($b->status())->toBe($a->status())
            ->and($b->json('message'))->toBe($a->json('message'))
            ->and(array_keys($b->json()))->toBe(array_keys($a->json()))
            ->and($sender->sent)->toHaveCount(1);
    });

    test('email chua xac thuc van nhan OTP; dang nhap bang SDT dang +84 van tim ra', function () {
        $sender = vvFakeOtp();
        vvPwStudent(['email_verified_at' => null]);

        vvForgot(['login' => '+84 912 345 678'])->assertStatus(202);

        expect($sender->sent)->toHaveCount(1)->and($sender->sent[0]['destination'])->toBe('hs@example.com');
    });

    test('AC8 tai khoan locked / giao vien: 202 chung nhung khong gui OTP', function () {
        $sender = vvFakeOtp();
        vvPwStudent(['status' => UserStatus::Locked]);
        User::factory()->teacher()->create(['email' => 'gv@example.com']);

        vvForgot()->assertStatus(202);
        vvForgot(['login' => 'gv@example.com'])->assertStatus(202);

        expect($sender->sent)->toBe([]);
    });

    test('loi gui mail khong lam doi status (khong lo ton tai)', function () {
        app()->instance(OtpSender::class, new class implements OtpSender
        {
            public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
            {
                throw new RuntimeException('smtp down');
            }
        });
        vvPwStudent();

        vvForgot()->assertStatus(202);
    });

    test('BR6 captcha sai/thieu -> 422 CAPTCHA_FAILED, khong gui', function (?string $token) {
        $sender = vvFakeOtp();
        vvPwStudent();

        vvForgot(['captcha_token' => $token])->assertStatus(422)->assertJsonPath('code', 'CAPTCHA_FAILED');
        expect($sender->sent)->toBe([]);
    })->with(['invalid', null]);

    test('khong co Origin hop le -> 400 ORIGIN_NOT_ALLOWED', function () {
        $this->postJson(vvApiUrl('/auth/password/forgot'), ['login' => 'hs@example.com', 'captcha_token' => 'ok'])
            ->assertStatus(400)->assertJsonPath('code', 'ORIGIN_NOT_ALLOWED');
    });

    test('AC6 cooldown: lan 2 ngay sau do -> 429 + Retry-After, tinh theo tai khoan CHUAN HOA (khong ne bang +84/hoa thuong), ca tai khoan khong ton tai', function () {
        vvFakeOtp();
        vvPwStudent();

        vvForgot()->assertStatus(202);
        vvForgot(['login' => 'HS@Example.com'])->assertStatus(429)->assertHeader('Retry-After');
        vvForgot(['login' => '+84912345678'])->assertStatus(429); // email và SĐT của cùng 1 tài khoản dùng chung hạn mức
        vvForgot(['login' => '0912 345 678'])->assertStatus(429);

        vvForgot(['login' => 'khongco@example.com'])->assertStatus(202);
        vvForgot(['login' => 'KhongCo@example.com'])->assertStatus(429);
    });

    test('R2 request khong captcha / captcha sai KHONG tieu hao han muc theo tai khoan cua nan nhan', function () {
        $sender = vvFakeOtp();
        vvPwStudent();

        for ($i = 0; $i < 8; $i++) {
            vvForgot(['captcha_token' => 'invalid'])->assertStatus(422);
        }

        vvForgot()->assertStatus(202);
        expect($sender->sent)->toHaveCount(1);
        vvForgot()->assertStatus(429);
    });

    test('BR7 tran OTP cua tai khoan (OtpService) bi nuot: van 202, khong tao them ma', function () {
        $sender = vvFakeOtp();
        $user = vvPwStudent();
        OtpCode::factory()->count(5)->create(['user_id' => $user->id, 'created_at' => now()->subMinutes(10)]);

        vvForgot()->assertStatus(202);

        expect($sender->sent)->toBe([]);
    });

    test('hoc sinh dang dang nhap hop le goi forgot -> 403 FORBIDDEN (guest.student)', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();

        $a->call('POST', '/auth/password/forgot', ['login' => 'hs@example.com', 'captcha_token' => 'ok'])
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    });
});

describe('reset', function () {
    function vvIssueResetCode(User $user): string
    {
        $sender = vvFakeOtp();
        (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => $user->email, 'captcha_token' => 'ok'])->assertStatus(202);
        Cache::flush();

        return $sender->lastCode();
    }

    test('AC2 dat lai thanh cong: doi mat khau, password_changed_at, dang nhap bang mat khau moi, ma bi tieu thu', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);

        vvReset($code)->assertOk()->assertJsonPath('message', 'Mật khẩu đã được đặt lại. Vui lòng đăng nhập bằng mật khẩu mới.');

        $fresh = $user->fresh();
        expect(Hash::check('mat-khau-moi-2', $fresh->password))->toBeTrue()
            ->and($fresh->password_changed_at)->not->toBeNull()
            ->and(OtpCode::where('purpose', 'reset_password')->whereNotNull('consumed_at')->count())->toBe(1)
            ->and(AuditLog::where('action', 'account.password_reset')->count())->toBe(1);

        (new VvPwBrowser)->login('mat-khau-moi-2')->assertOk();
        (new VvPwBrowser)->login('mat-khau-cu-1')->assertStatus(422);
    });

    test('BR3 A dang nhap, B (khach) dat lai mat khau bang OTP -> A nhan 401 SESSION_REVOKED', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();
        $oldSession = $a->cookie;
        $a->me()->assertOk();

        $code = vvIssueResetCode($user);
        $b = new VvPwBrowser;
        $b->call('POST', '/auth/password/reset', [
            'login' => 'hs@example.com', 'code' => $code, 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'mat-khau-moi-2',
        ])->assertOk();

        expect($user->fresh()->current_session_id)->toBe(StudentSessionService::LOGGED_OUT)
            ->and(StudentSessionService::tombstone($oldSession)['reason'])->toBe('password_changed');

        $a->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
    });

    test('AC3 ma sai -> 422 field code, khong doi mat khau; ma het han -> 422', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        // T27-5: mã sai trả cùng OTP_EXPIRED + thông điệp như tài khoản không tồn tại (không lộ tài khoản có mã hiệu lực).
        vvReset($wrong)->assertStatus(422)->assertJsonValidationErrors('code')
            ->assertJsonPath('code', 'OTP_EXPIRED')
            ->assertJsonPath('errors.code.0', "Mã OTP đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới.");
        expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();

        OtpCode::query()->update(['expires_at' => now()->subMinute()]);
        vvReset($code)->assertStatus(422)->assertJsonValidationErrors('code');
        expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
    });

    test('ma het luot (5 lan sai) -> 422 OTP_EXPIRED (T27-5: khong lo 429 rieng), ma dung cung khong dung duoc', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            Cache::flush();
            vvReset($wrong)->assertStatus(422);
        }
        Cache::flush();
        vvReset($code)->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
        expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
    });

    test('AC7 dung lai ma da dung -> 422, mat khau khong bi doi lan 2', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);

        vvReset($code)->assertOk();
        vvReset($code, ['password' => 'mat-khau-khac-3', 'password_confirmation' => 'mat-khau-khac-3'])->assertStatus(422);

        expect(Hash::check('mat-khau-moi-2', $user->fresh()->password))->toBeTrue();
    });

    test('tai khoan khong ton tai: 422 code cung thong diep "het han" nhu tai khoan chua co ma', function () {
        $user = vvPwStudent();
        $noCode = vvReset('123456');
        $ghost = vvReset('123456', ['login' => 'khongco@example.com']);

        expect($ghost->status())->toBe(422)
            ->and($ghost->json('errors.code.0'))->toBe($noCode->json('errors.code.0'));
        expect($user->fresh()->password)->not->toBeNull();
    });

    test('BR8 bi khoa sau khi ma duoc gui -> khong hoan tat, ma khong bi tieu thu', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);
        $user->forceFill(['status' => UserStatus::Locked])->save();

        vvReset($code)->assertStatus(422)->assertJsonValidationErrors('code');

        expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue()
            ->and(OtpCode::whereNotNull('consumed_at')->count())->toBe(0);
    });

    test('ma gan voi email cu: doi email sau khi gui -> ma vo hieu', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);
        $user->forceFill(['email' => 'moi@example.com'])->save();

        vvReset($code, ['login' => 'moi@example.com'])->assertStatus(422);
        expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
    });

    test('validation: mat khau ngan / xac nhan khong khop / ma khong du 6 so', function () {
        $user = vvPwStudent();
        $code = vvIssueResetCode($user);

        vvReset($code, ['password' => 'ngan', 'password_confirmation' => 'ngan'])->assertStatus(422)->assertJsonValidationErrors('password');
        vvReset($code, ['password_confirmation' => 'khac-han-123'])->assertStatus(422)->assertJsonValidationErrors('password_confirmation');
        vvReset('12ab')->assertStatus(422)->assertJsonValidationErrors('code');
    });

    test('throttle verify theo tai khoan chuan hoa: vuot 5 lan/phut -> 429 du doi cach viet login', function () {
        vvPwStudent();
        $logins = ['hs@example.com', 'HS@example.com', '0912345678', '+84912345678', ' hs@EXAMPLE.com '];

        foreach ($logins as $login) {
            vvReset('123456', ['login' => $login])->assertStatus(422);
        }
        vvReset('123456', ['login' => '84912345678'])->assertStatus(429);
    });
});

describe('change (PUT /auth/password)', function () {
    test('AC4 + R5 (T05): A, B dang nhap; B doi mat khau qua HTTP -> B van dung duoc (bind lai), A nhan SESSION_REVOKED, mat khau cu het hieu luc', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $b = new VvPwBrowser;

        $a->login()->assertOk();
        $oldA = $a->cookie;
        $b->login()->assertOk(); // B thay A (SESSION_REPLACED) — phiên hiện hành là B
        $oldB = $b->cookie;

        $res = $b->changePassword('mat-khau-cu-1', 'mat-khau-moi-2');
        $res->assertOk()->assertJsonPath('session_kept', true);

        $fresh = $user->fresh();
        expect(Hash::check('mat-khau-moi-2', $fresh->password))->toBeTrue()
            ->and($fresh->password_changed_at)->not->toBeNull()
            ->and($b->cookie)->not->toBe($oldB) // regenerate
            ->and($fresh->current_session_id)->toBe($b->cookie) // bind lại
            ->and($fresh->current_device_id)->toBe($b->device)
            ->and(AuditLog::where('action', 'account.password_changed')->count())->toBe(1);

        $b->me()->assertOk()->assertJsonPath('id', $user->id);
        // Cookie cũ của chính B không còn dùng được (đã regenerate + tombstone), và phiên cũ của A cũng không.
        $stale = new VvPwBrowser;
        $stale->device = $b->device;
        $stale->cookie = $oldB;
        $stale->me()->assertStatus(401);
        expect(StudentSessionService::tombstone($oldB)['reason'])->toBe('password_changed');
        $a->me()->assertStatus(401);
    });

    test('doi mat khau khi A la phien hien hanh va con 1 phien cu: phien cu nhan SESSION_REVOKED (tombstone password_changed)', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();
        $oldA = $a->cookie;

        $a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2')->assertOk();

        $other = new VvPwBrowser;
        $other->cookie = $oldA; // trình duyệt khác còn giữ cookie cũ
        $other->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
        $a->me()->assertOk();
        expect($user->fresh()->current_session_id)->toBe($a->cookie);
    });

    test('AC5 sai mat khau hien tai -> 422 current_password, khong doi, phien khong bi huy', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();

        $a->changePassword('sai-mat-khau-9', 'mat-khau-moi-2')->assertStatus(422)
            ->assertJsonValidationErrors('current_password')
            ->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không đúng.');

        expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue();
        $a->me()->assertOk();
    });

    test('validation: moi trung cu, ngan, xac nhan khong khop', function () {
        vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();

        $a->changePassword('mat-khau-cu-1', 'mat-khau-cu-1')->assertStatus(422)->assertJsonValidationErrors('password');
        $a->changePassword('mat-khau-cu-1', 'ngan')->assertStatus(422)->assertJsonValidationErrors('password');
        $a->call('PUT', '/auth/password', ['current_password' => 'mat-khau-cu-1', 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'khac-123456'])
            ->assertStatus(422)->assertJsonValidationErrors('password_confirmation');
        $a->me()->assertOk();
    });

    test('chua dang nhap -> 401', function () {
        $this->putJson(vvApiUrl('/auth/password'), ['current_password' => 'x', 'password' => 'y'], vvWebHeaders())->assertStatus(401);
    });

    test('giao vien (role khac hoc_sinh) khong dung duoc PUT /auth/password o host api -> 403, mat khau khong doi', function () {
        $teacher = User::factory()->teacher()->create(['password' => Hash::make('mat-khau-cu-1')]);
        $this->actingAs($teacher);

        $this->putJson(vvApiUrl('/auth/password'), [
            'current_password' => 'mat-khau-cu-1', 'password' => 'mat-khau-moi-2', 'password_confirmation' => 'mat-khau-moi-2',
        ], vvWebHeaders())->assertStatus(403);

        expect(Hash::check('mat-khau-cu-1', $teacher->fresh()->password))->toBeTrue();
    });

    test('R3 doi mat khau KHONG co header X-Device-Id: bind lai giu current_device_id cu', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();
        expect($user->fresh()->current_device_id)->toBe($a->device);

        $a->sendDevice = false;
        $a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2')->assertOk()->assertJsonPath('session_kept', true);

        expect($user->fresh()->current_device_id)->toBe($a->device)
            ->and($user->fresh()->current_session_id)->toBe($a->cookie);
    });

    test('R5 PUT /auth/password tra Cache-Control no-store', function () {
        vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();

        expect($a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2')->headers->get('Cache-Control'))->toContain('no-store');
    });

    test('bind lai loi: mat khau van doi, 200 nhung session_kept=false va khong con phien (fail-safe)', function () {
        $user = vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();

        User::updating(function (User $u): bool {
            if ($u->isDirty('current_session_id') && $u->current_session_id !== StudentSessionService::LOGGED_OUT) {
                throw new RuntimeException('db down');
            }

            return true;
        });

        $a->changePassword('mat-khau-cu-1', 'mat-khau-moi-2')->assertOk()->assertJsonPath('session_kept', false);
        User::flushEventListeners();

        expect(Hash::check('mat-khau-moi-2', $user->fresh()->password))->toBeTrue();
        $a->me()->assertStatus(401);
    });

    test('throttle password-change: 6 lan/phut -> 429', function () {
        vvPwStudent();
        $a = new VvPwBrowser;
        $a->login()->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $a->changePassword('sai-mat-khau-9', 'mat-khau-moi-2')->assertStatus(422);
        }
        $a->changePassword('sai-mat-khau-9', 'mat-khau-moi-2')->assertStatus(429);
    });
});
