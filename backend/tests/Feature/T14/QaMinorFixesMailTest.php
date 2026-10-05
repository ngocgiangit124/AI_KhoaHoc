<?php

use App\Enums\EnrollmentStatus;
use App\Mail\EnrollmentDecisionMail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../T28/helpers.php';

function vvQaAdminPost(string $path, array $payload = [])
{
    return test()->postJson(vvAdminUrl($path), $payload, vvAdminHeaders());
}

// QA gom sửa lỗi nhỏ (minor-fixes-1): mail duyệt/từ chối qua HTTP (host admin).

function vvQaPending(array $userAttrs = []): Enrollment
{
    return Enrollment::factory()->pendingApproval()->create([
        'course_id' => Course::factory()->published()->create()->id,
        'user_id' => User::factory()->student()->create(array_merge(['email' => uniqid('hs').'@example.com', 'email_verified_at' => now()], $userAttrs))->id,
    ]);
}

test('QA minor-fixes: HTTP duyet lan 1 queue dung 1 mail; duyet/tu choi lan 2 -> 409 va KHONG co mail thu hai', function () {
    Mail::fake();
    $e = vvQaPending();
    vvStaffLogin(vvStaffUser('admin'));

    vvQaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertOk();
    Mail::assertQueuedCount(1);

    vvQaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertStatus(409);
    vvQaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => 'x'])->assertStatus(409);
    Mail::assertQueuedCount(1);
});

test('QA minor-fixes: HTTP tu choi voi ly do chua HTML -> 422 (chan tu validation), khong gui mail, trang thai khong doi', function () {
    Mail::fake();
    $e = vvQaPending();
    vvStaffLogin(vvStaffUser('admin'));

    vvQaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => '<script>alert(1)</script>'])->assertStatus(422);
    Mail::assertNothingQueued();
    expect($e->fresh()->status)->toBe(EnrollmentStatus::PendingApproval);

    // Ký tự đặc biệt không phải thẻ: được nhận và escape trong mail.
    vvQaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => 'Thiếu giấy tờ "x" & y'])->assertOk();
    Mail::assertQueued(EnrollmentDecisionMail::class, fn (EnrollmentDecisionMail $m) => str_contains($m->render(), 'Thiếu giấy tờ &quot;x&quot; &amp; y'));
});

test('QA minor-fixes: ten khoa/hoc sinh chua HTML cung duoc escape', function () {
    Mail::fake();
    $e = Enrollment::factory()->pendingApproval()->create([
        'course_id' => Course::factory()->published()->create(['title' => 'Toán <i>9</i>'])->id,
        'user_id' => User::factory()->student()->create(['name' => 'An <u>x</u>', 'email' => 'a@example.com', 'email_verified_at' => now()])->id,
    ]);
    app(EnrollmentService::class)->approve($e, User::factory()->admin()->create());

    Mail::assertQueued(EnrollmentDecisionMail::class, function (EnrollmentDecisionMail $m) {
        $html = $m->render();

        return ! str_contains($html, '<i>9</i>') && ! str_contains($html, '<u>x</u>');
    });
});

test('QA minor-fixes: HS chi co SDT / email chua xac thuc qua HTTP -> khong gui mail, van duyet duoc', function () {
    Mail::fake();
    $a = vvQaPending(['email' => null]);
    $b = vvQaPending(['email_verified_at' => null]);
    vvStaffLogin(vvStaffUser('admin'));

    vvQaAdminPost("/admin/enrollment-requests/{$a->id}/approve")->assertOk();
    vvQaAdminPost("/admin/enrollment-requests/{$b->id}/reject", ['reason' => 'x'])->assertOk();
    Mail::assertNothingQueued();
});

test('QA minor-fixes: loi queue/mail khong lam hong viec duyet da commit, log khong chua PII', function () {
    $e = vvQaPending(['email' => 'pii-secret@example.com']);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down pii-secret@example.com'));
    Log::spy();

    $result = app(EnrollmentService::class)->approve($e, User::factory()->admin()->create());

    expect($result->status)->toBe(EnrollmentStatus::Active)->and($e->fresh()->status)->toBe(EnrollmentStatus::Active);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => ! str_contains(json_encode($ctx), 'pii-secret'))->once();
});

test('QA minor-fixes: duyet that bai (409) khong gui mail; khoa bi xoa mem van duyet va gui mail khong loi', function () {
    Mail::fake();
    $e = vvQaPending();
    $e->course->delete();
    app(EnrollmentService::class)->reject($e->fresh(), User::factory()->admin()->create(), null);
    Mail::assertQueuedCount(1);
});
