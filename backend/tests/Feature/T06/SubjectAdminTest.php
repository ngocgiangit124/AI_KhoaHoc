<?php

use App\Enums\SubjectStatus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;

/**
 * US-011 — Quản lý chuyên đề (host admin-api, nhóm `staff`).
 */
function subjectAdminUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1/subjects'.$path;
}

function subjectAdminHeaders(): array
{
    return ['Origin' => config('app.admin_url')];
}

// --- Phân quyền (US-011 §Phân quyền, api-contract §2.5) -------------------

test('admin xem duoc danh sach chuyen de (AC1)', function () {
    $admin = User::factory()->admin()->create();
    Subject::factory()->count(2)->create();
    Subject::factory()->hidden()->create();

    $response = $this->actingAs($admin)->getJson(subjectAdminUrl(), subjectAdminHeaders());

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

test('quan ly trang tao chuyen de moi thanh cong (AC1)', function () {
    $pageManager = User::factory()->pageManager()->create();

    $response = $this->actingAs($pageManager)->postJson(subjectAdminUrl(), [
        'name' => 'Hình học',
    ], subjectAdminHeaders());

    $response->assertCreated();
    $response->assertJson([
        'name' => 'Hình học',
        'slug' => 'hinh-hoc',
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('subjects', [
        'name' => 'Hình học',
        'status' => 'active',
    ]);
});

test('giao vien chi xem duoc chuyen de active, khong tao/sua/xoa duoc (BR4)', function () {
    $teacher = User::factory()->teacher()->create();
    Subject::factory()->create();
    $hidden = Subject::factory()->hidden()->create();

    $index = $this->actingAs($teacher)->getJson(subjectAdminUrl(), subjectAdminHeaders());
    $index->assertOk();
    expect($index->json('data'))->toHaveCount(1);

    $store = $this->actingAs($teacher)->postJson(subjectAdminUrl(), ['name' => 'Số học'], subjectAdminHeaders());
    $store->assertForbidden();

    $update = $this->actingAs($teacher)->putJson(subjectAdminUrl('/'.$hidden->id), ['name' => 'Đổi tên'], subjectAdminHeaders());
    $update->assertForbidden();

    $destroy = $this->actingAs($teacher)->deleteJson(subjectAdminUrl('/'.$hidden->id), [], subjectAdminHeaders());
    $destroy->assertForbidden();

    $status = $this->actingAs($teacher)->patchJson(subjectAdminUrl('/'.$hidden->id.'/status'), ['status' => 'active'], subjectAdminHeaders());
    $status->assertForbidden();
});

// R2 (review-T06) — phân quyền (middleware `can:...`) phải chạy TRƯỚC validate
// của FormRequest: Giáo viên gửi payload không hợp lệ vẫn phải nhận 403 (không
// lộ chi tiết validate qua 422) vì không có quyền thao tác chứ không phải vì
// dữ liệu sai.
test('giao vien gui payload khong hop le van bi 403, khong phai 422 (R2)', function () {
    $teacher = User::factory()->teacher()->create();
    $existing = Subject::factory()->create(['name' => 'Đại số']);
    $target = Subject::factory()->create(['name' => 'Hình học']);

    $storeEmpty = $this->actingAs($teacher)->postJson(subjectAdminUrl(), ['name' => '   '], subjectAdminHeaders());
    $storeEmpty->assertForbidden();

    $storeDuplicate = $this->actingAs($teacher)->postJson(subjectAdminUrl(), ['name' => 'đại số'], subjectAdminHeaders());
    $storeDuplicate->assertForbidden();

    $storeHtml = $this->actingAs($teacher)->postJson(subjectAdminUrl(), ['name' => '<script>alert(1)</script>'], subjectAdminHeaders());
    $storeHtml->assertForbidden();

    // 'đại số' trùng với $existing (không phải chính $target đang sửa).
    $updateDuplicate = $this->actingAs($teacher)->putJson(subjectAdminUrl('/'.$target->id), ['name' => 'đại số'], subjectAdminHeaders());
    $updateDuplicate->assertForbidden();
});

test('hoc sinh bi tu choi 403', function () {
    $student = User::factory()->student()->create();

    $response = $this->actingAs($student)->getJson(subjectAdminUrl(), subjectAdminHeaders());

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('khach chua dang nhap bi tu choi 401', function () {
    $response = $this->getJson(subjectAdminUrl(), subjectAdminHeaders());

    $response->assertStatus(401);
});

// --- Validation (US-011 Trường hợp biên & lỗi) -----------------------------

test('ten chuyen de rong bi bao loi validate', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(subjectAdminUrl(), ['name' => '   '], subjectAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

test('ten chuyen de qua 100 ky tu bi bao loi validate', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(subjectAdminUrl(), [
        'name' => str_repeat('a', 101),
    ], subjectAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

test('ten chuyen de chua HTML bi tu choi (S8 - XSS)', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson(subjectAdminUrl(), [
        'name' => '<script>alert(1)</script>',
    ], subjectAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

test('ten chuyen de trung khong phan biet hoa thuong bi bao loi Chuyen de da ton tai (AC2)', function () {
    $admin = User::factory()->admin()->create();
    Subject::factory()->create(['name' => 'Đại số']);

    $response = $this->actingAs($admin)->postJson(subjectAdminUrl(), [
        'name' => 'đại số',
    ], subjectAdminHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
    expect($response->json('errors.name.0'))->toBe('Chuyên đề đã tồn tại.');
});

// --- Sửa / ẩn-hiện / xoá ---------------------------------------------------

test('doi ten chuyen de thanh cong, khong can cap nhat thu cong noi khac (AC5)', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create(['name' => 'Toán A']);

    $response = $this->actingAs($admin)->putJson(subjectAdminUrl('/'.$subject->id), [
        'name' => 'Toán B',
    ], subjectAdminHeaders());

    $response->assertOk();
    $response->assertJson(['name' => 'Toán B']);
    $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'name' => 'Toán B']);
});

test('an chuyen de thanh cong (AC6)', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create();

    $response = $this->actingAs($admin)->patchJson(subjectAdminUrl('/'.$subject->id.'/status'), [
        'status' => 'hidden',
    ], subjectAdminHeaders());

    $response->assertOk();
    $response->assertJson(['status' => 'hidden']);
    expect($subject->fresh()->status)->toBe(SubjectStatus::Hidden);
});

test('hien lai chuyen de thanh cong', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->hidden()->create();

    $response = $this->actingAs($admin)->patchJson(subjectAdminUrl('/'.$subject->id.'/status'), [
        'status' => 'active',
    ], subjectAdminHeaders());

    $response->assertOk();
    expect($subject->fresh()->status)->toBe(SubjectStatus::Active);
});

test('xoa chuyen de chua gan khoa hoc nao thanh cong (AC4)', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create();

    $response = $this->actingAs($admin)->deleteJson(subjectAdminUrl('/'.$subject->id), [], subjectAdminHeaders());

    $response->assertNoContent();
    $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
});

// Đã gộp T07 (`courses`/`course_subject` với FK `subject_id` restrict) —
// bật lại test này. Logic chặn xoá (`SubjectService::delete`) không đổi, chỉ
// dựa vào FK `restrict` (bắt `QueryException` mã 1451).
test('xoa chuyen de dang gan khoa hoc bi chan 409 (AC3)', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create();
    $course = Course::factory()->create();
    $course->subjects()->attach($subject);

    $response = $this->actingAs($admin)->deleteJson(subjectAdminUrl('/'.$subject->id), [], subjectAdminHeaders());

    $response->assertStatus(409);
    $response->assertJson(['code' => 'SUBJECT_IN_USE']);
    $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
});

// --- Audit log (S15) --------------------------------------------------------

test('tao chuyen de ghi audit log', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(subjectAdminUrl(), ['name' => 'Ôn thi vào 10'], subjectAdminHeaders());

    $log = AuditLog::query()->where('action', 'subject.create')->first();

    expect($log)->not->toBeNull();
    expect($log->actor_id)->toBe($admin->id);
    expect($log->subject_type)->toBe((new Subject)->getMorphClass());
});

test('xoa chuyen de ghi audit log', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create();

    $this->actingAs($admin)->deleteJson(subjectAdminUrl('/'.$subject->id), [], subjectAdminHeaders());

    $log = AuditLog::query()->where('action', 'subject.delete')->first();

    expect($log)->not->toBeNull();
    expect($log->subject_id)->toBe($subject->id);
});

test('an/hien chuyen de ghi audit log', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create();

    $this->actingAs($admin)->patchJson(subjectAdminUrl('/'.$subject->id.'/status'), ['status' => 'hidden'], subjectAdminHeaders());

    $log = AuditLog::query()->where('action', 'subject.status.update')->first();

    expect($log)->not->toBeNull();
    expect($log->changes)->toEqual(['status' => ['before' => 'active', 'after' => 'hidden']]);
});
