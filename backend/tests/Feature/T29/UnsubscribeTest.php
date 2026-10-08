<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Privacy\ParentNoticeToken;
use App\Services\Privacy\ParentNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

const VV_T29_UNSUB_MESSAGE = 'Đã ghi nhận. Bạn sẽ không nhận thêm thông báo từ VitaminVui về học sinh này.';

beforeEach(fn () => Mail::fake());

function vvT29Unsub(string $token)
{
    return test()->postJson(vvT29UnsubUrl(), ['token' => $token], ['Origin' => config('app.frontend_url')]);
}

test('(i) token dung -> dat parent_notice_opt_out_at, audit, lan gui sau bi bo', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);

    vvT29Unsub(vvT29Token($student))->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);

    expect($student->fresh()->parent_notice_opt_out_at)->not->toBeNull();
    $log = AuditLog::query()->where('action', 'parent_notice.opt_out')->where('subject_id', $student->id)->firstOrFail();
    expect($log->actor_id)->toBeNull()->and(json_encode($log->getAttributes()))->not->toContain('ph@example');

    app(ParentNotifier::class)->accountCreated($student->fresh());
    expect(vvT29Notices())->toHaveCount(0);
});

test('(i) token sai / email phu huynh da doi / da an danh / goi lan 2 -> 200 cung body, DB khong doi', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $token = vvT29Token($student);

    // token sai (chu ky)
    vvT29Unsub($student->id.'.'.str_repeat('A', 43))->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);
    // rac / khong ton tai
    vvT29Unsub('khong-phai-token')->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);
    vvT29Unsub('99999999.'.str_repeat('A', 43))->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);
    expect($student->fresh()->parent_notice_opt_out_at)->toBeNull();

    // email phu huynh da doi -> token cu mat hieu luc
    $student->forceFill(['parent_email' => 'khac@example.com'])->save();
    vvT29Unsub($token)->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);
    expect($student->fresh()->parent_notice_opt_out_at)->toBeNull();

    // da an danh
    $newToken = vvT29Token($student);
    $student->forceFill(['anonymized_at' => now()])->save();
    vvT29Unsub($newToken)->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);
    expect($student->fresh()->parent_notice_opt_out_at)->toBeNull();

    // goi lan 2: dau thoi gian khong bi ghi de, khong audit them
    $other = User::factory()->student()->create(['parent_email' => 'ph2@example.com']);
    $t = vvT29Token($other);
    vvT29Unsub($t)->assertOk();
    $first = $other->fresh()->parent_notice_opt_out_at;
    $audits = AuditLog::query()->where('action', 'parent_notice.opt_out')->where('subject_id', $other->id)->count();

    $this->travel(5)->minutes();
    vvT29Unsub($t)->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);

    expect($other->fresh()->parent_notice_opt_out_at->equalTo($first))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'parent_notice.opt_out')->where('subject_id', $other->id)->count())->toBe($audits);
});

test('(i) one-click: form List-Unsubscribe=One-Click + ?t= -> 200 va dat opt-out; khong Set-Cookie, khong can CSRF/Origin', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $token = vvT29Token($student);

    $response = test()->post(vvT29UnsubUrl('?t='.$token), ['List-Unsubscribe' => 'One-Click'], ['Accept' => 'application/json']);

    $response->assertOk()->assertExactJson(['message' => VV_T29_UNSUB_MESSAGE]);
    expect($response->headers->getCookies())->toBe([])
        ->and($student->fresh()->parent_notice_opt_out_at)->not->toBeNull();
});

test('(i) response (ca thanh cong lan that bai) khong co Set-Cookie du request co Origin cua FE', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);

    foreach ([vvT29Token($student), 'rac'] as $token) {
        $r = vvT29Unsub($token)->assertOk();
        expect($r->headers->getCookies())->toBe([])->and($r->headers->has('Set-Cookie'))->toBeFalse();
    }
});

test('(i) thieu token / khong phai chuoi / > 512 ky tu -> 422 field token', function () {
    test()->postJson(vvT29UnsubUrl(), [], ['Origin' => config('app.frontend_url')])->assertStatus(422)->assertJsonValidationErrors('token');
    test()->postJson(vvT29UnsubUrl(), ['token' => ['a']], ['Origin' => config('app.frontend_url')])->assertStatus(422)->assertJsonValidationErrors('token');
    vvT29Unsub(str_repeat('a', 513))->assertStatus(422)->assertJsonValidationErrors('token');
});

test('(i) 31 lan/gio/IP -> 429', function () {
    foreach (range(1, 30) as $i) {
        vvT29Unsub('rac')->assertOk();
    }

    vvT29Unsub('rac')->assertStatus(429);
});

test('token: khong dung chung giua hoc sinh, khong doan duoc bang id khac, kiem hash_equals voi email hien tai', function () {
    config(['privacy.notice_token_key' => null]); // kiem ca nhanh dan xuat tu APP_KEY
    $a = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $b = User::factory()->student()->create(['parent_email' => 'ph@example.com']);

    [, $sigA] = explode('.', vvT29Token($a));
    vvT29Unsub($b->id.'.'.$sigA)->assertOk();
    expect($b->fresh()->parent_notice_opt_out_at)->toBeNull();

    // Khoa HMAC lay tu cau hinh: doi khoa -> token cu vo hieu
    $token = vvT29Token($a);
    config(['privacy.notice_token_key' => 'khoa-khac-hoan-toan']);
    expect(ParentNoticeToken::verify($token))->toBeNull();

    // Email phu huynh khong phan biet hoa thuong
    config(['privacy.notice_token_key' => null]);
    $a->forceFill(['parent_email' => 'PH@Example.com'])->save();
    expect(ParentNoticeToken::verify($token)?->getKey())->toBe($a->id);
});

test('(i) opt-out theo cap (hoc sinh, email): doi email phu huynh qua PUT thi nhan thong bao lai', function () {
    Mail::fake();
    $student = vvT29Student();
    vvT29Unsub(vvT29Token($student))->assertOk();
    expect($student->fresh()->parent_notice_opt_out_at)->not->toBeNull();

    vvT29Put(['parent_email' => 'moi@example.com'])->assertOk()->assertJsonPath('notices_enabled', true);
    expect(vvT29Notices('parent_contact_added'))->toHaveCount(1);
});

test('(i) tai khoan an danh: ParentNotifier khong gui', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com', 'anonymized_at' => now()]);

    app(ParentNotifier::class)->accountCreated($student);

    expect(vvT29Notices())->toHaveCount(0);
    expect(DB::table('audit_logs')->where('action', 'parent_notice.sent')->where('subject_id', $student->id)->count())->toBe(0);
});
