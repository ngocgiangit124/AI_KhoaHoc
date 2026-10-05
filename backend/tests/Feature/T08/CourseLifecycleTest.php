<?php

use App\Enums\CourseStatus;
use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Courses\CourseTeacherService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

test('AC2: publish khoa du chuong/bai -> published, published_at lan dau, audit; publish lai -> 409', function (string $state) {
    $actor = vvCourseActor($state);
    $course = vvCourseWithContent();

    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")
        ->assertOk()->assertJsonPath('status', 'published')->assertJsonPath('lessons_count', 1);

    $first = $course->fresh()->published_at;
    expect($first)->not->toBeNull();
    $log = AuditLog::query()->where('action', 'course.publish')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($actor->id)->and($log->subject_id)->toBe($course->id);

    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');

    // ngừng bán rồi xuất bản lại: giữ published_at lần đầu
    vvCourseJson('POST', "/admin/courses/{$course->id}/unpublish")->assertOk()->assertJsonPath('status', 'unpublished');
    $this->travel(10)->minutes();
    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertOk();
    expect($course->fresh()->published_at->equalTo($first))->toBeTrue();
})->with(['admin', 'pageManager']);

test('AC3: publish khoa chua co chuong/bai (hoac chi co chuong, chuong/bai da xoa) -> 422 thong diep ro', function () {
    vvCourseActor();
    $empty = Course::factory()->create();
    $onlyChapter = Course::factory()->create();
    Chapter::factory()->for($onlyChapter)->create();
    $deleted = vvCourseWithContent();
    $deleted->lessons()->delete();

    foreach ([$empty, $onlyChapter, $deleted] as $course) {
        vvCourseJson('POST', "/admin/courses/{$course->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'COURSE_NOT_PUBLISHABLE')
            ->assertJsonPath('message', 'Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.');
        expect($course->fresh()->status)->toBe(CourseStatus::Draft);
    }

    // bài thuộc chương đã xoá mềm cũng không tính
    $orphan = vvCourseWithContent();
    $orphan->chapters()->delete();
    vvCourseJson('POST', "/admin/courses/{$orphan->id}/publish")->assertStatus(422);
});

test('unpublish: khoa draft -> 409 INVALID_COURSE_STATE; khoa da ngung ban -> 409 ALREADY_PROCESSED', function () {
    vvCourseActor();
    $draft = Course::factory()->create();
    $off = Course::factory()->unpublished()->create();

    vvCourseJson('POST', "/admin/courses/{$draft->id}/unpublish")->assertStatus(409)->assertJsonPath('code', 'INVALID_COURSE_STATE');
    vvCourseJson('POST', "/admin/courses/{$off->id}/unpublish")->assertStatus(409)->assertJsonPath('code', 'ALREADY_PROCESSED');
});

test('AC4: khoa co enrollment -> khong xoa duoc (409), van nguyen; unpublish van duoc', function (string $state) {
    vvCourseActor();
    $course = vvCourseWithContent(['status' => CourseStatus::Published, 'published_at' => now()]);
    vvEnroll($course, $state);

    vvCourseJson('DELETE', "/admin/courses/{$course->id}")->assertStatus(409)->assertJsonPath('code', 'COURSE_HAS_ENROLLMENTS');
    expect(Course::query()->whereKey($course->id)->exists())->toBeTrue()
        ->and(Chapter::query()->where('course_id', $course->id)->count())->toBe(1);

    vvCourseJson('POST', "/admin/courses/{$course->id}/unpublish")->assertOk()->assertJsonPath('status', 'unpublished');
})->with(['active', 'pending', 'rejected']);

test('AC5: khoa chua co enrollment -> xoa mem ca chuong/bai, het hien thi, audit, 404 khi xoa lai', function () {
    $admin = vvCourseActor();
    $course = vvCourseWithContent();

    vvCourseJson('DELETE', "/admin/courses/{$course->id}")->assertNoContent();

    expect(Course::query()->whereKey($course->id)->exists())->toBeFalse()
        ->and(Course::withTrashed()->whereKey($course->id)->exists())->toBeTrue()
        ->and(Chapter::query()->where('course_id', $course->id)->count())->toBe(0)
        ->and(Chapter::withTrashed()->where('course_id', $course->id)->count())->toBe(1)
        ->and(Lesson::query()->where('course_id', $course->id)->count())->toBe(0)
        ->and(Lesson::withTrashed()->where('course_id', $course->id)->count())->toBe(1);

    $ids = collect(vvAdminGet('/admin/courses?per_page=50')->json('data'))->pluck('id');
    expect($ids)->not->toContain($course->id);
    vvAdminGet("/admin/courses/{$course->id}")->assertNotFound();
    vvCourseJson('DELETE', "/admin/courses/{$course->id}")->assertNotFound();

    $log = AuditLog::query()->where('action', 'course.delete')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($course->id);
});

test('gan giao vien: them/bot, >=1, chi role giao_vien dang hoat dong, audit, ca hai cung thay khoa (AC10)', function () {
    $admin = vvCourseActor();
    [$a, $b] = [vvStaffUser('teacher'), vvStaffUser('teacher')];
    $course = vvAssign(Course::factory()->create(), $a);

    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$a->id, $b->id]])
        ->assertOk()->assertJsonCount(2, 'teachers');
    expect(Course::query()->visibleTo($b)->whereKey($course->id)->exists())->toBeTrue()
        ->and(Course::query()->visibleTo($a)->whereKey($course->id)->exists())->toBeTrue();

    $log = AuditLog::query()->where('action', 'course.teachers')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and($log->changes['teacher_ids']['to'])->toBe([min($a->id, $b->id), max($a->id, $b->id)]);
    expect(DB::table('course_teacher')->where('course_id', $course->id)->where('user_id', $b->id)->value('added_by'))->toBe($admin->id);

    // gỡ a, còn b
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$b->id]])->assertOk()->assertJsonCount(1, 'teachers');
    expect(Course::query()->visibleTo($a)->whereKey($course->id)->exists())->toBeFalse();

    // không để khóa mồ côi / sai vai trò
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => []])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", [])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [User::factory()->student()->create()->id]])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [vvStaffUser('pageManager')->id]])->assertStatus(422);
    expect($course->teachers()->pluck('users.id')->all())->toBe([$b->id]);
});

test('CourseTeacherService: tu choi danh sach rong va role sai ngay ca khi goi thang (khong qua Request)', function () {
    $admin = vvStaffUser('admin');
    $course = vvAssign(Course::factory()->create(), $t = vvStaffUser('teacher'));
    $service = app(CourseTeacherService::class);

    expect(fn () => $service->sync($course, [], $admin))->toThrow(ValidationException::class);
    expect(fn () => $service->sync($course, [$admin->id], $admin))->toThrow(ValidationException::class);
    expect(fn () => $service->sync($course, [$t->id, 999999], $admin))->toThrow(ValidationException::class);
    expect($course->teachers()->pluck('users.id')->all())->toBe([$t->id]);
});

test('khong ghi audit khi gan lai dung danh sach cu', function () {
    vvCourseActor();
    $t = vvStaffUser('teacher');
    $course = vvAssign(Course::factory()->create(), $t);

    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$t->id]])->assertOk();
    expect(AuditLog::query()->where('action', 'course.teachers')->where('subject_id', $course->id)->count())->toBe(0);
});

test('mass-assignment: status/slug/created_by/manual_order/thumbnail_path khong the ghi qua Model::fill', function () {
    $course = new Course(['title' => 'x']);
    foreach (['status', 'slug', 'created_by', 'manual_order', 'thumbnail_path', 'published_at', 'enrollments_count'] as $field) {
        expect(fn () => new Course([$field => 'a']))->toThrow(MassAssignmentException::class);
    }
});
