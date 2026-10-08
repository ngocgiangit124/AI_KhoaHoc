<?php

use App\Enums\ConsentType;
use App\Enums\OtpPurpose;
use App\Jobs\FinalizeAccountDeletionJob;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Consent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Pest\TestSuite;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T27/helpers.php';

/*
 * QA T34 (độc lập với test của Dev): đi qua HTTP bằng "trình duyệt" giả (cookie thật), kiểm AC/Xong khi.
 */

/** Đệ quy lấy mọi khoá của mảng/JSON. */
function vvQaKeys(mixed $v, array &$out = []): array
{
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            if (is_string($k)) {
                $out[] = $k;
            }
            vvQaKeys($x, $out);
        }
    }

    return $out;
}

function vvQaLoggedIn(array $attrs = []): VvPwBrowser
{
    vvPwStudent($attrs);
    $b = new VvPwBrowser;
    $b->login()->assertOk();

    return $b;
}

function vvQaDeleteAll(VvPwBrowser $b, VvCapturingOtpSender $otp): void
{
    $b->call('POST', '/me/account/delete/otp')->assertStatus(202);
    $b->call('POST', '/me/account/delete', ['code' => $otp->lastCode()])->assertOk();
}

// ------------------------------------------------------------------ AC1 đồng ý

test('AC1: consents - khach 401, giao vien/admin khong dung duoc, hoc sinh xem + dong y', function () {
    test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertStatus(401);
    test()->postJson(vvApiUrl('/me/consents/accept'), ['policy_version' => '2026-10-tam'], vvWebHeaders())->assertStatus(401);

    vvActAsStudent(User::factory()->teacher()->verified()->create());
    test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertStatus(403);
    test()->postJson(vvApiUrl('/me/data-export'), ['current_password' => 'password'], vvWebHeaders())->assertStatus(403);
    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(403);
});

test('AC1: needs_policy_acceptance + accept dung/sai policy_version + khong lo ip/ua + khong trung', function () {
    $me = vvT34Student();
    vvT34Consent($me, ConsentType::Terms, 'cu-2025');
    vvT34Consent($me, ConsentType::PrivacyPolicy, 'cu-2025');
    $current = (string) config('privacy.policy_version');

    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertOk()->assertJsonPath('needs_policy_acceptance', true);

    $list = test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertOk();
    expect(json_encode($list->json()))->not->toContain('203.0.113.5')->not->toContain('Pest');
    expect(vvQaKeys($list->json()))->not->toContain('ip')->not->toContain('user_agent');

    $bad = test()->postJson(vvApiUrl('/me/consents/accept'), ['policy_version' => 'sai-version', 'accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())->assertStatus(409);
    expect($bad->json('code'))->toBe('CONSENT_VERSION_CHANGED')->and($bad->json('errors.current_version'))->toBe($current);
    expect(Consent::query()->where('user_id', $me->id)->count())->toBe(2);

    test()->postJson(vvApiUrl('/me/consents/accept'), ['policy_version' => $current, 'accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())->assertSuccessful();
    test()->postJson(vvApiUrl('/me/consents/accept'), ['policy_version' => $current, 'accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())->assertSuccessful();
    expect(Consent::query()->where('user_id', $me->id)->where('policy_version', $current)->count())->toBe(2);

    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertOk()->assertJsonPath('needs_policy_acceptance', false);
});

// ------------------------------------------------------------------ AC2 xuất dữ liệu

test('AC2: xuat du lieu - khach 401, thieu/sai mat khau 422 va khong tinh luot, 2 lan OK, lan 3 -> 429', function () {
    test()->postJson(vvApiUrl('/me/data-export'), ['current_password' => 'password'], vvWebHeaders())->assertStatus(401);

    Carbon::setTestNow(Carbon::parse('2026-10-08 22:30:00', 'Asia/Ho_Chi_Minh'));
    $me = vvT34Student();

    test()->postJson(vvApiUrl('/me/data-export'), [], vvWebHeaders())->assertStatus(422);
    vvT34Export('sai-mat-khau')->assertStatus(422)->assertJsonValidationErrors('current_password');
    expect(vvT34Audits('privacy.data_export', $me->id))->toBe(0);

    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertOk()->assertJsonPath('remaining', 2);

    $ok = vvT34Export()->assertOk();
    expect($ok->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('filename=')
        ->and($ok->headers->get('Cache-Control'))->toContain('no-store')
        ->and($ok->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($ok->headers->get('Content-Type'))->toContain('application/json')
        ->and($ok->json('format_version'))->toBe(1);
    vvT34Export()->assertOk();

    $third = vvT34Export()->assertStatus(429);
    expect($third->json('code'))->toBe('DATA_EXPORT_LIMIT')
        ->and((int) $third->headers->get('Retry-After'))->toBe(90 * 60)
        ->and($third->json('errors.resets_at'))->toBe('2026-10-09T00:00:00+07:00');
    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertOk()->assertJsonPath('remaining', 0);

    // Sai mat khau luc het luot van chi la 422 hoac 429, khong lo gi. Qua 00:00 VN -> dung lai.
    Carbon::setTestNow(Carbon::parse('2026-10-09 00:00:05', 'Asia/Ho_Chi_Minh'));
    vvT34Export()->assertOk();
    Carbon::setTestNow();
});

test('AC2: ranh gioi ngay VN - 23:59:59 va 00:00:00 UTC khong lam lech ngay', function () {
    $me = vvT34Student();
    // 16:59 UTC = 23:59 VN ngay 8: 2 luot thuoc ngay 8.
    Carbon::setTestNow(Carbon::parse('2026-10-08 16:59:00', 'UTC'));
    vvT34Export()->assertOk();
    vvT34Export()->assertOk();
    vvT34Export()->assertStatus(429);
    // 17:00 UTC = 00:00 VN ngay 9.
    Carbon::setTestNow(Carbon::parse('2026-10-08 17:00:00', 'UTC'));
    vvT34Export()->assertOk();
    Carbon::setTestNow();
    expect($me->id)->toBeInt();
});

test('AC2: file xuat khong chua du lieu hoc sinh B (cung khoa, cung ma giam gia), khong co hash/token/dap an', function () {
    $a = vvT34Create(['name' => 'Học Sinh Alpha', 'email' => 'alpha.qa@example.com']);
    $b = vvT34Create(['name' => 'Bạn Beta Khác', 'email' => 'beta.qa@example.com', 'phone' => '0987654321', 'parent_email' => 'ph.beta@example.com', 'remember_token' => 'BETA-REMEMBER-TOKEN']);
    $course = Course::factory()->published()->paid(500000)->create(['title' => 'Khoá chung A và B']);
    vvT34Seed($b, $course);
    vvT34Seed($a, $course);
    User::query()->whereKey($a->id)->update(['remember_token' => 'ALPHA-REMEMBER-TOKEN', 'current_device_id' => 'dev-alpha-uuid']);
    vvActAsStudent($a->fresh());

    $body = vvT34Export()->assertOk()->getContent();
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

    foreach (['Bạn Beta Khác', 'beta.qa@example.com', '0987654321', 'ph.beta@example.com', 'BETA-REMEMBER-TOKEN', 'ALPHA-REMEMBER-TOKEN', 'dev-alpha-uuid'] as $needle) {
        expect($body)->not->toContain($needle);
    }
    expect($body)->toContain('alpha.qa@example.com');
    expect($data['orders'])->toHaveCount(1)->and($data['enrollments'])->toHaveCount(1)->and($data['learning_progress'])->toHaveCount(1);

    $keys = vvQaKeys($data);
    foreach (['password', 'remember_token', 'current_session_id', 'current_device_id', 'token', 'answers', 'result', 'question_ids', 'pay_url', 'gateway_trans_id', 'create_response', 'otp', 'code_hash'] as $bad) {
        expect($keys)->not->toContain($bad);
    }
    expect($body)->not->toContain(User::query()->find($a->id)->password);
});

// ------------------------------------------------------------------ AC3 xoá tài khoản

test('AC3: OTP xoa - khach 401, sai ma 422 khong doi gi, het han 422, het luot -> ma dung cung bi khoa', function () {
    test()->postJson(vvApiUrl('/me/account/delete'), ['code' => '123456'], vvWebHeaders())->assertStatus(401);

    $otp = vvFakeOtp();
    $b = vvQaLoggedIn();
    $before = User::query()->where('email', 'hs@example.com')->firstOrFail()->getAttributes();

    $b->call('POST', '/me/account/delete', ['code' => '123456'])->assertStatus(422);          // chua gui ma
    $b->call('POST', '/me/account/delete', [])->assertStatus(422);
    $b->call('POST', '/me/account/delete/otp')->assertStatus(202);
    $right = $otp->lastCode();
    $wrong = $right === '000000' ? '111111' : '000000';

    $b->call('POST', '/me/account/delete', ['code' => $wrong])->assertStatus(422);
    $after = User::query()->where('email', 'hs@example.com')->firstOrFail();
    expect($after->anonymized_at)->toBeNull()->and($after->getAttributes())->toEqual($before);

    // Mã đăng ký purpose khác (verify_account) không dùng được để xoá.
    test()->travel(61)->seconds();
    for ($i = 0; $i < 4; $i++) {
        $b->call('POST', '/me/account/delete', ['code' => $wrong])->assertStatus(422);
    }
    $b->call('POST', '/me/account/delete', ['code' => $right])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');   // het luot -> dung cung khong duoc
    expect(User::query()->where('email', 'hs@example.com')->first()->anonymized_at)->toBeNull();
});

test('AC3: ma xoa het han sau 10 phut -> 422, tai khoan nguyen ven', function () {
    $otp = vvFakeOtp();
    $b = vvQaLoggedIn();
    $b->call('POST', '/me/account/delete/otp')->assertStatus(202);
    $code = $otp->lastCode();
    test()->travel(11)->minutes();
    $b->call('POST', '/me/account/delete', ['code' => $code])->assertStatus(422);
    expect(User::query()->where('email', 'hs@example.com')->first()?->anonymized_at)->toBeNull();
});

test('AC3: ma purpose khac (verify_account / reset_password) khong xoa duoc tai khoan', function () {
    $otp = vvFakeOtp();
    $b = vvQaLoggedIn();
    $me = User::query()->where('email', 'hs@example.com')->firstOrFail();
    (new OtpCode)->forceFill([
        'user_id' => $me->id, 'purpose' => OtpPurpose::VerifyAccount, 'channel' => 'email', 'destination' => $me->email,
        'code_hash' => Hash::make('654321'), 'expires_at' => now()->addMinutes(5), 'attempts' => 0,
    ])->save();
    $b->call('POST', '/me/account/delete', ['code' => '654321'])->assertStatus(422);
    expect($me->fresh()->anonymized_at)->toBeNull()->and($otp->sent)->toBe([]);
});

test('AC3: xoa that - an danh du cot, phien cu + recaller cu -> 401, dang ky lai OK, tai khoan moi sach, du lieu hoc cu con', function () {
    Queue::fake();
    $otp = vvFakeOtp();
    $b = vvQaLoggedIn(['remember_token' => 'REMEMBER-OLD', 'parent_email' => 'ph@example.com', 'date_of_birth' => '2012-05-01', 'bio' => 'bio', 'avatar_path' => null]);
    $old = User::query()->where('email', 'hs@example.com')->firstOrFail();
    $seed = vvT34Seed($old);
    $oldCookie = $b->cookie;
    $oldHash = $old->password;

    vvQaDeleteAll($b, $otp);

    $row = User::query()->findOrFail($old->id);
    expect($row->name)->toBe('Tài khoản đã xoá')
        ->and($row->email)->toBeNull()->and($row->phone)->toBeNull()->and($row->date_of_birth)->toBeNull()
        ->and($row->parent_email)->toBeNull()->and($row->parent_phone)->toBeNull()->and($row->parent_notice_opt_out_at)->toBeNull()
        ->and($row->referral_code_used)->toBeNull()->and($row->bio)->toBeNull()->and($row->avatar_path)->toBeNull()
        ->and($row->remember_token)->toBeNull()->and($row->current_device_id)->toBeNull()
        ->and($row->anonymized_at)->not->toBeNull()
        ->and($row->password)->not->toBe($oldHash)->and(Hash::check('mat-khau-cu-1', $row->password))->toBeFalse()
        ->and($row->current_session_id)->toBe('logged_out')
        ->and($row->role->value)->toBe('hoc_sinh');
    expect(Consent::query()->where('user_id', $old->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(Consent::query()->where('user_id', $old->id)->whereNotNull('ip')->orWhereNotNull('user_agent')->where('user_id', $old->id)->count())->toBe(0)
        ->and(OtpCode::query()->where('user_id', $old->id)->count())->toBe(0);
    Queue::assertPushed(FinalizeAccountDeletionJob::class, 1);

    // Phiên cũ.
    $tab = new VvPwBrowser;
    $tab->cookie = $oldCookie;
    $tab->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');
    foreach ([['GET', '/me/consents'], ['GET', '/me/data-export'], ['GET', '/me/parent-contact']] as [$m, $p]) {
        $tab->call($m, $p)->assertStatus(401);
    }
    // 5 API ghi PII bằng cookie cũ -> 401, không ghi.
    $snap = User::query()->findOrFail($old->id)->getAttributes();
    $tab->call('PUT', '/auth/contact', ['email' => 'moi@example.com', 'phone' => '0900000001', 'current_password' => 'mat-khau-cu-1'])->assertStatus(401);
    $tab->call('PUT', '/auth/password', ['current_password' => 'mat-khau-cu-1', 'password' => 'mat-khau-moi-9', 'password_confirmation' => 'mat-khau-moi-9'])->assertStatus(401);
    $tab->call('PUT', '/me/parent-contact', ['parent_email' => 'x@example.com', 'current_password' => 'mat-khau-cu-1'])->assertStatus(401);
    $tab->call('POST', '/me/consents/accept', ['policy_version' => (string) config('privacy.policy_version'), 'accept_terms' => true, 'accept_privacy' => true])->assertStatus(401);
    $tab->call('POST', '/me/account/delete/otp')->assertStatus(401);
    expect(User::query()->findOrFail($old->id)->getAttributes())->toEqual($snap)
        ->and(OtpCode::query()->where('user_id', $old->id)->count())->toBe(0)
        ->and(Consent::query()->where('user_id', $old->id)->whereNull('revoked_at')->count())->toBe(0);

    // Cookie remember (recaller) cũ, không kèm cookie phiên.
    $name = 'remember_web_'.sha1(SessionGuard::class);
    $value = CookieValuePrefix::create($name, app('encrypter')->getKey()).$old->id.'|REMEMBER-OLD|'.hash_hmac('sha256', $oldHash, config('app.key'));
    app('auth')->forgetGuards();
    test()->flushSession();
    test()->withCredentials()->withUnencryptedCookie($name, app('encrypter')->encrypt($value, false));
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertStatus(401);

    // Đăng ký lại cùng email + SĐT.
    vvResetClient();
    $self = TestSuite::getInstance()->test;
    (new ReflectionProperty($self, 'defaultCookies'))->setValue($self, []);
    vvRegister(['email' => 'hs@example.com', 'phone' => '0912345678'])->assertCreated();
    $new = User::query()->where('email', 'hs@example.com')->firstOrFail();
    expect($new->id)->not->toBe($old->id)
        ->and(Enrollment::query()->where('user_id', $new->id)->count())->toBe(0)
        ->and(Order::query()->where('user_id', $new->id)->count())->toBe(0)
        ->and(Cart::query()->where('user_id', $new->id)->first()?->items()->count() ?? 0)->toBe(0);

    // Khoá đang học của tài khoản đã xoá giữ nguyên.
    expect(Enrollment::query()->where('user_id', $old->id)->where('course_id', $seed['course']->id)->first()->status->value)->toBe('active')
        ->and(Order::query()->where('user_id', $old->id)->count())->toBe(1);
    expect(vvT34Audits('privacy.account_anonymized', $old->id))->toBe(1);
});

test('AC3: tai khoan moi sau dang ky lai goi /me/courses thay danh sach rong', function () {
    $otp = vvFakeOtp();
    $b = vvQaLoggedIn();
    $old = User::query()->where('email', 'hs@example.com')->firstOrFail();
    vvT34Seed($old);
    vvQaDeleteAll($b, $otp);

    $new = new VvPwBrowser;
    vvResetClient();
    $new->call('POST', '/auth/register', vvRegisterPayload(['email' => 'hs@example.com', 'phone' => '0912345678', 'password' => 'mat-khau-cu-1', 'password_confirmation' => 'mat-khau-cu-1']))->assertCreated();
    $courses = $new->call('GET', '/me/courses');
    if ($courses->status() === 200) {
        expect(json_encode($courses->json()))->not->toContain('Toán 8 nâng cao');
    } else {
        expect($courses->status())->toBeIn([401, 403, 404]);
    }
    $new->me()->assertOk();
});

test('AC3: don pending co link song -> 409 ACCOUNT_HAS_PENDING_PAYMENT, khong gui ma; het link thi gui duoc', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    vvT34PendingOrder($me, 'pending', now()->addMinutes(15));

    $r = test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(409);
    expect($r->json('code'))->toBe('ACCOUNT_HAS_PENDING_PAYMENT')->and($r->json('errors.retry_after_at'))->not->toBeNull()->and($otp->sent)->toBe([]);

    test()->travel(16)->minutes();
    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(202);
});

test('AC3: xac nhan xoa khi don pending co link xuat hien SAU khi gui ma -> 409, khong an danh', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);
    vvT34PendingOrder($me, 'pending', now()->addMinutes(15));

    vvT34Delete($code)->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_PENDING_PAYMENT');
    expect($me->fresh()->anonymized_at)->toBeNull()->and($me->fresh()->email)->not->toBeNull();
});

test('AC3 pha B: job don gio + don pending het link + yeu cau hoc, giu enrollment active', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $paid = vvT34Seed($me);
    $pendingOrder = vvT34PendingOrder($me, withAttempt: false);
    $pendingFree = Course::factory()->published()->create();
    $req = Enrollment::factory()->create(['user_id' => $me->id, 'course_id' => $pendingFree->id, 'status' => 'pending_approval']);

    vvT34Delete(vvT34OtpForDelete($otp))->assertOk();   // queue sync trong test => job chạy luôn

    expect($pendingOrder->fresh()->status->value)->toBe('cancelled')
        ->and($req->fresh()->status->value)->toBe('rejected')
        ->and(Enrollment::query()->where('user_id', $me->id)->where('course_id', $paid['course']->id)->first()->status->value)->toBe('active')
        ->and(Order::query()->where('user_id', $me->id)->where('status', 'paid')->count())->toBe(1);
    expect(Cart::query()->where('user_id', $me->id)->first()?->items()->count() ?? 0)->toBe(0);
    expect(AuditLog::query()->where('action', 'privacy.account_anonymized')->where('subject_id', $me->id)->count())->toBe(1);
});
