<?php

use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
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
