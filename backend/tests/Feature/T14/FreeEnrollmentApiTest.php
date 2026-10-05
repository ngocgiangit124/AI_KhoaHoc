<?php

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

require_once __DIR__.'/../T04/helpers.php';

function vvFreeEnrollPost(int|string $courseId)
{
    return test()->postJson(vvApiUrl("/courses/{$courseId}/free-enrollments"), [], vvWebHeaders());
}

test('AC1: hoc sinh da xac thuc xin hoc khoa mien phi -> 201 pending_approval', function () {
    $student = vvActAsStudent(User::factory()->verified()->create());
    $course = Course::factory()->published()->create();

    vvFreeEnrollPost($course->id)->assertCreated()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('course_id', $course->id)
        ->assertJsonStructure(['id', 'course_id', 'status', 'requested_at'])
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('approved_by');

    expect(Enrollment::where('user_id', $student->id)->count())->toBe(1);
});

test('US-001 AC9/BR7: chua xac thuc OTP -> 403 ACCOUNT_NOT_VERIFIED, khong tao ban ghi', function () {
    $student = vvActAsStudent(User::factory()->create());
    $course = Course::factory()->published()->create();

    vvFreeEnrollPost($course->id)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_NOT_VERIFIED');

    expect(Enrollment::count())->toBe(0);
});

test('chi can 1 kenh xac thuc (email hoac sdt) la du', function () {
    vvActAsStudent(User::factory()->create(['email_verified_at' => now()]));
    $course = Course::factory()->published()->create();

    vvFreeEnrollPost($course->id)->assertCreated();
});

test('AC4: gui lai khi dang cho -> 409 ENROLLMENT_PENDING; khi da duoc duyet -> 409 ALREADY_OWNED', function () {
    $student = vvActAsStudent(User::factory()->verified()->create());
    $course = Course::factory()->published()->create();

    vvFreeEnrollPost($course->id)->assertCreated();
    vvFreeEnrollPost($course->id)->assertStatus(409)->assertJsonPath('code', 'ENROLLMENT_PENDING');

    Enrollment::where('user_id', $student->id)->update(['status' => 'active']);
    vvFreeEnrollPost($course->id)->assertStatus(409)->assertJsonPath('code', 'ALREADY_OWNED');
});

test('AC5: bi tu choi roi gui lai -> 201 ban ghi moi', function () {
    $student = vvActAsStudent(User::factory()->verified()->create());
    $course = Course::factory()->published()->create();
    Enrollment::factory()->rejected()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    vvFreeEnrollPost($course->id)->assertCreated();

    expect(Enrollment::where('user_id', $student->id)->count())->toBe(2);
});

test('BR1: khoa co phi -> 422 COURSE_NOT_FREE; chua published / xoa mem / khong ton tai -> 404', function () {
    vvActAsStudent(User::factory()->verified()->create());

    vvFreeEnrollPost(Course::factory()->published()->paid()->create()->id)->assertStatus(422)->assertJsonPath('code', 'COURSE_NOT_FREE');
    vvFreeEnrollPost(Course::factory()->create()->id)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    vvFreeEnrollPost(Course::factory()->unpublished()->create()->id)->assertNotFound();
    $deleted = Course::factory()->published()->create();
    $deleted->delete();
    vvFreeEnrollPost($deleted->id)->assertNotFound();
    vvFreeEnrollPost(999999)->assertNotFound();

    expect(Enrollment::count())->toBe(0);
});

test('chua dang nhap -> 401; khoa bi khoa -> 403 ACCOUNT_LOCKED', function () {
    $course = Course::factory()->published()->create();

    vvFreeEnrollPost($course->id)->assertUnauthorized();

    vvActAsStudent(User::factory()->verified()->locked()->create());
    vvFreeEnrollPost($course->id)->assertForbidden();
    expect(Enrollment::count())->toBe(0);
});

test('mass-assignment: body status/user_id/course_id bi bo qua', function () {
    $student = vvActAsStudent(User::factory()->verified()->create());
    $other = User::factory()->verified()->create();
    $course = Course::factory()->published()->create();

    test()->postJson(vvApiUrl("/courses/{$course->id}/free-enrollments"), [
        'status' => 'active', 'user_id' => $other->id, 'approved_by' => $other->id, 'source' => 'purchase',
    ], vvWebHeaders())->assertCreated()->assertJsonPath('status', 'pending_approval');

    $row = Enrollment::first();
    expect($row->user_id)->toBe($student->id)->and($row->approved_by)->toBeNull()->and($row->source->value)->toBe('free_approval');
});

test('tai khoan giao vien khong goi duoc route hoc sinh (role)', function () {
    vvActAsStudent(User::factory()->teacher()->create());

    vvFreeEnrollPost(Course::factory()->published()->create()->id)->assertForbidden();
});
