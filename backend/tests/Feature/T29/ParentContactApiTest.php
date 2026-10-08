<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Mail::fake());

test('(g) sai mat khau hoac thieu -> 422 current_password, khong doi gi', function () {
    $student = vvT29Student();

    vvT29Put(['current_password' => 'sai-mat-khau', 'parent_email' => 'moi@example.com'])
        ->assertStatus(422)->assertJsonValidationErrors('current_password')
        ->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không đúng.');

    test()->putJson(vvApiUrl('/me/parent-contact'), ['parent_email' => 'moi@example.com'], vvWebHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect($student->fresh()->parent_email)->toBe('phuhuynh@example.com')
        ->and(vvT29Notices())->toHaveCount(0);
});

test('(g) thieu key -> giu nguyen; null hoac chuoi rong -> xoa', function () {
    $student = vvT29Student();

    vvT29Put(['parent_phone' => '0922222222'])->assertOk();
    $fresh = $student->fresh();
    expect($fresh->parent_email)->toBe('phuhuynh@example.com')->and($fresh->parent_phone)->toBe('0922222222');

    vvT29Put(['parent_phone' => null])->assertOk()->assertJsonPath('has_phone', false)->assertJsonPath('phone_masked', null);
    expect($student->fresh()->parent_phone)->toBeNull()->and($student->fresh()->parent_email)->toBe('phuhuynh@example.com');

    vvT29Put(['parent_email' => ''])->assertOk()->assertJsonPath('has_email', false)->assertJsonPath('notices_enabled', false);
    expect($student->fresh()->parent_email)->toBeNull();
    expect(vvT29Notices())->toHaveCount(0); // xoa khong gui gi
});

test('(g) khong co key nao -> 422 field parent_email', function () {
    vvT29Student();

    vvT29Put([])->assertStatus(422)->assertJsonPath('errors.parent_email.0', 'Vui lòng nhập thông tin cần cập nhật.');
});

test('(g) doi email -> opt-out ve NULL va thu parent_contact_added toi dia chi MOI; khong bao dia chi cu', function () {
    $student = vvT29Student(['parent_notice_opt_out_at' => now()->subDay()]);

    vvT29Put(['parent_email' => 'Moi@Example.com'])
        ->assertOk()->assertJsonPath('notices_enabled', true)->assertJsonPath('notices_opted_out_at', null);

    expect($student->fresh()->parent_email)->toBe('moi@example.com')->and($student->fresh()->parent_notice_opt_out_at)->toBeNull();

    $notices = vvT29Notices();
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->kind)->toBe('parent_contact_added')
        ->and($notices->first()->hasTo('moi@example.com'))->toBeTrue()
        ->and($notices->first()->hasTo('phuhuynh@example.com'))->toBeFalse();
});

test('(g) cung email (khac hoa thuong) -> khong thu, giu opt-out, khong audit', function () {
    $student = vvT29Student(['parent_notice_opt_out_at' => now()->subDay()]);
    $before = AuditLog::query()->where('action', 'parent_contact.update')->where('subject_id', $student->id)->count();

    vvT29Put(['parent_email' => 'PhuHuynh@EXAMPLE.com'])->assertOk()->assertJsonPath('notices_enabled', false);

    expect(vvT29Notices())->toHaveCount(0)
        ->and($student->fresh()->parent_notice_opt_out_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'parent_contact.update')->where('subject_id', $student->id)->count())->toBe($before);
});

test('(g) audit changes chi co added|changed|removed|unchanged, khong chua @ hay chu so SDT', function () {
    $student = vvT29Student(['parent_email' => null, 'parent_phone' => '0911111111']);

    vvT29Put(['parent_email' => 'ph.moi@example.com', 'parent_phone' => '0933333333'])->assertOk();

    $log = AuditLog::query()->where('action', 'parent_contact.update')->where('subject_id', $student->id)->latest('id')->firstOrFail();
    expect($log->changes)->toBe(['parent_email_change' => 'added', 'parent_phone_change' => 'changed']);

    $json = json_encode($log->changes);
    expect($json)->not->toContain('@')->and(preg_match('/\d/', (string) $json))->toBe(0);
});

test('(g) them email moi tu trang -> thu; lien he trung email/SDT cua chinh hoc sinh -> 422', function () {
    $student = vvT29Student(['parent_email' => null, 'email' => 'minh@example.com', 'phone' => '0900000001']);

    vvT29Put(['parent_email' => 'MINH@example.com'])->assertStatus(422)
        ->assertJsonPath('errors.parent_email.0', 'Email phụ huynh phải khác email của bạn.');
    vvT29Put(['parent_phone' => '+84 900 000 001'])->assertStatus(422)
        ->assertJsonPath('errors.parent_phone.0', 'Số điện thoại phụ huynh phải khác số của bạn.');
    vvT29Put(['parent_email' => 'khong-hop-le'])->assertStatus(422)->assertJsonValidationErrors('parent_email');

    vvT29Put(['parent_email' => 'ph@example.com'])->assertOk();
    expect(vvT29Notices('parent_contact_added'))->toHaveCount(1);
});

test('(g) tai khoan chua xac thuc van sua duoc nhung KHONG gui thu (chong relay)', function () {
    $student = vvT29Student(['email_verified_at' => null, 'phone_verified_at' => null]);
    expect($student->isVerified())->toBeFalse();

    vvT29Put(['parent_email' => 'a@example.com'])->assertOk();
    expect($student->fresh()->parent_email)->toBe('a@example.com')->and(vvT29Notices())->toHaveCount(0);
});

test('(g) vuot 5 lan/gio -> 429', function () {
    vvT29Student();

    foreach (range(1, 5) as $i) {
        vvT29Put(['parent_email' => "ph{$i}@example.com"])->assertOk();
    }

    vvT29Put(['parent_email' => 'ph6@example.com'])->assertStatus(429);
    expect(User::query()->where('parent_email', 'ph6@example.com')->count())->toBe(0);
});

test('(g) cac guest/giao vien khong goi duoc; GET/PUT can dang nhap', function () {
    test()->getJson(vvApiUrl('/me/parent-contact'), vvWebHeaders())->assertUnauthorized();
    test()->putJson(vvApiUrl('/me/parent-contact'), ['current_password' => 'x', 'parent_email' => 'a@example.com'], vvWebHeaders())->assertUnauthorized();

    vvActAsStudent(User::factory()->teacher()->create());
    test()->getJson(vvApiUrl('/me/parent-contact'), vvWebHeaders())->assertForbidden();
});

test('(h) GET /me/parent-contact va /auth/me chi co ban che; email day du khong xuat hien trong body', function () {
    vvT29Student(['parent_email' => 'nguyenvanphuhuynh@gmail.com', 'parent_phone' => '0912345789']);

    $contact = test()->getJson(vvApiUrl('/me/parent-contact'), vvWebHeaders())->assertOk();
    $contact->assertExactJson([
        'email_masked' => 'n***@gmail.com',
        'phone_masked' => '*******789',
        'has_email' => true,
        'has_phone' => true,
        'notices_enabled' => true,
        'notices_opted_out_at' => null,
    ])->assertHeader('Cache-Control');
    expect($contact->headers->get('Cache-Control'))->toContain('no-store');

    $me = test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertOk();
    $me->assertJsonPath('parent_contact.email_masked', 'n***@gmail.com')
        ->assertJsonPath('parent_contact.phone_masked', '*******789')
        ->assertJsonPath('needs_policy_acceptance', false)
        ->assertJsonPath('parent_consent_status', 'not_required');

    foreach ([$contact->getContent(), $me->getContent()] as $body) {
        expect($body)->not->toContain('nguyenvanphuhuynh')->and($body)->not->toContain('0912345789')->and($body)->not->toContain('912345789');
    }
});

test('(h) hoc sinh khong co lien he -> has_* false, notices_enabled false; opt-out hien thoi diem +07:00', function () {
    vvT29Student(['parent_email' => null, 'parent_phone' => null]);
    test()->getJson(vvApiUrl('/me/parent-contact'), vvWebHeaders())->assertOk()
        ->assertJsonPath('email_masked', null)->assertJsonPath('has_email', false)->assertJsonPath('notices_enabled', false);

    $at = Carbon::parse('2026-10-08 03:00:00', 'UTC');
    $s = vvT29Student(['parent_notice_opt_out_at' => $at]);
    test()->getJson(vvApiUrl('/me/parent-contact'), vvWebHeaders())->assertOk()
        ->assertJsonPath('notices_enabled', false)
        ->assertJsonPath('notices_opted_out_at', $s->fresh()->parent_notice_opt_out_at->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String());
    expect($s->fresh()->parent_notice_opt_out_at->setTimezone('Asia/Ho_Chi_Minh')->format('P'))->toBe('+07:00');
});

test('(h) notices_enabled false khi co parent_notices tat', function () {
    vvT29Student();
    config(['features.parent_notices' => false]);

    test()->getJson(vvApiUrl('/me/parent-contact'), vvWebHeaders())->assertOk()->assertJsonPath('notices_enabled', false)->assertJsonPath('has_email', true);
});

test('needs_policy_acceptance: dong y phien ban cu -> true; da thu hoi khong tinh; dung phien ban -> false', function () {
    $student = vvT29Student();
    $insert = fn (string $type, string $version, ?string $revoked = null, string $at = '2026-09-01 00:00:00') => DB::table('consents')->insert([
        'user_id' => $student->id, 'type' => $type, 'policy_version' => $version, 'granted_by' => 'self',
        'channel' => 'web_form', 'granted_at' => $at, 'revoked_at' => $revoked, 'created_at' => $at,
    ]);

    $insert('terms', '2026-09');
    $insert('privacy_policy', '2026-10-tam');
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('needs_policy_acceptance', true);

    // Dong y moi hon cho terms o phien ban hien hanh -> het can chap nhan
    $insert('terms', '2026-10-tam', null, '2026-10-08 00:00:00');
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('needs_policy_acceptance', false);

    // Ban moi nhat bi thu hoi thi khong tinh, quay ve ban cu hon con hieu luc
    $insert('terms', '2026-11', '2026-11-02 00:00:00', '2026-11-01 00:00:00');
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('needs_policy_acceptance', false);
});
