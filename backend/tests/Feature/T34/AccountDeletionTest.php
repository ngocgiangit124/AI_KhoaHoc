<?php

use App\Enums\ConsentType;
use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Mail\OtpMail;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Consent;
use App\Models\Course;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\PaymentAttempt;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\StudentSessionService;
use App\Services\Orders\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T27/helpers.php';

// ---------------------------------------------------------------------------------------------------------------------
// (e) bước gửi mã
// ---------------------------------------------------------------------------------------------------------------------

test('(e) chua xac thuc email -> 403 ACCOUNT_NOT_VERIFIED, khong gui ma', function () {
    $otp = vvFakeOtp();
    vvActAsStudent(User::factory()->student()->create(['email' => 'chua@example.com']));

    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_NOT_VERIFIED')
        ->assertJsonPath('message', 'Bạn cần xác thực email trước khi xoá tài khoản.');

    expect($otp->sent)->toBe([])->and(OtpCode::query()->count())->toBe(0);
});

test('(e) chi co SDT da xac thuc (khong co email) cung bi chan', function () {
    $otp = vvFakeOtp();
    vvActAsStudent(User::factory()->student()->create(['email' => null, 'phone' => '0912345678', 'phone_verified_at' => now()]));

    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_NOT_VERIFIED');
    expect($otp->sent)->toBe([]);
});

test('(e) don pending co attempt pending con han -> 409 ACCOUNT_HAS_PENDING_PAYMENT + retry_after_at (+07:00 = expires_at muon nhat)', function () {
    $this->freezeSecond(); // so sánh tới giây với now(): tránh đỏ giả khi máy chậm vượt ranh giới giây
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $order = vvT34PendingOrder($me, 'pending', now()->addMinutes(10));
    PaymentAttempt::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'expires_at' => now()->addMinutes(25), 'gateway_order_id' => $order->code.'-2']);

    $r = test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_PENDING_PAYMENT');

    expect($r->json('errors.retry_after_at'))->toEndWith('+07:00')
        ->and(Carbon::parse($r->json('errors.retry_after_at'))->equalTo(now()->addMinutes(25)->startOfSecond()))->toBeTrue()
        ->and($otp->sent)->toBe([]);
});

test('(e) attempt het han / da failed / chi created / don khac pending khong chan', function (string $kind) {
    $otp = vvFakeOtp();
    $me = vvT34Student();

    match ($kind) {
        'het_han' => vvT34PendingOrder($me, 'pending', now()->subMinute()),
        'failed' => vvT34PendingOrder($me, 'failed', now()->addMinutes(10)),
        'created' => vvT34PendingOrder($me, 'created', now()->addMinutes(10)),
        'khong_attempt' => vvT34PendingOrder($me, withAttempt: false),
        'don_da_tra' => PaymentAttempt::factory()->create(['order_id' => Order::factory()->paid()->create(['user_id' => $me->id])->id, 'status' => 'pending']),
    };

    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(202);
    expect($otp->sent)->toHaveCount(1);
})->with(['het_han', 'failed', 'created', 'khong_attempt', 'don_da_tra']);

test('(e) gui ma purpose delete_account toi email, shape 202, audit khong PII, ma cu bi huy; gui lai trong 60 s -> 429', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student(['email' => 'hoc.sinh@example.com']);

    $r = test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(202);

    expect(array_keys($r->json()))->toBe(['resend_available_at', 'destination_masked'])
        ->and($r->json('destination_masked'))->toBe('h***@example.com')
        ->and($r->json('resend_available_at'))->toEndWith('+07:00')
        ->and($otp->sent)->toHaveCount(1)
        ->and($otp->sent[0]['purpose'])->toBe('delete_account')
        ->and($otp->sent[0]['destination'])->toBe('hoc.sinh@example.com')
        ->and($otp->sent[0]['channel'])->toBe('email');
    $row = OtpCode::query()->where('user_id', $me->id)->firstOrFail();
    expect($row->purpose)->toBe(OtpPurpose::DeleteAccount);

    $log = AuditLog::query()->where('action', 'privacy.account_delete_otp_sent')->where('subject_id', $me->id)->firstOrFail();
    expect($log->actor_id)->toBe($me->id)->and(json_encode($log->changes))->not->toContain('hoc.sinh');

    $again = test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(429);
    expect((int) $again->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($otp->sent)->toHaveCount(1);

    // Hết cooldown: gửi lại được, mã cũ bị huỷ.
    $this->travel(61)->seconds();
    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(202);
    expect(OtpCode::query()->where('user_id', $me->id)->whereNull('invalidated_at')->whereNull('consumed_at')->count())->toBe(1);
    vvT34Delete($otp->sent[0]['code'])->assertStatus(422);
});

test('(e) thu OTP xoa tai khoan co tieu de/cau canh bao rieng', function () {
    $mail = new OtpMail('An', '123456', OtpPurpose::DeleteAccount, 10);

    expect($mail->envelope()->subject)->toBe('Mã xác nhận xoá tài khoản VitaminVui');
    $html = $mail->render();
    expect($html)->toContain('XOÁ TÀI KHOẢN')->toContain('123456')->toContain('không thể hoàn tác')->toContain('Không chia sẻ mã này');

    $verify = (new OtpMail('An', '123456', OtpPurpose::VerifyAccount, 10));
    expect($verify->envelope()->subject)->toBe('Mã xác thực VitaminVui của bạn')->and($verify->render())->not->toContain('XOÁ TÀI KHOẢN');
});

test('(e) mã verify_account khong dung duoc de xoa tai khoan (purpose tach biet)', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    OtpCode::factory()->withCode('123456')->create(['user_id' => $me->id, 'purpose' => OtpPurpose::VerifyAccount, 'destination' => $me->email]);

    vvT34Delete('123456')->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    expect($me->fresh()->anonymized_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// (f) bước xác nhận
// ---------------------------------------------------------------------------------------------------------------------

test('(f) ma sai / het han / khong co ma -> 422 va MOI cot tai khoan giu nguyen', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    vvT34Seed($me);
    $code = vvT34OtpForDelete($otp);
    $before = User::query()->find($me->id)->getAttributes();
    $consentsBefore = Consent::query()->where('user_id', $me->id)->get()->map->getAttributes()->all();

    $wrong = $code === '000000' ? '111111' : '000000';
    vvT34Delete($wrong)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID')->assertJsonValidationErrors('code');

    $this->travel(11)->minutes();
    vvT34Delete($code)->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    $this->travelBack();

    expect(User::query()->find($me->id)->getAttributes())->toBe($before)
        ->and(Consent::query()->where('user_id', $me->id)->get()->map->getAttributes()->all())->toBe($consentsBefore)
        ->and(vvT34Audits('privacy.account_anonymized', $me->id))->toBe(0)
        ->and(OtpCode::query()->where('user_id', $me->id)->count())->toBe(1);
});

test('(f) chua gui ma -> OTP_EXPIRED; dinh dang ma sai -> 422 validation; thieu -> 422', function () {
    $me = vvT34Student();

    vvT34Delete('123456')->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    test()->postJson(vvApiUrl('/me/account/delete'), ['code' => '12345'], vvWebHeaders())->assertStatus(422)->assertJsonValidationErrors('code');
    test()->postJson(vvApiUrl('/me/account/delete'), ['code' => 123456], vvWebHeaders())->assertStatus(422)->assertJsonValidationErrors('code');
    test()->postJson(vvApiUrl('/me/account/delete'), [], vvWebHeaders())->assertStatus(422)->assertJsonValidationErrors('code');
    expect($me->fresh()->anonymized_at)->toBeNull();
});

test('(f) het 5 luot cua ma -> 429 TOO_MANY_ATTEMPTS, ma dung cung khong dung duoc', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        vvT34Delete($wrong)->assertStatus(422);
    }
    // otp-verify: 5/phút theo cấu hình; sang phút khác để chạm trần của chính mã.
    $this->travel(61)->seconds();
    vvT34Delete($code)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    expect($me->fresh()->anonymized_at)->toBeNull();
});

test('(f) dung ma -> cot users khop bang T34.3', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student(['phone' => '0912345678', 'parent_notice_opt_out_at' => now()->subDay(), 'remember_token' => 'abc', 'current_device_id' => (string) Str::uuid()]);
    $hashBefore = $me->password;
    $kept = $me->fresh();
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk()->assertExactJson(['message' => 'Tài khoản của bạn đã được xoá.']);

    $u = User::query()->findOrFail($me->id);
    expect($u->name)->toBe('Tài khoản đã xoá')
        ->and($u->email)->toBeNull()->and($u->phone)->toBeNull()->and($u->date_of_birth)->toBeNull()
        ->and($u->parent_email)->toBeNull()->and($u->parent_phone)->toBeNull()->and($u->parent_notice_opt_out_at)->toBeNull()
        ->and($u->referral_code_used)->toBeNull()->and($u->bio)->toBeNull()->and($u->avatar_path)->toBeNull()
        ->and($u->remember_token)->toBeNull()->and($u->current_device_id)->toBeNull()
        ->and($u->anonymized_at)->not->toBeNull()
        ->and($u->current_session_id)->toBe('logged_out')
        ->and($u->password)->not->toBe($hashBefore)
        ->and(Hash::check('password', $u->password))->toBeFalse()
        // Giữ nguyên
        ->and($u->role)->toBe($kept->role)->and($u->status)->toBe($kept->status)->and($u->grade_level)->toBe($kept->grade_level)
        ->and($u->email_verified_at?->equalTo($kept->email_verified_at))->toBeTrue()
        ->and($u->phone_verified_at?->equalTo($kept->phone_verified_at))->toBeTrue()
        ->and($u->created_at->equalTo($kept->created_at))->toBeTrue();
    // Cột dạng raw (parent_*) cũng NULL, không chỉ cast.
    expect(DB::table('users')->where('id', $me->id)->first(['parent_email', 'parent_phone', 'email', 'phone']))
        ->toEqual((object) ['parent_email' => null, 'parent_phone' => null, 'email' => null, 'phone' => null]);
});

test('(f) consents bi thu hoi + ip/ua/destination NULL, giu loai/phien ban/thoi diem; otp_codes cua user = 0', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $c1 = vvT34Consent($me, ConsentType::Terms, '2026-09', ['destination_masked' => 'a***@x.com']);
    $c2 = vvT34Consent($me, ConsentType::PrivacyPolicy, revokedAtHelper());
    $other = vvT34Create();
    $oc = vvT34Consent($other);
    OtpCode::factory()->create(['user_id' => $other->id]);
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    $rows = Consent::query()->where('user_id', $me->id)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->revoked_at)->not->toBeNull()->and($row->ip)->toBeNull()->and($row->user_agent)->toBeNull()->and($row->destination_masked)->toBeNull();
    }
    expect($rows->firstWhere('id', $c1->id)->policy_version)->toBe('2026-09')
        ->and($rows->firstWhere('id', $c1->id)->granted_at->equalTo($c1->fresh()->granted_at))->toBeTrue()
        ->and(OtpCode::query()->where('user_id', $me->id)->count())->toBe(0);

    // Không đụng dữ liệu người khác
    expect($oc->fresh()->revoked_at)->toBeNull()->and($oc->fresh()->ip)->toBe('203.0.113.5')
        ->and(OtpCode::query()->where('user_id', $other->id)->count())->toBe(1)
        ->and($other->fresh()->anonymized_at)->toBeNull()->and($other->fresh()->email)->not->toBeNull();
});

function revokedAtHelper(): string
{
    return '2026-09';
}

test('(f) giu thoi diem thu hoi cu cua dong da thu hoi', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $old = vvT34Consent($me, ConsentType::Terms, '2026-09', ['revoked_at' => '2026-09-15 08:00:00']);
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    expect($old->fresh()->revoked_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 08:00:00');
});

test('(f) dung 1 audit privacy.account_anonymized, actor = user, changes rong, khong PII o audit nao cua user', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student(['name' => 'Tên Rất Riêng Biệt', 'email' => 'rieng.biet@example.com', 'phone' => '0987654321']);
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    $logs = AuditLog::query()->where('action', 'privacy.account_anonymized')->where('subject_id', $me->id)->get();
    expect($logs)->toHaveCount(1)->and($logs[0]->actor_id)->toBe($me->id)->and($logs[0]->changes)->toBe([]);

    $dump = AuditLog::query()->where(fn ($q) => $q->where('actor_id', $me->id)->orWhere('subject_id', $me->id))->get()->map(fn ($l) => json_encode($l->changes).$l->action)->implode('|');
    expect($dump)->not->toContain('rieng.biet')->not->toContain('0987654321')->not->toContain('Riêng')->not->toContain('phuhuynh@example.com');
});

test('(f) erase() duoc goi: dong teacher_profiles cua user bi xoa + dong y cong khai thu hoi', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    TeacherProfile::factory()->create(['user_id' => $me->id, 'bio' => 'x', 'public_consent_at' => now(), 'public_consent_version' => (string) config('teacher_profile.consent_version')]);
    vvT34Consent($me, ConsentType::TeacherPublicProfile);
    $code = vvT34OtpForDelete($otp);

    vvT34Delete($code)->assertOk();

    expect(TeacherProfile::query()->where('user_id', $me->id)->count())->toBe(0)
        ->and(Consent::query()->where('user_id', $me->id)->where('type', 'teacher_public_profile')->whereNull('revoked_at')->count())->toBe(0)
        ->and(vvT34Audits('teacher_profile.erase', $me->id))->toBe(1);
});

test('(f) doi email sau khi gui ma -> OTP_EXPIRED, khong xoa', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student(['email' => 'cu@example.com']);
    $code = vvT34OtpForDelete($otp);
    User::query()->whereKey($me->id)->update(['email' => 'moi@example.com']);

    vvT34Delete($code)->assertStatus(422)->assertJsonPath('code', 'OTP_EXPIRED');
    expect($me->fresh()->anonymized_at)->toBeNull();
});

test('(f) email bi mat xac thuc sau khi gui ma -> 403 ACCOUNT_NOT_VERIFIED', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);
    User::query()->whereKey($me->id)->update(['email_verified_at' => null, 'phone_verified_at' => null]);
    vvActAsStudent(User::query()->findOrFail($me->id)); // request thật luôn nạp user mới từ DB

    vvT34Delete($code)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_NOT_VERIFIED');
    expect($me->fresh()->anonymized_at)->toBeNull();
});

test('(f) co link thanh toan song luc xac nhan -> 409, KHONG tieu ma, khong xoa', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);
    vvT34PendingOrder($me, 'pending', now()->addMinutes(20));

    vvT34Delete($code)->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_PENDING_PAYMENT');

    expect($me->fresh()->anonymized_at)->toBeNull()
        ->and(OtpCode::query()->where('user_id', $me->id)->whereNotNull('consumed_at')->count())->toBe(0);
});

test('(f) khong can mat khau; gui them current_password khong anh huong', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);

    test()->postJson(vvApiUrl('/me/account/delete'), ['code' => $code, 'current_password' => 'sai'], vvWebHeaders())->assertOk();
    expect($me->fresh()->anonymized_at)->not->toBeNull();
});

test('(f) route yeu cau dang nhap hoc sinh', function () {
    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(401);
    test()->postJson(vvApiUrl('/me/account/delete'), ['code' => '123456'], vvWebHeaders())->assertStatus(401);
    test()->actingAs(User::factory()->teacher()->create())->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(403);
});

// ---------------------------------------------------------------------------------------------------------------------
// (g) sau khi xoá — qua HTTP thật (cookie/phiên)
// ---------------------------------------------------------------------------------------------------------------------

test('(g) phien hien tai /auth/me -> 401; tab khac cung phien cu -> 401 SESSION_REVOKED; dang nhap lai bang email/SDT cu -> 422 chung', function () {
    $otp = vvFakeOtp();
    vvPwStudent(['name' => 'Hoc Sinh Xoa']);
    $tab1 = new VvPwBrowser;
    $tab1->login()->assertOk();
    $oldCookie = $tab1->cookie;

    $tab1->call('POST', '/me/account/delete/otp')->assertStatus(202);
    $tab1->call('POST', '/me/account/delete', ['code' => $otp->lastCode()])->assertOk();

    // Phien hien tai: cookie moi la khach.
    $tab1->me()->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    expect($tab1->cookie)->not->toBe($oldCookie);

    // Tab khac van giu cookie cu.
    $tab2 = new VvPwBrowser;
    $tab2->cookie = $oldCookie;
    $tab2->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED')->assertJsonPath('message', 'Tài khoản đã được xoá.');

    $fresh = new VvPwBrowser;
    $fresh->login()->assertStatus(422)->assertJsonValidationErrors('login')
        ->assertJsonPath('errors.login.0', LoginService::GENERIC_FAILURE);
    $phone = new VvPwBrowser;
    $phone->call('POST', '/auth/login', ['login' => '0912345678', 'password' => 'mat-khau-cu-1'])->assertStatus(422);
});

test('(g) dang ky lai bang cung email + SDT -> 201; tai khoan cu van la dong rieng', function () {
    $otp = vvFakeOtp();
    $old = vvPwStudent();
    $tab = new VvPwBrowser;
    $tab->login()->assertOk();
    $tab->call('POST', '/me/account/delete/otp')->assertStatus(202);
    $tab->call('POST', '/me/account/delete', ['code' => $otp->lastCode()])->assertOk();

    vvResetClient();
    vvRegister(['email' => 'hs@example.com', 'phone' => '0912345678'])->assertCreated();

    $new = User::query()->where('email', 'hs@example.com')->firstOrFail();
    expect($new->id)->not->toBe($old->id)->and($new->phone)->toBe('0912345678')
        ->and($old->fresh()->email)->toBeNull();
});

test('(g) forgot voi email cu -> 202, khong gui ma', function () {
    $otp = vvFakeOtp();
    $old = vvPwStudent();
    $tab = new VvPwBrowser;
    $tab->login()->assertOk();
    $tab->call('POST', '/me/account/delete/otp')->assertStatus(202);
    $tab->call('POST', '/me/account/delete', ['code' => $otp->lastCode()])->assertOk();
    $sentBefore = count($otp->sent);

    vvResetClient();
    vvForgot()->assertStatus(202);

    expect($otp->sent)->toHaveCount($sentBefore)
        ->and(OtpCode::query()->where('user_id', $old->id)->count())->toBe(0);
});

test('(g) phien con song cua tai khoan da an danh bi middleware chan (phong thu): 401 SESSION_REVOKED', function () {
    $me = vvT34Student();
    User::query()->whereKey($me->id)->update(['anonymized_at' => now()]);
    vvActAsStudent(User::query()->findOrFail($me->id));

    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
    test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertStatus(401);
});

test('(g) tai khoan da an danh khong nhan duoc phien moi du dang nhap dua (bind duoi khoa)', function () {
    $me = vvT34Create();
    User::query()->whereKey($me->id)->update(['anonymized_at' => now()]);

    $request = Request::create('/');
    $store = app('session')->driver('array');
    $store->start();
    $request->setLaravelSession($store);

    expect(fn () => app(StudentSessionService::class)->bind($me->fresh(), $request))
        ->toThrow(ValidationException::class);
    expect($me->fresh()->current_session_id)->toBeNull();
});

test('(g) checkout cua tai khoan da an danh -> 401 SESSION_REVOKED, khong tao don', function () {
    $me = vvT34Create();
    $course = Course::factory()->published()->paid(100000)->create();
    Cart::factory()->state(['user_id' => $me->id])->withCourses([$course])->create();
    User::query()->whereKey($me->id)->update(['anonymized_at' => now()]);
    config(['payments.enabled_gateways' => ['fake'], 'features.paid_checkout' => true]);

    expect(fn () => app(CheckoutService::class)->checkout($me->fresh(), 100000, 'fake'))
        ->toThrow(DomainException::class, 'Tài khoản đã được xoá.');
    expect(Order::query()->where('user_id', $me->id)->count())->toBe(0);
});
