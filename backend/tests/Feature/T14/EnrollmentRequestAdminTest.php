<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

require_once __DIR__.'/../T28/helpers.php';

function vvAdminPost(string $path, array $payload = [])
{
    return test()->postJson(vvAdminUrl($path), $payload, vvAdminHeaders());
}

function vvPendingFor(Course $course, array $userAttrs = []): Enrollment
{
    return Enrollment::factory()->pendingApproval()->create([
        'course_id' => $course->id,
        'user_id' => User::factory()->student()->create($userAttrs),
    ]);
}

function vvTeacherOf(Course ...$courses): User
{
    $teacher = vvStaffUser('teacher');
    foreach ($courses as $c) {
        $c->teachers()->attach($teacher->id);
    }

    return $teacher;
}

test('AC7: danh sach cho duyet sap requested_at asc, PII che, phan trang 25, khong lo field nhay cam', function () {
    $course = Course::factory()->published()->create();
    $late = vvPendingFor($course, ['name' => 'HS Muon', 'email' => 'muon@example.com', 'phone' => '0911111111']);
    $late->forceFill(['requested_at' => now()])->save();
    $early = vvPendingFor($course, ['name' => 'HS Som', 'email' => 'nguyenvanan@gmail.com', 'phone' => '0912345678']);
    $early->forceFill(['requested_at' => now()->subHours(3)])->save();
    Enrollment::factory()->rejected()->create(['course_id' => $course->id]);

    vvStaffLogin(vvStaffUser('pageManager'));
    $res = vvAdminGet('/admin/enrollment-requests')->assertOk();

    expect($res->json('data.0.id'))->toBe($early->id)
        ->and($res->json('data.1.id'))->toBe($late->id)
        ->and($res->json('data'))->toHaveCount(2)
        ->and($res->json('data.0.student.email_masked'))->toBe('n***@gmail.com')
        ->and($res->json('data.0.student.phone_masked'))->toBe('******5678')
        ->and($res->json('data.0.student.name'))->toBe('HS Som')
        ->and($res->json('meta.per_page'))->toBe(25);
    expect($res->getContent())->not->toContain('nguyenvanan@gmail.com')->not->toContain('0912345678')->not->toContain('password');
});

test('R4: yeu cau cua khoa da xoa mem khong nam trong danh sach (khong tra course null)', function () {
    $live = Course::factory()->published()->create();
    $gone = Course::factory()->published()->create();
    vvPendingFor($live);
    vvPendingFor($gone);
    $gone->delete();
    vvStaffLogin(vvStaffUser('admin'));

    $data = vvAdminGet('/admin/enrollment-requests')->assertOk()->json('data');
    expect($data)->toHaveCount(1)->and($data[0]['course']['id'])->toBe($live->id);
});

test('R3: duyet khi khoa da doi sang co phi -> 422 COURSE_NOT_FREE, van pending', function () {
    $course = Course::factory()->published()->create();
    $e = vvPendingFor($course);
    $course->forceFill(['price' => 100000])->save();
    vvStaffLogin(vvStaffUser('admin'));

    vvAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertStatus(422)->assertJsonPath('code', 'COURSE_NOT_FREE');
    expect($e->fresh()->status->value)->toBe('pending_approval');
    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject")->assertOk();
});

test('filter course_id + status; per_page sai -> 422 tieng Viet', function () {
    $a = Course::factory()->published()->create();
    $b = Course::factory()->published()->create();
    vvPendingFor($a);
    vvPendingFor($b);
    Enrollment::factory()->rejected()->create(['course_id' => $a->id]);

    vvStaffLogin(vvStaffUser('admin'));

    expect(vvAdminGet("/admin/enrollment-requests?course_id={$a->id}")->assertOk()->json('data'))->toHaveCount(1);
    expect(vvAdminGet("/admin/enrollment-requests?course_id={$a->id}&status=rejected")->assertOk()->json('data'))->toHaveCount(1);
    expect(vvAdminGet('/admin/enrollment-requests?course_id=999999')->assertOk()->json('data'))->toHaveCount(0);
    vvAdminGet('/admin/enrollment-requests?per_page=1000')->assertStatus(422)->assertJsonPath('errors.per_page.0', 'Số dòng mỗi trang phải từ 1 đến 100.');
    vvAdminGet('/admin/enrollment-requests?status=hack')->assertStatus(422);
});

test('BR4/AC6: giao vien chi thay khoa minh phu trach; loc khoa khac -> 403 (ke ca khoa khong ton tai)', function () {
    $mine = Course::factory()->published()->create();
    $theirs = Course::factory()->published()->create();
    $e = vvPendingFor($mine);
    vvPendingFor($theirs);

    vvStaffLogin(vvTeacherOf($mine));

    $res = vvAdminGet('/admin/enrollment-requests')->assertOk();
    expect($res->json('data'))->toHaveCount(1)->and($res->json('data.0.id'))->toBe($e->id);
    vvAdminGet("/admin/enrollment-requests?course_id={$mine->id}")->assertOk();
    vvAdminGet("/admin/enrollment-requests?course_id={$theirs->id}")->assertForbidden();
    vvAdminGet('/admin/enrollment-requests?course_id=999999')->assertForbidden();
});

test('AC2: staff duyet -> active, approved_by, count +1, audit; double click -> 409 ALREADY_PROCESSED', function () {
    $course = Course::factory()->published()->create();
    $e = vvPendingFor($course);
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);

    vvAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('student.email_masked', fn ($v) => str_contains($v, '***'));

    $fresh = $e->fresh();
    expect($fresh->status->value)->toBe('active')->and($fresh->approved_by)->toBe($admin->id)
        ->and($course->fresh()->enrollments_count)->toBe(1);
    $log = AuditLog::where('action', 'enrollment.approve')->where('subject_id', $e->id)->first();
    expect($log)->not->toBeNull()->and($log->actor_id)->toBe($admin->id);

    vvAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject")->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
    expect($course->fresh()->enrollments_count)->toBe(1);
});

test('AC3: giao vien phu trach tu choi kem ly do -> rejected; count khong doi', function () {
    $course = Course::factory()->published()->create();
    $e = vvPendingFor($course);
    $teacher = vvTeacherOf($course);
    vvStaffLogin($teacher);

    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => 'Chua dung lop'])->assertOk()
        ->assertJsonPath('status', 'rejected')->assertJsonPath('rejection_reason', 'Chua dung lop');

    expect($e->fresh()->rejection_reason)->toBe('Chua dung lop')->and($e->fresh()->approved_by)->toBeNull()->and($e->fresh()->approved_at)->toBeNull()
        ->and($course->fresh()->enrollments_count)->toBe(0);
});

test('AC6: giao vien KHONG phu trach duyet/tu choi -> 403, trang thai khong doi; khong dung duoc id khoa trong body', function () {
    $mine = Course::factory()->published()->create();
    $theirs = Course::factory()->published()->create();
    $e = vvPendingFor($theirs);
    vvStaffLogin(vvTeacherOf($mine));

    vvAdminPost("/admin/enrollment-requests/{$e->id}/approve", ['course_id' => $mine->id])->assertForbidden();
    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => 'x'])->assertForbidden();
    // 403 trước validate: không dò được rule bằng payload sai.
    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => str_repeat('a', 5000)])->assertForbidden();

    expect($e->fresh()->status->value)->toBe('pending_approval');
});

test('BR4 bien: khoa het giao vien phu trach -> admin / quan ly trang van duyet duoc', function () {
    $course = Course::factory()->published()->create();
    expect($course->teachers()->count())->toBe(0);
    $e = vvPendingFor($course);
    vvStaffLogin(vvStaffUser('pageManager'));

    vvAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertOk();
});

test('reason: > 1000 ky tu / chua HTML -> 422; khong ton tai enrollment -> 404; chua dang nhap -> 401', function () {
    $course = Course::factory()->published()->create();
    $e = vvPendingFor($course);
    vvStaffLogin(vvStaffUser('admin'));

    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Lý do tối đa 1.000 ký tự.');
    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => '<script>alert(1)</script>'])->assertStatus(422);
    vvAdminPost('/admin/enrollment-requests/999999/approve')->assertNotFound();
    expect($e->fresh()->status->value)->toBe('pending_approval');

    vvAdminPost("/admin/enrollment-requests/{$e->id}/reject", ['reason' => '   '])->assertOk()->assertJsonPath('rejection_reason', null);
});

test('hoc sinh khong vao duoc route quan tri (host admin)', function () {
    $e = vvPendingFor(Course::factory()->published()->create());
    vvActAsStudent(User::factory()->verified()->create());

    vvAdminGet('/admin/enrollment-requests')->assertStatus(403);
    vvAdminPost("/admin/enrollment-requests/{$e->id}/approve")->assertStatus(403);
    expect($e->fresh()->status->value)->toBe('pending_approval');
});

test('chua dang nhap admin -> 401', function () {
    vvAdminGet('/admin/enrollment-requests')->assertUnauthorized();
});
