<?php

use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Enrollment\EnrollmentService;

/**
 * `EnrollmentService::revoke()` chưa có route HTTP nào ở T14 (viết sẵn cho
 * `RefundService` — T20) — kiểm trực tiếp qua container, giống cách
 * `docs/architecture/api-contract.md` §3 mô tả "nơi DUY NHẤT đổi
 * `enrollments.status`".
 */
test('revoke thu hoi enrollment active va giam enrollments_count', function () {
    $course = Course::factory()->published()->create(['price' => 199000, 'enrollments_count' => 1]);
    $enrollment = Enrollment::factory()->create(['course_id' => $course->id]);

    $result = app(EnrollmentService::class)->revoke($enrollment, 'refund');

    expect($result->status)->toBe(EnrollmentStatus::Revoked);
    expect($result->revoked_reason)->toBe('refund');
    expect($result->revoked_at)->not->toBeNull();
    expect($course->fresh()->enrollments_count)->toBe(0);
});

test('revoke enrollment khong active (da rejected) tra ALREADY_PROCESSED', function () {
    $course = Course::factory()->published()->create();
    $enrollment = Enrollment::factory()->rejected()->create(['course_id' => $course->id]);

    expect(fn () => app(EnrollmentService::class)->revoke($enrollment, 'refund'))
        ->toThrow(DomainException::class);
});

test('goi requestFree 2 lan lien tiep cho cung 1 hoc sinh/khoa chi tao 1 ban ghi song (BR5)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->verified()->create();

    app(EnrollmentService::class)->requestFree($course, $student);

    expect(fn () => app(EnrollmentService::class)->requestFree($course, $student))
        ->toThrow(DomainException::class);

    expect(Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->count())->toBe(1);
});

test('nhanh bat loi 1062 khi 2 request dua nhau: findLiveEnrollment stale tra null lan dau (R4)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->verified()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    // Mô phỏng race: lần đọc đầu "chưa thấy" dòng live (request kia chưa INSERT
    // xong), save() đụng unique live_flag, lần đọc lại mới thấy.
    $service = new class(app(AuditLogger::class)) extends EnrollmentService
    {
        private int $calls = 0;

        protected function findLiveEnrollment(User $student, Course $course): ?Enrollment
        {
            return $this->calls++ === 0 ? null : parent::findLiveEnrollment($student, $course);
        }
    };

    try {
        $service->requestFree($course, $student);
        $this->fail('Phải ném DomainException');
    } catch (DomainException $e) {
        expect($e->code())->toBe('ENROLLMENT_PENDING');
        expect($e->status())->toBe(409);
    }

    expect(Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->count())->toBe(1);
});
