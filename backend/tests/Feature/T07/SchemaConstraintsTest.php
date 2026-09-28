<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Models\Subject;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DBA checklist §5 mục 1 (docs/db/design-review.md): xác nhận CHECK constraint
 * và generated column hoạt động đúng như `SHOW CREATE TABLE` (kiểm bằng tay
 * khi migrate local — xem docs/db/T07-checklist.md).
 */
test('chk_courses_grade_level tu choi grade_level ngoai 6-12', function (int $grade) {
    $creator = User::factory()->admin()->create();

    expect(fn () => DB::table('courses')->insert([
        'title' => 'Khoá test',
        'slug' => 'khoa-test-'.$grade,
        'grade_level' => $grade,
        'status' => CourseStatus::Draft->value,
        'created_by' => $creator->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([5, 13, 0, -1]);

test('chk_courses_grade_level chap nhan grade_level tu 6 den 12', function (int $grade) {
    $course = Course::factory()->create(['grade_level' => $grade]);

    expect($course->grade_level)->toBe($grade);
})->with([6, 9, 12]);

test('enrollments.live_flag la generated STORED dung cong thuc', function () {
    $active = Enrollment::factory()->create(['status' => EnrollmentStatus::Active]);
    $pending = Enrollment::factory()->pendingApproval()->create();
    $rejected = Enrollment::factory()->rejected()->create();
    $revoked = Enrollment::factory()->revoked()->create();

    expect($active->fresh()->live_flag)->toBe(1)
        ->and($pending->fresh()->live_flag)->toBe(1)
        ->and($rejected->fresh()->live_flag)->toBeNull()
        ->and($revoked->fresh()->live_flag)->toBeNull();
});

/**
 * U(user_id, course_id, live_flag) — DBA checklist §5 mục 2: race 2 dòng
 * "đang sống" (pending_approval/active) cho CÙNG (user, course) phải bị DB
 * từ chối (1062 Duplicate entry), mô phỏng 2 request gần như đồng thời.
 */
test('unique user_id-course_id-live_flag chan 2 enrollment dang song cho cung khoa', function () {
    $user = User::factory()->create();
    $course = Course::factory()->create();

    Enrollment::factory()->pendingApproval()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    expect(fn () => Enrollment::factory()->pendingApproval()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]))->toThrow(QueryException::class, '1062');
});

test('unique user_id-course_id-live_flag khong chan gui lai sau khi bi tu choi', function () {
    $user = User::factory()->create();
    $course = Course::factory()->create();

    Enrollment::factory()->rejected()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    $second = Enrollment::factory()->pendingApproval()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    expect($second->exists)->toBeTrue();
});

test('unique user_id-course_id-live_flag khong chan mua lai sau khi bi thu hoi', function () {
    $user = User::factory()->create();
    $course = Course::factory()->create();

    Enrollment::factory()->revoked()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    $second = Enrollment::factory()->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'status' => EnrollmentStatus::Active,
        'source' => EnrollmentSource::Purchase,
    ]);

    expect($second->exists)->toBeTrue();
});

test('lesson_progress unique user_id-lesson_id', function () {
    $progress = LessonProgress::factory()->create();

    expect(fn () => LessonProgress::factory()->create([
        'user_id' => $progress->user_id,
        'lesson_id' => $progress->lesson_id,
        'course_id' => $progress->course_id,
    ]))->toThrow(QueryException::class, '1062');
});

test('video_assets unique provider-provider_video_id', function () {
    $asset = VideoAsset::factory()->create(['provider' => 'internal', 'provider_video_id' => 'abc-123']);

    expect(fn () => VideoAsset::factory()->create([
        'provider' => 'internal',
        'provider_video_id' => 'abc-123',
        'lesson_id' => $asset->lesson_id,
    ]))->toThrow(QueryException::class, '1062');
});

test('course_subject chan xoa cung chuyen de dang duoc gan (restrict)', function () {
    $subject = Subject::factory()->create();
    $course = Course::factory()->create();
    $course->subjects()->attach($subject);

    expect(fn () => DB::table('subjects')->where('id', $subject->id)->delete())
        ->toThrow(QueryException::class);
});

/**
 * DBA checklist §5 mục 7 (design-review §2.8) — xác nhận trên bảng THẬT
 * `subjects` (không phải bảng tạm) hành vi unique bỏ dấu của
 * utf8mb4_0900_ai_ci: "Hình học" và "Hinh hoc" bị coi là trùng (US-011 BR1).
 */
test('subjects.name unique bo qua dau (utf8mb4_0900_ai_ci)', function () {
    Subject::factory()->create(['name' => 'Hình học']);

    expect(fn () => DB::table('subjects')->insert([
        'name' => 'Hinh hoc',
        'slug' => 'hinh-hoc-2',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, '1062');
});
