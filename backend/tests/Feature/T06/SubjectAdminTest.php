<?php

use App\Enums\SubjectStatus;
use App\Models\AuditLog;
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

// TODO (chờ gộp T07): bảng `course_subject` (FK `subject_id` restrict) do T07
// tạo ở một worktree khác — chưa tồn tại trong DB test của T06. Bật lại test
// này (bỏ `->skip()`) sau khi gộp nhánh T06+T07, xác nhận migration
// `course_subject` đã chạy. Logic chặn xoá (`SubjectService::delete`) đã viết
// đầy đủ, dựa vào FK `restrict` (bắt `QueryException` mã 1451) — không cần
// sửa code khi bật lại, chỉ cần dữ liệu `course_subject` tồn tại.
test('xoa chuyen de dang gan khoa hoc bi chan 409 (AC3)', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create();

    // Giả lập gán khóa học: cần bảng `course_subject` (T07). Tạm ghi thẳng
    // bằng query builder để không phụ thuộc Model `Course` (thuộc T07, có
    // thể chưa tồn tại/đổi cấu trúc ở thời điểm chạy test này).
    DB::table('course_subject')->insert([
        'course_id' => DB::table('courses')->insertGetId([
            'title' => 'Khóa test',
            'slug' => 'khoa-test-'.uniqid(),
            'grade_level' => 10,
            'price' => 0,
            'status' => 'draft',
            'search_text' => '',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'subject_id' => $subject->id,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($admin)->deleteJson(subjectAdminUrl('/'.$subject->id), [], subjectAdminHeaders());

    $response->assertStatus(409);
    $response->assertJson(['code' => 'SUBJECT_IN_USE']);
    $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
})->skip('Chờ gộp T07 (bảng course_subject/courses chưa tồn tại trong worktree T06)');

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
    expect($log->changes)->toBe(['status' => ['before' => 'active', 'after' => 'hidden']]);
});
