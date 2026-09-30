<?php

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * `POST /courses/{course}/free-enrollments` (US-012, api-contract §2.4).
 */
function vvFreeEnrollUrl(Course $course): string
{
    return 'http://'.config('app.api_host')."/api/v1/courses/{$course->id}/free-enrollments";
}

function vvFreeEnrollHeaders(): array
{
    return ['Origin' => config('app.frontend_url')];
}

test('hoc sinh dang ky khoa mien phi thanh cong (AC1)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->verified()->create();

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertCreated();
    $response->assertJson([
        'course_id' => $course->id,
        'status' => 'pending_approval',
    ]);

    $enrollment = Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->first();
    expect($enrollment)->not->toBeNull();
    expect($enrollment->status->value)->toBe('pending_approval');
    expect($enrollment->source->value)->toBe('free_approval');
});

test('chan gui trung yeu cau khi dang cho duyet (AC4, BR5)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->verified()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(409);
    $response->assertJson(['code' => 'ENROLLMENT_PENDING']);
    expect(Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->count())->toBe(1);
});

test('da so huu khoa (active) tra ALREADY_OWNED', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->verified()->create();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(409);
    $response->assertJson(['code' => 'ALREADY_OWNED']);
});

test('gui lai yeu cau sau khi bi tu choi tao ban ghi moi, khong bi chan vinh vien (AC5)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->verified()->create();
    Enrollment::factory()->rejected()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertCreated();
    expect(Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->count())->toBe(2);
});

test('khoa co phi bi tu choi 403 FORBIDDEN (BR1)', function () {
    $course = Course::factory()->published()->create(['price' => 199000]);
    $student = User::factory()->student()->verified()->create();

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('khoa chua publish tra 404 (khong lo su ton tai)', function () {
    $course = Course::factory()->free()->create();
    $student = User::factory()->student()->verified()->create();

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(404);
});

test('chua xac thuc OTP bi chan 403 ACCOUNT_NOT_VERIFIED (BR7)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->student()->create();

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'ACCOUNT_NOT_VERIFIED']);
});

test('hoc sinh duoi nguong tuoi chua co xac nhan phu huynh bi chan (US-017 BR6)', function () {
    $course = Course::factory()->published()->free()->create();
    $student = User::factory()->minor()->verified()->create();

    $response = test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'PARENT_CONSENT_REQUIRED']);
});

test('khach chua dang nhap bi 401', function () {
    $course = Course::factory()->published()->free()->create();

    $response = test()->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());

    $response->assertStatus(401);
});

test('throttle free-enroll: qua 10 lan/phut theo user bi 429 (R3)', function () {
    $student = User::factory()->student()->verified()->create();
    $course = Course::factory()->published()->free()->create();

    for ($i = 0; $i < 10; $i++) {
        test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders());
    }

    test()->actingAs($student)->postJson(vvFreeEnrollUrl($course), [], vvFreeEnrollHeaders())
        ->assertStatus(429);
});
