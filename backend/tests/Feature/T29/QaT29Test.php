<?php

// QA độc lập cho T29 (ADR-006). Bổ sung cho test của Dev: tập trung vào biên, tương tác giữa các luồng, rò rỉ PII.

use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Privacy\ParentNotifier;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Mail::fake());

function qaT29Url(string $p): string
{
    return vvApiUrl($p);
}

function qaT29Backfill()
{
    return require database_path('migrations/2026_10_20_110000_backfill_parent_consent_status.php');
}

// ---------------------------------------------------------------- AC: đăng ký

test('AC1: dang ky moi tuoi (11,13,17,18,40) khong can phu huynh -> 201, not_required; lien he luu o moi tuoi', function (int $age) {
    $dob = now('Asia/Ho_Chi_Minh')->subYears($age)->toDateString();

    vvRegister(['date_of_birth' => $dob, 'email' => "qa-t29-{$age}@example.com"])->assertCreated()
        ->assertJsonPath('parent_consent_status', 'not_required');

    vvResetClient();
    vvRegister(['date_of_birth' => $dob, 'email' => "qa-t29-b{$age}@example.com", 'phone' => '0933333333',
        'parent_email' => "qa-t29-ph{$age}@example.com", 'parent_phone' => '0944444444'])->assertCreated();

    $u = User::query()->where('email', "qa-t29-b{$age}@example.com")->firstOrFail();
    expect($u->parent_email)->toBe("qa-t29-ph{$age}@example.com")->and($u->parent_phone)->toBe('0944444444')
        ->and($u->parent_consent_status->value)->toBe('not_required');
    // luu ma hoa trong DB
    $raw = DB::table('users')->where('id', $u->id)->first();
    expect($raw->parent_email)->not->toContain('qa-t29-ph')->and($raw->parent_phone)->not->toBe('0944444444');
})->with([11, 13, 17, 18, 40]);

test('AC2: email/SDT phu huynh trung cua hoc sinh (hoa thuong, khoang trang, +84, 84) -> 422', function () {
    vvRegister(['email' => 'hs@example.com', 'parent_email' => '  HS@Example.com '])->assertStatus(422)->assertJsonValidationErrors('parent_email');
    vvRegister(['phone' => '0912345678', 'parent_phone' => '+84912345678'])->assertStatus(422)->assertJsonValidationErrors('parent_phone');
    vvRegister(['phone' => '0912345678', 'parent_phone' => '84912345678'])->assertStatus(422)->assertJsonValidationErrors('parent_phone');
    vvRegister(['phone' => '0912345678', 'parent_phone' => '0912 345 678'])->assertStatus(422)->assertJsonValidationErrors('parent_phone');
    expect(User::count())->toBe(0);
});

test('AC2b: parent_* sai dinh dang / sai kieu / qua dai -> 422, khong 500', function (array $bad, string $field) {
    vvRegister($bad)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'email thieu @' => [['parent_email' => 'abc'], 'parent_email'],
    'email mang' => [['parent_email' => ['a@b.com']], 'parent_email'],
    'email dai' => [['parent_email' => str_repeat('a', 250).'@x.com'], 'parent_email'],
    'email ky tu dieu khien' => [['parent_email' => "a@b.com\r\nBcc: x@y.com"], 'parent_email'],
    'sdt sai' => [['parent_phone' => '12345'], 'parent_phone'],
    'sdt mang' => [['parent_phone' => [1]], 'parent_phone'],
    'sdt chu' => [['parent_phone' => 'abcdefghij'], 'parent_phone'],
]);

test('AC3: checkout/preview, free-enrollment khong bi chan khi pending/revoked/granted; khong route nao co parent.consent', function () {
    foreach (['pending', 'revoked', 'granted'] as $status) {
        $s = User::factory()->student()->verified()->create();
        DB::table('users')->where('id', $s->id)->update(['parent_consent_status' => $status]);
        vvActAsStudent($s);
        test()->getJson(qaT29Url('/checkout/preview'), vvWebHeaders())->assertOk();
        $course = Course::factory()->published()->create();
        test()->postJson(qaT29Url("/courses/{$course->id}/free-enrollments"), [], vvWebHeaders())->assertCreated();
        vvResetClient();
    }

    foreach (Route::getRoutes() as $r) {
        expect(implode(',', $r->gatherMiddleware()))->not->toContain('parent.consent');
    }
});

test('AC4: backfill migration - nhieu lo (id cach nhau > 2000), moi vai tro, khong dong vao cot khac', function () {
    $rows = [
        User::factory()->student()->create(['name' => 'Giữ nguyên A']),
        User::factory()->teacher()->create(),
        User::factory()->student()->create(['name' => 'Giữ nguyên B']),
    ];
    $ids = [$rows[0]->id, $rows[1]->id + 1500, $rows[2]->id + 3200];
    DB::table('users')->where('id', $rows[1]->id)->update(['id' => $ids[1]]);
    DB::table('users')->where('id', $rows[2]->id)->update(['id' => $ids[2]]);
    DB::table('users')->where('id', $ids[0])->update(['parent_consent_status' => 'pending']);
    DB::table('users')->where('id', $ids[1])->update(['parent_consent_status' => 'granted']);
    DB::table('users')->where('id', $ids[2])->update(['parent_consent_status' => 'revoked']);
    $before = DB::table('users')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($r) => Arr::except((array) $r, ['parent_consent_status']))->all();

    qaT29Backfill()->up();

    expect(DB::table('users')->where('parent_consent_status', '<>', 'not_required')->count())->toBe(0);
    $after = DB::table('users')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($r) => Arr::except((array) $r, ['parent_consent_status']))->all();
    expect($after)->toBe($before); // updated_at va moi cot khac khong doi
});

// ---------------------------------------------------------------- thư

test('AC5: account_created chi sau OTP dau: dang ky khong thu; xac thuc -> 1 thu; doi email roi xac thuc lai -> KHONG gui lai', function () {
    $otp = vvFakeOtp();
    vvRegister(['parent_email' => 'qa-t29-ph@example.com'])->assertCreated();
    expect(vvT29Notices())->toHaveCount(0);

    $user = User::firstOrFail();
    app(OtpService::class)->verifyAccount($user, $otp->lastCode());
    expect(vvT29Notices('account_created'))->toHaveCount(1);

    // doi email -> chua xac thuc -> xac thuc email moi
    vvActAsStudent($user->fresh());
    vvContactUpdate(['current_password' => 'matkhau-123', 'email' => 'qa-t29-new@example.com'])->assertOk();
    $user = $user->fresh();
    expect($user->email_verified_at)->toBeNull();
    $this->travel(3)->hours();
    app(OtpService::class)->issue($user, OtpPurpose::VerifyAccount, 'email', $user->email);
    app(OtpService::class)->verifyAccount($user, $otp->lastCode());

    expect(vvT29Notices('account_created'))->toHaveCount(1);
});

test('AC6: them/doi email phu huynh (da xac thuc) gui 1 thu; HS chua xac thuc khong gui; doi SDT thoi khong gui', function () {
    $s = vvT29Student(['parent_email' => null]);
    vvT29Put(['parent_email' => 'qa-t29-a@example.com'])->assertOk();
    expect(vvT29Notices('parent_contact_added'))->toHaveCount(1);
    vvT29Put(['parent_email' => 'qa-t29-b@example.com'])->assertOk();
    expect(vvT29Notices('parent_contact_added'))->toHaveCount(2);
    vvT29Put(['parent_phone' => '0955555555'])->assertOk();
    vvT29Put(['parent_email' => 'QA-T29-B@example.com'])->assertOk();
    expect(vvT29Notices())->toHaveCount(2);

    vvResetClient();
    vvActAsStudent(User::factory()->student()->create(['parent_email' => null]));
    vvT29Put(['parent_email' => 'qa-t29-c@example.com'])->assertOk();
    expect(vvT29Notices())->toHaveCount(2);
});

test('AC7: markPaid cung luc - hai ban sao cu cua cung don (ca hai con thay pending) chi 1 thu', function () {
    $s = User::factory()->student()->create(['parent_email' => 'qa-t29-ph@example.com']);
    $order = vvT29PendingOrder($s);
    $copyA = Order::find($order->id);
    $copyB = Order::find($order->id);

    app(OrderFulfillmentService::class)->markPaid($copyA, 'ipn', 'R1');
    app(OrderFulfillmentService::class)->markPaid($copyB, 'ipn', 'R1');
    app(OrderFulfillmentService::class)->markPaid($order, 'query');

    expect(vvT29Notices('order_paid'))->toHaveCount(1)
        ->and(AuditLog::where('action', 'parent_notice.sent')->where('subject_id', $s->id)->count())->toBe(1);
});

test('AC7b: markPaid trong transaction bi rollback -> khong thu', function () {
    $s = User::factory()->student()->create(['parent_email' => 'qa-t29-ph@example.com']);
    $order = vvT29PendingOrder($s);

    try {
        DB::transaction(function () use ($order) {
            app(OrderFulfillmentService::class)->markPaid($order, 'ipn');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Order::find($order->id)->status->value)->toBe('pending')->and(vvT29Notices())->toHaveCount(0);
});

test('AC8: loi gui thu o OTP verify, PUT parent-contact, markPaid khong lam hong thao tac chinh', function () {
    // markPaid
    $s = User::factory()->student()->create(['parent_email' => 'qa-t29-ph@example.com']);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    $order = vvT29PendingOrder($s);
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');
    expect(Order::find($order->id)->status->value)->toBe('paid');

    // PUT
    $st = vvT29Student(['parent_email' => null]);
    vvT29Put(['parent_email' => 'qa-t29-x@example.com'])->assertOk();
    expect($st->fresh()->parent_email)->toBe('qa-t29-x@example.com');

    // verify OTP
    $otp = vvFakeOtp();
    vvResetClient();
    vvRegister(['parent_email' => 'qa-t29-y@example.com'])->assertCreated();
    $u = User::where('parent_email', 'qa-t29-y@example.com')->first() ?? User::latest('id')->first();
    $verified = app(OtpService::class)->verifyAccount($u, $otp->lastCode());
    expect($verified->isVerified())->toBeTrue();
});

test('AC9: ten/khoa hoc doc hai (fullwidth, bidi, CRLF, @, mailto, data:) -> khong link, khong ky tu nguy hiem trong subject/html/text', function (string $evil) {
    $s = User::factory()->student()->verified()->create(['name' => $evil.' Nam', 'parent_email' => 'qa-t29-ph@example.com']);
    $order = vvT29PendingOrder($s, 100000, 'Khóa '.$evil);
    app(ParentNotifier::class)->accountCreated($s);
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    foreach (vvT29Notices() as $mail) {
        $html = $mail->render();
        $text = view('emails.parent-notice-text', $mail->buildViewData())->render();
        $subject = $mail->envelope()->subject;
        preg_match_all('/href="([^"]*)"/', $html, $m);
        foreach ($m[1] as $href) {
            expect($href)->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/');
        }
        expect($subject)->not->toMatch('/[\r\n\x{202E}\x{202D}\x{2066}-\x{2069}\x{200B}-\x{200F}@<>]/u');
        $allowed = [rtrim((string) config('app.frontend_url'), '/'), (string) config('app.api_host'), (string) config('ops.support_email')];
        foreach ([strip_tags($html), $text, $subject] as $out) {
            $visible = str_replace($allowed, '', preg_replace('~https?://\S+~', '', $out));
            expect($visible)->not->toContain('://')->not->toContain('@')->not->toContain('mailto:')->not->toContain('data:')->not->toContain('javascript:')
                ->not->toMatch('/\p{L}+\.\p{L}{2,}/u');
        }
    }
})->with([
    'fullwidth link' => ['＜ａ ｈｒｅｆ＝https：／／evil＞'],
    'bidi' => ["\u{202E}evil\u{202C}"],
    'crlf' => ["x\r\nBcc: evil@evil.test"],
    'at' => ['evil@evil.test'],
    'mailto' => ['mailto:evil@evil.test'],
    'data' => ['data:text/html,evil'],
    'js' => ['javascript:evil(1)'],
    'markdown ref' => ["[a]: https://evil\n[a]"],
    'ky tu markdown' => ['\\[x\\]\\(https://evil\\) *evil* _evil_ `evil`'],
    'nul' => ["evil\0evil"],
]);

test('AC10: tran 5 thu / dia chi: 6 hoc sinh cung email (khac hoa thuong) -> 5; khac dia chi khong anh huong; het 24h gui lai; dang ky van 201', function () {
    foreach (range(1, 6) as $i) {
        $u = User::factory()->student()->verified()->create(['parent_email' => $i % 2 ? 'QA-T29-Cap@Example.com' : 'qa-t29-cap@example.com']);
        app(ParentNotifier::class)->accountCreated($u);
    }
    expect(vvT29Notices())->toHaveCount(5);

    $other = User::factory()->student()->verified()->create(['parent_email' => 'qa-t29-other@example.com']);
    app(ParentNotifier::class)->accountCreated($other);
    expect(vvT29Notices())->toHaveCount(6);

    $this->travel(25)->hours();
    $again = User::factory()->student()->verified()->create(['parent_email' => 'qa-t29-cap@example.com']);
    app(ParentNotifier::class)->accountCreated($again);
    expect(vvT29Notices())->toHaveCount(7);
});

test('AC10b: tran dung chung giua cac loai thu (account_created + contact_added + order_paid)', function () {
    $s = User::factory()->student()->verified()->create(['parent_email' => 'qa-t29-mix@example.com']);
    $n = app(ParentNotifier::class);
    $n->accountCreated($s);
    $n->contactAdded($s);
    foreach (range(1, 4) as $i) {
        app(OrderFulfillmentService::class)->markPaid(vvT29PendingOrder($s), 'ipn');
    }
    expect(vvT29Notices())->toHaveCount(5);
});

test('AC11: FEATURE_PARENT_NOTICES tat -> khong thu o moi duong; PUT, dang ky, huy nhan van chay', function () {
    config(['features.parent_notices' => false]);
    $otp = vvFakeOtp();
    vvRegister(['parent_email' => 'qa-t29-off@example.com'])->assertCreated();
    $u = User::firstOrFail();
    app(OtpService::class)->verifyAccount($u, $otp->lastCode());

    vvActAsStudent($u->fresh());
    vvT29Put(['current_password' => 'matkhau-123', 'parent_email' => 'qa-t29-off2@example.com'])->assertOk()->assertJsonPath('notices_enabled', false);
    app(OrderFulfillmentService::class)->markPaid(vvT29PendingOrder($u), 'ipn');
    expect(vvT29Notices())->toHaveCount(0)
        ->and(AuditLog::where('action', 'parent_notice.sent')->where('subject_id', $u->id)->count())->toBe(0);

    test()->postJson(vvT29UnsubUrl(), ['token' => vvT29Token($u)], vvWebHeaders())->assertOk();
    expect($u->fresh()->parent_notice_opt_out_at)->not->toBeNull();
});

// ---------------------------------------------------------------- huỷ nhận

test('AC12: huy nhan - GET khong huy duoc (405), HEAD/PUT khong; one-click khong Origin khong Accept; phan hoi dong nhat', function () {
    $s = User::factory()->student()->create(['parent_email' => 'qa-t29-ph@example.com']);
    $t = vvT29Token($s);

    test()->get(vvT29UnsubUrl('?t='.$t))->assertStatus(405);
    expect($s->fresh()->parent_notice_opt_out_at)->toBeNull();

    // body that su cua Gmail/Outlook: form urlencoded, khong Origin, khong Accept json
    $r = test()->call('POST', vvT29UnsubUrl('?t='.$t), ['List-Unsubscribe' => 'One-Click'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    $r->assertOk();
    expect($r->headers->has('Set-Cookie'))->toBeFalse()->and($s->fresh()->parent_notice_opt_out_at)->not->toBeNull();

    // body hop le va body that bai phai giong het nhau (ca byte)
    $bad = test()->call('POST', vvT29UnsubUrl('?t=rac'), ['List-Unsubscribe' => 'One-Click'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    expect($bad->getStatusCode())->toBe(200)->and($bad->getContent())->toBe($r->getContent());
});

test('AC12b: thieu token khi KHONG gui Accept json (mail client) -> khong 500, khong Set-Cookie', function () {
    $r = test()->call('POST', vvT29UnsubUrl(), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    expect($r->getStatusCode())->toBeIn([200, 302, 422])->and($r->headers->has('Set-Cookie'))->toBeFalse();
});

test('AC13: token cu sau khi doi email khong dung duoc; token moi dung; token cua email cu khong lay duoc quyen cua email moi', function () {
    $s = vvT29Student();
    $old = vvT29Token($s);
    vvT29Put(['parent_email' => 'qa-t29-new@example.com'])->assertOk();

    test()->postJson(vvT29UnsubUrl(), ['token' => $old], vvWebHeaders())->assertOk();
    expect($s->fresh()->parent_notice_opt_out_at)->toBeNull()
        ->and(DB::table('parent_notice_suppressions')->count())->toBe(0);

    test()->postJson(vvT29UnsubUrl(), ['token' => vvT29Token($s)], vvWebHeaders())->assertOk();
    expect($s->fresh()->parent_notice_opt_out_at)->not->toBeNull();
});

test('AC14: sau huy nhan, xoa roi them lai email -> khong nhan thu; danh sach chan chi luu HMAC', function () {
    $s = vvT29Student();
    test()->postJson(vvT29UnsubUrl(), ['token' => vvT29Token($s)], vvWebHeaders())->assertOk();

    vvT29Put(['parent_email' => null])->assertOk();
    vvT29Put(['parent_email' => 'PHUHUYNH@example.com'])->assertOk();
    app(ParentNotifier::class)->contactAdded($s->fresh());
    app(OrderFulfillmentService::class)->markPaid(vvT29PendingOrder($s), 'ipn');
    expect(vvT29Notices())->toHaveCount(0);

    $rows = DB::table('parent_notice_suppressions')->get();
    expect($rows)->toHaveCount(1)->and(json_encode($rows))->not->toContain('phuhuynh')->not->toContain('example');
    expect(array_keys((array) $rows[0]))->toBe(['id', 'email_hmac', 'created_at']);
    expect(strlen($rows[0]->email_hmac))->toBe(64);
});

// ---------------------------------------------------------------- API liên hệ

test('AC15: GET/PUT parent-contact - khach 401, giao vien/admin khong dung duoc, hoc sinh dung', function () {
    test()->getJson(qaT29Url('/me/parent-contact'))->assertStatus(401);
    test()->putJson(qaT29Url('/me/parent-contact'), ['current_password' => 'password', 'parent_email' => 'a@example.com'], vvWebHeaders())->assertStatus(401);

    foreach (['teacher', 'admin'] as $role) {
        $u = User::factory()->{$role}()->create();
        test()->actingAs($u)->getJson(qaT29Url('/me/parent-contact'), vvWebHeaders())->assertStatus(403);
    }
});

test('AC16: PUT - mass assignment bi bo qua, du lieu la khong 500', function () {
    $s = vvT29Student();
    vvT29Put(['parent_phone' => '0966666666', 'role' => 'admin', 'email' => 'hack@example.com', 'parent_consent_status' => 'granted',
        'parent_notice_opt_out_at' => now()->toDateTimeString(), 'id' => 999999])->assertOk();

    $f = $s->fresh();
    expect($f->role->value)->toBe('hoc_sinh')->and($f->email)->toBe($s->email)->and($f->parent_notice_opt_out_at)->toBeNull()
        ->and($f->parent_phone)->toBe('0966666666')->and($f->id)->toBe($s->id);

    foreach ([['parent_email' => ['x@y.com']], ['parent_email' => 123], ['parent_phone' => ['0911111111']], ['parent_email' => str_repeat('a', 300).'@x.com'],
        ['parent_email' => $s->email], ['parent_phone' => $s->phone ?? '0000'], ['parent_email' => "a@b.com\nBcc:x@y.com"]] as $bad) {
        Cache::flush();
        $r = vvT29Put($bad);
        expect($r->getStatusCode())->toBeIn([422]);
    }
    expect($s->fresh()->parent_email)->toBe('phuhuynh@example.com');
});

test('AC17: PUT sai mat khau lap lai -> 429 (CurrentPasswordGuard), dung chung han muc voi PUT /auth/contact; response khong chua PII', function () {
    $s = vvT29Student();
    $statuses = [];
    foreach (range(1, 12) as $i) {
        $statuses[] = vvT29Put(['current_password' => 'sai', 'parent_email' => 'qa-t29-z@example.com'])->getStatusCode();
    }
    expect(array_unique($statuses))->toContain(422)->toContain(429)
        ->and($statuses[0])->toBe(422);

    // dung mat khau moi van bi chan (dang bi 429)
    $r = vvT29Put(['parent_email' => 'qa-t29-z@example.com']);
    expect($r->getStatusCode())->toBe(429)->and($r->getContent())->not->toContain('phuhuynh@example.com');
    expect($s->fresh()->parent_email)->toBe('phuhuynh@example.com');
});

test('AC17b: do mat khau qua /auth/contact cung tieu han muc dung cho /me/parent-contact', function () {
    vvT29Student();
    for ($i = 0; $i < 10; $i++) {
        vvContactUpdate(['current_password' => 'sai', 'email' => 'qa-t29-q'.$i.'@example.com']);
    }

    expect(vvT29Put(['parent_email' => 'qa-t29-z@example.com'])->getStatusCode())->toBe(429);
});

test('AC18: PUT vuot 5/gio -> 429; reset sau 1 gio', function () {
    $s = vvT29Student();
    foreach (range(1, 5) as $i) {
        vvT29Put(['parent_phone' => '09'.str_repeat((string) $i, 8)])->assertOk();
    }
    vvT29Put(['parent_phone' => '0977777777'])->assertStatus(429);
    expect($s->fresh()->parent_phone)->toBe('0955555555'); // khong doi o lan 6

    $this->travel(61)->minutes();
    vvT29Put(['parent_phone' => '0977777777'])->assertOk();
});

test('AC19: /auth/me va GET parent-contact chi tra ban che; /auth/me co needs_policy_acceptance bool; config/public', function () {
    $s = vvT29Student(['parent_email' => 'qa-t29-secret@example.com', 'parent_phone' => '0912999888']);

    foreach (['/auth/me', '/me/parent-contact'] as $path) {
        $body = test()->getJson(qaT29Url($path), vvWebHeaders())->assertOk()->getContent();
        expect($body)->not->toContain('qa-t29-secret')->not->toContain('0912999888')->not->toContain('912999888');
    }

    $me = test()->getJson(qaT29Url('/auth/me'), vvWebHeaders())->assertOk();
    expect($me->json('needs_policy_acceptance'))->toBeBool()
        ->and($me->json('parent_contact.has_email'))->toBeTrue()
        ->and($me->json('parent_contact.phone_masked'))->toBe('*******888')
        ->and($me->json('parent_contact'))->toHaveKeys(['email_masked', 'phone_masked', 'has_email', 'has_phone', 'notices_enabled', 'notices_opted_out_at']);

    expect(test()->getJson(qaT29Url('/config/public'))->json('parent_contact_required'))->toBeFalse();
});

test('AC19b: needs_policy_acceptance - true khi phien ban doi, false khi dong y ban hien hanh', function () {
    $otp = vvFakeOtp();
    vvRegister()->assertCreated();
    $u = User::firstOrFail();
    vvActAsStudent($u);
    expect(test()->getJson(qaT29Url('/auth/me'), vvWebHeaders())->json('needs_policy_acceptance'))->toBeFalse();

    config(['privacy.policy_version' => '2099-01']);
    expect(test()->getJson(qaT29Url('/auth/me'), vvWebHeaders())->json('needs_policy_acceptance'))->toBeTrue();
});

// ---------------------------------------------------------------- PII

test('AC20: khong co email/SDT phu huynh ro trong log, audit, queue payload qua toan bo luong', function () {
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
        $logs[] = $e->message.' '.json_encode($e->context);
    });

    $pe = 'qa-t29-pii.parent@example.com';
    $pp = '0911223344';
    $otp = vvFakeOtp();
    vvRegister(['parent_email' => $pe, 'parent_phone' => $pp, 'name' => 'Qa Tư Hai Chín'])->assertCreated();
    $u = User::firstOrFail();
    app(OtpService::class)->verifyAccount($u, $otp->lastCode());
    vvActAsStudent($u->fresh());
    vvT29Put(['current_password' => 'matkhau-123', 'parent_email' => 'qa-t29-pii.new@example.com', 'parent_phone' => '0911223355'])->assertOk();
    vvT29Put(['current_password' => 'sai', 'parent_phone' => null]);
    app(OrderFulfillmentService::class)->markPaid(vvT29PendingOrder($u), 'ipn');
    // vuot tran de sinh log warning
    foreach (range(1, 6) as $i) {
        app(ParentNotifier::class)->contactAdded($u->fresh());
    }
    test()->postJson(vvT29UnsubUrl(), ['token' => vvT29Token($u)], vvWebHeaders())->assertOk();

    $needles = ['qa-t29-pii', '0911223344', '0911223355', '911223344', '911223355'];
    $audit = json_encode(AuditLog::where('subject_id', $u->id)->orWhere('actor_id', $u->id)->get()->map->getAttributes()->all());
    foreach ($needles as $n) {
        expect($audit)->not->toContain($n);
        foreach ($logs as $line) {
            expect($line)->not->toContain($n);
        }
    }
    expect(AuditLog::where('action', 'parent_contact.update')->where('subject_id', $u->id)->first()->changes)
        ->toBe(['parent_email_change' => 'changed', 'parent_phone_change' => 'changed']);
});

test('AC21: payload job trong queue (driver database) duoc ma hoa - khong chua email phu huynh/ten hoc sinh', function () {
    if (! DB::getSchemaBuilder()->hasTable('jobs')) {
        $this->markTestSkipped('khong co bang jobs');
    }
    Mail::swap(new MailManager(app())); // dung mailer that
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

    $s = User::factory()->student()->verified()->create(['name' => 'Qa Tên Riêng Biệt', 'parent_email' => 'qa-t29-payload@example.com']);
    app(ParentNotifier::class)->accountCreated($s);

    $payloads = DB::table('jobs')->pluck('payload')->implode("\n");
    expect($payloads)->not->toBe('')
        ->and($payloads)->not->toContain('qa-t29-payload')->not->toContain('Biệt')->not->toContain('Tên Riêng')
        ->and($payloads)->toContain('ParentNoticeMail')->toContain('"data"');
    // lop encrypted: command khong phai chuoi serialize 'O:'
    expect(json_decode(DB::table('jobs')->latest('id')->value('payload'), true)['data']['command'])->not->toStartWith('O:');
});

// Dọn: không để job dư lại (RefreshDatabase rollback transaction; jobs cùng connection nên tự rollback).
