<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\VideoSource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Subject;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return list<string> các cột của index (theo thứ tự) trên bảng. */
function vvIndexColumns(string $table): array
{
    return collect(Schema::getIndexes($table))->map(fn ($i) => implode(',', $i['columns']).($i['unique'] ? ' [U]' : ''))->all();
}

function vvSqlErrorCode(Closure $fn): int
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (int) $e->errorInfo[1];
    }

    return 0;
}

test('DBA checklist 3: ket noi dung READ COMMITTED', function () {
    expect(DB::selectOne('SELECT @@transaction_isolation AS level')->level)->toBe('READ-COMMITTED');
});

test('DBA checklist 1: enrollments.live_flag la generated STORED, unique gom live_flag', function () {
    $ddl = DB::selectOne('SHOW CREATE TABLE enrollments');
    $sql = collect((array) $ddl)->last();

    expect($sql)->toContain('GENERATED ALWAYS AS')->toContain('STORED')->toContain('pending_approval')
        ->toContain('UNIQUE KEY `enrollments_user_course_live_unique` (`user_id`,`course_id`,`live_flag`)');
});

test('CHECK chk_courses_grade_level (6..12) o tang DB, loi 3819', function () {
    $creator = User::factory()->teacher()->create();

    foreach ([6, 12] as $ok) {
        expect(Course::factory()->create(['grade_level' => $ok, 'created_by' => $creator->id])->grade_level)->toBe($ok);
    }

    foreach ([5, 13, 0] as $bad) {
        expect(vvSqlErrorCode(fn () => Course::factory()->create(['grade_level' => $bad, 'created_by' => $creator->id])))->toBe(3819);
    }

    expect(collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_courses_grade_level'")))->toHaveCount(1);
});

test('DBA checklist 2: enrollments chong trung 1 dong "song" (pending/active) cho cung user+khoa', function () {
    $user = User::factory()->student()->create();
    $course = Course::factory()->create();

    $e1 = Enrollment::factory()->pendingApproval()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    expect($e1->fresh()->getAttribute('live_flag'))->toBe(1);

    // pending đã có -> active thứ 2 cũng trùng.
    expect(vvSqlErrorCode(fn () => Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id])))->toBe(1062);
    expect(Enrollment::query()->where('user_id', $user->id)->count())->toBe(1);

    // Bị từ chối -> live_flag NULL -> cho phép gửi lại (US-012 AC5) rồi mua lại sau hoàn tiền.
    $e1->forceFill(['status' => EnrollmentStatus::Rejected])->save();
    expect($e1->fresh()->getAttribute('live_flag'))->toBeNull();

    $e2 = Enrollment::factory()->pendingApproval()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    $e2->forceFill(['status' => EnrollmentStatus::Revoked])->save();
    Enrollment::factory()->rejected()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

    expect(Enrollment::query()->where('user_id', $user->id)->count())->toBe(4)
        ->and(vvSqlErrorCode(fn () => Enrollment::factory()->pendingApproval()->create(['user_id' => $user->id, 'course_id' => $course->id])))->toBe(1062);

    // Khoá học khác của cùng HS không bị ảnh hưởng.
    Enrollment::factory()->create(['user_id' => $user->id]);
});

test('enrollment: status/source khong mass-assign duoc (S17)', function () {
    expect(fn () => new Enrollment(['user_id' => 1, 'course_id' => 1, 'status' => 'active']))->toThrow(MassAssignmentException::class);
    expect(fn () => new Course(['title' => 't', 'status' => 'published']))->toThrow(MassAssignmentException::class);
    expect(fn () => new Course(['title' => 't', 'enrollments_count' => 9]))->toThrow(MassAssignmentException::class);
    expect(fn () => new Course(['title' => 't', 'thumbnail_path' => '../x.png']))->toThrow(MassAssignmentException::class);
});

test('Course: cast enum, search_text khong dau, soft delete, quan he', function () {
    $teacher = User::factory()->teacher()->create();
    $subject = Subject::factory()->create();
    $course = Course::factory()->published()->create(['title' => 'Đại số lớp 9', 'short_description' => 'Ôn thi vào Lớp 10', 'created_by' => $teacher->id]);
    $course->subjects()->attach($subject);
    $course->teachers()->attach($teacher, ['added_by' => $teacher->id]);

    $course = Course::query()->with(['subjects', 'teachers', 'creator'])->findOrFail($course->id);
    expect($course->status)->toBe(CourseStatus::Published)
        ->and($course->search_text)->toBe('dai so lop 9 on thi vao lop 10')
        ->and($course->subjects)->toHaveCount(1)
        ->and($course->teachers->first()->id)->toBe($teacher->id)
        ->and($course->creator->id)->toBe($teacher->id);

    $course->update(['title' => 'Hình học']);
    expect($course->fresh()->search_text)->toBe('hinh hoc on thi vao lop 10');

    $course->delete();
    expect(Course::query()->count())->toBe(0)->and(Course::withTrashed()->count())->toBe(1);
});

test('course_subject/course_teacher: PK kep chong trung; xoa cung khoa hoc go lien ket; user/subject bi restrict', function () {
    $course = Course::factory()->create();
    $subject = Subject::factory()->create();
    $teacher = User::factory()->teacher()->create();

    $course->subjects()->attach($subject);
    $course->teachers()->attach($teacher);

    expect(vvSqlErrorCode(fn () => DB::table('course_subject')->insert(['course_id' => $course->id, 'subject_id' => $subject->id])))->toBe(1062)
        ->and(vvSqlErrorCode(fn () => DB::table('course_teacher')->insert(['course_id' => $course->id, 'user_id' => $teacher->id])))->toBe(1062)
        ->and(vvSqlErrorCode(fn () => DB::table('subjects')->where('id', $subject->id)->delete()))->toBe(1451)
        ->and(vvSqlErrorCode(fn () => DB::table('users')->where('id', $teacher->id)->delete()))->toBe(1451);

    DB::table('courses')->where('id', $course->id)->delete();
    expect(DB::table('course_subject')->count())->toBe(0)->and(DB::table('course_teacher')->count())->toBe(0);
});

test('chapters/lessons: factory giu course_id khop chuong, cast enum, soft delete, thu tu', function () {
    $course = Course::factory()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    $lesson = Lesson::factory()->external('youtube', 'dQw4w9WgXcQ')->create(['chapter_id' => $chapter->id, 'position' => 1]);

    expect($lesson->course_id)->toBe($course->id)
        ->and($lesson->video_source)->toBe(VideoSource::ExternalLink)
        ->and($lesson->is_preview)->toBeFalse()
        ->and($course->lessons()->count())->toBe(1)
        ->and($chapter->lessons()->count())->toBe(1);

    $lesson->delete();
    expect($course->lessons()->count())->toBe(0)->and(Lesson::withTrashed()->count())->toBe(1);

    // lessons.course_id/chapter_id không mass-assign (S5).
    expect(fn () => new Lesson(['title' => 'x', 'course_id' => 1]))->toThrow(MassAssignmentException::class);
    expect(fn () => new Lesson(['title' => 'x', 'video_asset_id' => 1]))->toThrow(MassAssignmentException::class);
});

test('video_assets: unique (provider, provider_video_id); lessons.video_asset_id ON DELETE SET NULL', function () {
    $lesson = Lesson::factory()->create();
    $asset = VideoAsset::factory()->ready(120)->create(['lesson_id' => $lesson->id, 'provider_video_id' => 'abc']);
    $lesson->forceFill(['video_source' => VideoSource::Upload, 'video_asset_id' => $asset->id])->save();

    expect($lesson->fresh()->videoAsset->id)->toBe($asset->id)
        ->and($asset->lesson->id)->toBe($lesson->id)
        ->and(vvSqlErrorCode(fn () => VideoAsset::factory()->create(['lesson_id' => $lesson->id, 'provider_video_id' => 'abc'])))->toBe(1062);

    // FK vòng được phá bằng SET NULL: xoá asset thì bài về chưa có video, không lỗi.
    $asset->delete();
    expect($lesson->fresh()->video_asset_id)->toBeNull();

    // Không gán video_asset_id trỏ tới asset không tồn tại.
    expect(vvSqlErrorCode(fn () => $lesson->forceFill(['video_asset_id' => 999999])->save()))->toBe(1452);
});

test('lesson_progress: unique (user, lesson), khong FK course_id, status cast', function () {
    $user = User::factory()->student()->create();
    $lesson = Lesson::factory()->create();

    $progress = LessonProgress::factory()->create(['user_id' => $user->id, 'lesson_id' => $lesson->id]);
    expect($progress->course_id)->toBe($lesson->course_id)
        ->and($progress->status->value)->toBe('in_progress')
        ->and(vvSqlErrorCode(fn () => LessonProgress::factory()->create(['user_id' => $user->id, 'lesson_id' => $lesson->id])))->toBe(1062);

    expect(LessonProgress::factory()->completed()->make()->completed_at)->not->toBeNull();

    $fkTables = collect(Schema::getForeignKeys('lesson_progress'))->map(fn ($f) => $f['foreign_table'])->all();
    expect($fkTables)->toContain('users', 'lessons')->not->toContain('courses');
});

test('chi muc theo goi y DBA/data-model ton tai', function () {
    expect(vvIndexColumns('courses'))->toContain('slug [U]', 'status,grade_level')
        ->and(vvIndexColumns('subjects'))->toContain('name [U]', 'slug [U]')
        ->and(vvIndexColumns('chapters'))->toContain('course_id,position')
        ->and(vvIndexColumns('lessons'))->toContain('chapter_id,position', 'course_id')
        ->and(vvIndexColumns('video_assets'))->toContain('provider,provider_video_id [U]', 'status,updated_at', 'created_by,created_at', 'lesson_id')
        ->and(vvIndexColumns('enrollments'))->toContain('user_id,course_id,live_flag [U]', 'course_id,status,requested_at', 'user_id,status,last_accessed_at', 'order_id')
        ->and(vvIndexColumns('lesson_progress'))->toContain('user_id,lesson_id [U]', 'user_id,course_id,status', 'user_id,course_id,last_accessed_at');
});

test('truy van "Khoa hoc cua toi" va danh sach cho duyet dung index (khong full scan)', function () {
    $plans = [
        DB::select('EXPLAIN SELECT * FROM enrollments WHERE user_id = 1 AND status = \'active\' ORDER BY last_accessed_at DESC'),
        DB::select('EXPLAIN SELECT * FROM enrollments WHERE course_id = 1 AND status = \'pending_approval\' ORDER BY requested_at ASC'),
        DB::select('EXPLAIN SELECT * FROM lesson_progress WHERE user_id = 1 AND course_id = 1 AND status = \'completed\''),
        DB::select('EXPLAIN SELECT * FROM courses WHERE status = \'published\' AND grade_level = 9 AND deleted_at IS NULL'),
    ];

    foreach ($plans as $plan) {
        expect($plan[0]->type)->not->toBe('ALL');
    }
});
