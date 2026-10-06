<?php

use App\Enums\EnrollmentStatus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;

require_once __DIR__.'/../T28/helpers.php';

function qaPending(Course $course): Enrollment
{
    return Enrollment::factory()->pendingApproval()->create([
        'course_id' => $course->id,
        'user_id' => User::factory()->student()->create(),
    ]);
}

function qaAdminPost(string $path, array $payload = [])
{
    return test()->postJson(vvAdminUrl($path), $payload, vvAdminHeaders());
}

test('QA US-012 AC1: sau khi xin hoc, viewer-state (T10) tra pending_approval', function () {
    vvActAsStudent(User::factory()->verified()->create());
    $course = Course::factory()->published()->create();

    test()->getJson(vvApiUrl("/courses/{$course->slug}/viewer-state"), vvWebHeaders())
        ->assertOk()->assertJsonPath('viewer_state', 'can_register_free');

    test()->postJson(vvApiUrl("/courses/{$course->id}/free-enrollments"), [], vvWebHeaders())->assertCreated();

    $res = test()->getJson(vvApiUrl("/courses/{$course->slug}/viewer-state"), vvWebHeaders())->assertOk();
    expect($res->json('viewer_state'))->toBe('pending_approval');
});

test('QA BR1: id khong phai so / am -> 404, khong 500', function () {
    vvActAsStudent(User::factory()->verified()->create());
    foreach (['abc', '0', '-1', '99999999999999999999'] as $id) {
        test()->postJson(vvApiUrl("/courses/{$id}/free-enrollments"), [], vvWebHeaders())->assertNotFound();
    }
});

test('QA AC2/AC3: duyet o khoa da xoa mem -> 409 COURSE_UNAVAILABLE (count khong tang), van tu choi duoc; tu choi khoa co phi van cho phep', function () {
    $gone = Course::factory()->published()->create();
    $e = qaPending($gone);
    $gone->delete();
    vvStaffLogin(vvStaffUser('admin'));

    qaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertStatus(409)->assertJsonPath('code', 'COURSE_UNAVAILABLE');
    expect((int) Course::withTrashed()->find($gone->id)->enrollments_count)->toBe(0)->and($e->fresh()->status->value)->toBe('pending_approval');

    $paid = Course::factory()->published()->create();
    $p = qaPending($paid);
    Course::whereKey($paid->id)->update(['price' => 500000]);
    qaAdminPost("/admin/enrollment-requests/{$p->id}/reject", ['reason' => 'Khoa da co phi'])->assertOk()->assertJsonPath('status', 'rejected');
    expect($paid->fresh()->enrollments_count)->toBe(0);
});

test('QA BR4 bien: giao vien bi go khoi khoa giua chung -> 403 ca index loc course_id lan duyet; admin van duyet', function () {
    $course = Course::factory()->published()->create();
    $e = qaPending($course);
    $teacher = vvStaffUser('teacher');
    $course->teachers()->attach($teacher->id);
    vvStaffLogin($teacher);
    vvAdminGet('/admin/enrollment-requests?course_id='.$course->id)->assertOk();

    $course->teachers()->detach($teacher->id);
    vvAdminGet('/admin/enrollment-requests?course_id='.$course->id)->assertForbidden();
    expect(vvAdminGet('/admin/enrollment-requests')->assertOk()->json('data'))->toBeEmpty();
    qaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertForbidden();

    vvStaffLogin(vvStaffUser('admin'));
    qaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertOk();
});

test('QA reason: ky tu dieu khien -> 422; tieng Viet co dau + xuong dong hop le; trim', function () {
    $course = Course::factory()->published()->create();
    vvStaffLogin(vvStaffUser('admin'));
    $e = qaPending($course);

    qaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => "abc\x00def"])->assertStatus(422);
    qaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => 'a < b'])->assertStatus(422);
    qaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => ['x']])->assertStatus(422);
    expect($e->fresh()->status)->toBe(EnrollmentStatus::PendingApproval);

    qaAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => "  Chưa đúng lớp.\nVui lòng chọn lại  "])->assertOk()
        ->assertJsonPath('rejection_reason', "Chưa đúng lớp.\nVui lòng chọn lại");

    // reason cũng chấp nhận đúng 1000 ký tự (biên)
    $e2 = qaPending($course);
    qaAdminPost("/admin/enrollment-requests/{$e2->id}/reject", ['reason' => str_repeat('đ', 1000)])->assertOk();
});

test('QA index: status active/rejected loc dung; status khong hop le (revoked) -> 422; course_id khong phai so -> 422', function () {
    $course = Course::factory()->published()->create();
    qaPending($course);
    Enrollment::factory()->rejected()->create(['course_id' => $course->id]);
    vvStaffLogin(vvStaffUser('pageManager'));

    expect(vvAdminGet('/admin/enrollment-requests?status=rejected')->assertOk()->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/enrollment-requests?status=active')->assertOk()->json('data'))->toHaveCount(0);
    vvAdminGet('/admin/enrollment-requests?status=revoked')->assertStatus(422);
    vvAdminGet('/admin/enrollment-requests?course_id=abc')->assertStatus(422);
    vvAdminGet('/admin/enrollment-requests?per_page=101')->assertStatus(422);
    vvAdminGet('/admin/enrollment-requests?per_page=0')->assertStatus(422);
});

test('QA grantPurchase nang pending -> active/purchase, roi admin duyet dong cu -> 409 ALREADY_PROCESSED, count dung 1', function () {
    $course = Course::factory()->published()->create();
    $e = qaPending($course);
    $student = User::find($e->user_id);

    vvStaffLogin(vvStaffUser('admin'));
    app(EnrollmentService::class)->grantPurchase($student, $course);

    qaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    qaAdminPost("/admin/enrollment-requests/{$e->id}/reject")->assertStatus(409);
    expect($course->fresh()->enrollments_count)->toBe(1)
        ->and($e->fresh()->source->value)->toBe('purchase');
});

test('QA audit: approve/reject/request ghi audit khong chua PII (email/sdt)', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $course = Course::factory()->published()->create();
    $student = User::factory()->verified()->create(['email' => 'qa-audit@example.com', 'phone' => '0933445566']);
    // audit_logs bất biến (trigger L2) nên dòng từ test khác có thể còn lại: đếm theo mức nền.
    $before = AuditLog::whereIn('action', ['enrollment.request', 'enrollment.approve'])->count();
    $e = app(EnrollmentService::class)->requestFree($student, $course);

    qaAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertOk();

    $dump = AuditLog::whereIn('action', ['enrollment.request', 'enrollment.approve'])->get()->toJson();
    expect(AuditLog::whereIn('action', ['enrollment.request', 'enrollment.approve'])->count() - $before)->toBe(2)
        ->and($dump)->not->toContain('qa-audit@example.com')->not->toContain('0933445566');
});
