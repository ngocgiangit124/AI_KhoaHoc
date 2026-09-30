<?php

use App\Enums\EnrollmentStatus;
use App\Mail\EnrollmentDecisionMail;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * `GET /admin/enrollment-requests`, `POST .../approve · /reject` (US-012,
 * api-contract §2.5).
 */
function vvEnrollmentAdminUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1/enrollment-requests'.$path;
}

function vvEnrollmentAdminHeaders(): array
{
    return ['Origin' => config('app.admin_url')];
}

test('admin xem danh sach cho duyet, sap xep requested_at tang dan, che email/sdt (AC7)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();

    $student1 = User::factory()->student()->create(['email' => 'hocsinh1@example.com', 'phone' => '0987654321']);
    $student2 = User::factory()->student()->create();

    $earlier = Enrollment::factory()->pendingApproval()->create([
        'user_id' => $student1->id,
        'course_id' => $course->id,
        'requested_at' => now()->subMinutes(10),
    ]);
    Enrollment::factory()->pendingApproval()->create([
        'user_id' => $student2->id,
        'course_id' => $course->id,
        'requested_at' => now()->subMinutes(5),
    ]);

    $response = test()->actingAs($admin)->getJson(vvEnrollmentAdminUrl(), vvEnrollmentAdminHeaders());

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(2);
    expect($data[0]['id'])->toBe($earlier->id);
    expect($data[0]['student']['email_masked'])->not->toBe('hocsinh1@example.com');
    expect($data[0]['student']['email_masked'])->toContain('*');
    expect($data[0]['student']['phone_masked'])->not->toContain('98765');
});

test('giao vien chi thay yeu cau cua khoa minh phu trach khi khong loc (BR4)', function () {
    $teacher = User::factory()->teacher()->create();
    $otherTeacher = User::factory()->teacher()->create();

    $myCourse = Course::factory()->published()->free()->create();
    $myCourse->teachers()->attach($teacher->id);

    $otherCourse = Course::factory()->published()->free()->create();
    $otherCourse->teachers()->attach($otherTeacher->id);

    Enrollment::factory()->pendingApproval()->create(['course_id' => $myCourse->id]);
    Enrollment::factory()->pendingApproval()->create(['course_id' => $otherCourse->id]);

    $response = test()->actingAs($teacher)->getJson(vvEnrollmentAdminUrl(), vvEnrollmentAdminHeaders());

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['course']['id'])->toBe($myCourse->id);
});

test('giao vien loc course_id khong phu trach bi tu choi 403 (AC6)', function () {
    $teacher = User::factory()->teacher()->create();
    $course = Course::factory()->published()->free()->create();

    $response = test()->actingAs($teacher)->getJson(
        vvEnrollmentAdminUrl('?course_id='.$course->id),
        vvEnrollmentAdminHeaders()
    );

    $response->assertStatus(403);
});

test('giao vien duyet khoa minh phu trach thanh cong, tang enrollments_count (AC2)', function () {
    $teacher = User::factory()->teacher()->create();
    $course = Course::factory()->published()->free()->create(['enrollments_count' => 0]);
    $course->teachers()->attach($teacher->id);
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    $response = test()->actingAs($teacher)->postJson(
        vvEnrollmentAdminUrl("/{$enrollment->id}/approve"),
        [],
        vvEnrollmentAdminHeaders()
    );

    $response->assertOk();
    $response->assertJson(['status' => 'active']);

    $enrollment->refresh();
    expect($enrollment->status)->toBe(EnrollmentStatus::Active);
    expect($enrollment->approved_by)->toBe($teacher->id);
    expect($enrollment->approved_at)->not->toBeNull();
    expect($enrollment->activated_at)->not->toBeNull();
    expect($course->fresh()->enrollments_count)->toBe(1);
});

test('duyet lan 2 tra 409 ALREADY_PROCESSED (double click)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/approve"), [], vvEnrollmentAdminHeaders())
        ->assertOk();

    $second = test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/approve"), [], vvEnrollmentAdminHeaders());

    $second->assertStatus(409);
    $second->assertJson(['code' => 'ALREADY_PROCESSED']);
});

test('giao vien khong phu trach bi 403 khi duyet, ke ca biet ID (AC6)', function () {
    $teacher = User::factory()->teacher()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    $response = test()->actingAs($teacher)->postJson(
        vvEnrollmentAdminUrl("/{$enrollment->id}/approve"),
        [],
        vvEnrollmentAdminHeaders()
    );

    $response->assertStatus(403);
    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::PendingApproval);
});

test('tu choi ghi ly do, khong doi enrollments_count (AC3)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create(['enrollments_count' => 0]);
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    $response = test()->actingAs($admin)->postJson(
        vvEnrollmentAdminUrl("/{$enrollment->id}/reject"),
        ['reason' => 'Thieu thong tin xac minh'],
        vvEnrollmentAdminHeaders()
    );

    $response->assertOk();
    $response->assertJson([
        'status' => 'rejected',
        'rejection_reason' => 'Thieu thong tin xac minh',
    ]);

    expect($course->fresh()->enrollments_count)->toBe(0);
});

test('tu choi lan 2 tra 409', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/reject"), [], vvEnrollmentAdminHeaders())
        ->assertOk();

    $second = test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/reject"), [], vvEnrollmentAdminHeaders());

    $second->assertStatus(409);
});

test('ly do tu choi chua the HTML bi tu choi 422 (van ban thuan)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    $response = test()->actingAs($admin)->postJson(
        vvEnrollmentAdminUrl("/{$enrollment->id}/reject"),
        ['reason' => '<script>alert(1)</script>'],
        vvEnrollmentAdminHeaders()
    );

    $response->assertStatus(422);
});

test('gui mail thong bao khi bat feature flag enrollment_decision_mail', function () {
    Mail::fake();
    config(['features.enrollment_decision_mail' => true]);

    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/approve"), [], vvEnrollmentAdminHeaders())
        ->assertOk();

    Mail::assertQueued(EnrollmentDecisionMail::class);
});

test('khong gui mail khi feature flag tat (mac dinh README 3.3)', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/approve"), [], vvEnrollmentAdminHeaders())
        ->assertOk();

    Mail::assertNothingQueued();
});

test('ghi audit_logs khi duyet (S15)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/approve"), [], vvEnrollmentAdminHeaders())
        ->assertOk();

    expect(
        AuditLog::query()
            ->where('action', 'enrollment.approve')
            ->where('subject_id', $enrollment->id)
            ->exists()
    )->toBeTrue();
});

test('hoc sinh khong duoc goi route duyet (role)', function () {
    $student = User::factory()->student()->verified()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    $response = test()->actingAs($student)->postJson(
        vvEnrollmentAdminUrl("/{$enrollment->id}/approve"),
        [],
        vvEnrollmentAdminHeaders()
    );

    $response->assertStatus(403);
});

test('loi queue mail khong lam approve tra 500 khi trang thai da doi (R1)', function () {
    config(['features.enrollment_decision_mail' => true]);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));

    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    $response = test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/approve"), [], vvEnrollmentAdminHeaders());

    $response->assertOk();
    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Active);
});

test('loi queue mail khong lam reject tra 500 (R1)', function () {
    config(['features.enrollment_decision_mail' => true]);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));

    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->free()->create();
    $enrollment = Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);

    test()->actingAs($admin)
        ->postJson(vvEnrollmentAdminUrl("/{$enrollment->id}/reject"), ['reason' => 'x'], vvEnrollmentAdminHeaders())
        ->assertOk();

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Rejected);
});

test('per_page ngoai khoang bi 422 (R7)', function () {
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)
        ->getJson(vvEnrollmentAdminUrl('?per_page=500'), vvEnrollmentAdminHeaders())
        ->assertStatus(422);
});
