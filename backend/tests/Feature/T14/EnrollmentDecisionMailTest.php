<?php

use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Mail\EnrollmentDecisionMail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function vvDecisionPending(array $userAttrs = [], array $courseAttrs = []): Enrollment
{
    return Enrollment::factory()->pendingApproval()->create([
        'course_id' => Course::factory()->published()->create($courseAttrs)->id,
        'user_id' => User::factory()->student()->create(array_merge(['email' => uniqid('hs').'@example.com', 'email_verified_at' => now()], $userAttrs)),
    ]);
}

test('US-012 AC2: duyet xong queue email tieng Viet toi hoc sinh', function () {
    Mail::fake();
    $enrollment = vvDecisionPending(courseAttrs: ['title' => 'Toán 9 cơ bản']);

    app(EnrollmentService::class)->approve($enrollment, User::factory()->admin()->create());

    Mail::assertQueued(EnrollmentDecisionMail::class, function (EnrollmentDecisionMail $m) use ($enrollment) {
        return $m->approved === true && $m->hasTo($enrollment->user->email)
            && str_contains($m->envelope()->subject, 'Toán 9 cơ bản')
            && str_contains($m->render(), 'đã được duyệt');
    });
});

test('US-012 AC3: tu choi queue email co ly do (escape HTML)', function () {
    Mail::fake();
    $enrollment = vvDecisionPending();

    app(EnrollmentService::class)->reject($enrollment, User::factory()->admin()->create(), 'Thiếu <b>thông tin</b>');

    Mail::assertQueued(EnrollmentDecisionMail::class, function (EnrollmentDecisionMail $m) {
        $html = $m->render();

        return $m->approved === false && str_contains($html, 'chưa được chấp nhận')
            && str_contains($html, 'Thiếu &lt;b&gt;thông tin&lt;/b&gt;') && ! str_contains($html, '<b>thông tin</b>');
    });
});

test('khong gui mail khi hoc sinh khong co email da xac thuc', function () {
    Mail::fake();
    app(EnrollmentService::class)->approve(vvDecisionPending(['email' => null]), User::factory()->admin()->create());
    app(EnrollmentService::class)->reject(vvDecisionPending(['email_verified_at' => null]), User::factory()->admin()->create());

    Mail::assertNothingQueued();
});

test('duyet that bai (khoa chuyen sang co phi) hoac transaction ngoai rollback thi khong gui mail', function () {
    Mail::fake();
    $paid = vvDecisionPending();
    Course::query()->whereKey($paid->course_id)->update(['price' => 100000]);

    expect(fn () => app(EnrollmentService::class)->approve($paid, User::factory()->admin()->create()))->toThrow(DomainException::class);

    $enrollment = vvDecisionPending();
    try {
        DB::transaction(function () use ($enrollment): void {
            app(EnrollmentService::class)->approve($enrollment, User::factory()->admin()->create());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::PendingApproval);
    Mail::assertNothingQueued();
});
