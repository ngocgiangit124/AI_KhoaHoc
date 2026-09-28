<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Str;

/**
 * S17 — cột trạng thái/quyền không bao giờ nằm trong $fillable (data-model §0).
 * `Model::shouldBeStrict()` đang bật ở testing (AppServiceProvider) → mọi khoá
 * ngoài $fillable ném `MassAssignmentException` thay vì âm thầm bỏ qua (mạnh
 * hơn yêu cầu tối thiểu của S17). Bổ sung cho `tests/Arch/ModelsTest.php`
 * (chỉ kiểm `$guarded != []`).
 */
test('Course::create nem MassAssignmentException khi mang chua status/manual_order/enrollments_count/created_by', function () {
    $attacker = User::factory()->admin()->create();

    expect(fn () => Course::create([
        'title' => 'Khoá học',
        'slug' => 'khoa-hoc-test',
        'grade_level' => 8,
        'status' => 'published',
        'manual_order' => 1,
        'enrollments_count' => 999,
        'created_by' => $attacker->id,
    ]))->toThrow(MassAssignmentException::class);
});

test('fillable cua Course khong bao gio chua cot trang thai', function () {
    $forbidden = ['status', 'published_at', 'manual_order', 'enrollments_count', 'search_text', 'created_by'];
    $fillable = (new Course)->getFillable();

    foreach ($forbidden as $key) {
        expect($fillable)->not->toContain($key);
    }
});

test('Enrollment::create nem MassAssignmentException khi mang chua status/approved_by', function () {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $staff = User::factory()->admin()->create();

    expect(fn () => Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'source' => 'free_approval',
        'status' => 'active',
        'approved_by' => $staff->id,
    ]))->toThrow(MassAssignmentException::class);
});

test('fillable cua Enrollment khong bao gio chua cot trang thai', function () {
    $forbidden = ['status', 'approved_by', 'approved_at', 'activated_at', 'revoked_at', 'revoked_reason', 'live_flag'];
    $fillable = (new Enrollment)->getFillable();

    foreach ($forbidden as $key) {
        expect($fillable)->not->toContain($key);
    }
});

test('Lesson::create nem MassAssignmentException khi mang chua video_source/video_asset_id (S13)', function () {
    $chapter = Chapter::factory()->create();
    $asset = VideoAsset::factory()->create();

    expect(fn () => Lesson::create([
        'course_id' => $chapter->course_id,
        'chapter_id' => $chapter->id,
        'title' => 'Bài học',
        'position' => 1,
        'video_source' => 'upload',
        'video_asset_id' => $asset->id,
    ]))->toThrow(MassAssignmentException::class);
});

test('fillable cua Lesson khong bao gio chua video_source/video_asset_id/external_*', function () {
    $forbidden = ['video_source', 'video_asset_id', 'external_provider', 'external_video_id', 'duration_seconds'];
    $fillable = (new Lesson)->getFillable();

    foreach ($forbidden as $key) {
        expect($fillable)->not->toContain($key);
    }
});

test('LessonProgress::create nem MassAssignmentException khi mang chua status/watched_seconds', function () {
    $lesson = Lesson::factory()->create();
    $user = User::factory()->create();

    expect(fn () => LessonProgress::create([
        'user_id' => $user->id,
        'lesson_id' => $lesson->id,
        'course_id' => $lesson->course_id,
        'last_accessed_at' => now(),
        'status' => 'completed',
        'watched_seconds' => 99999,
    ]))->toThrow(MassAssignmentException::class);
});

test('VideoAsset::create nem MassAssignmentException khi mang chua status', function () {
    $lesson = Lesson::factory()->create();
    $creator = User::factory()->admin()->create();

    expect(fn () => VideoAsset::create([
        'provider' => 'internal',
        'provider_library_id' => '1',
        'provider_video_id' => (string) Str::uuid(),
        'lesson_id' => $lesson->id,
        'declared_size_bytes' => 1000,
        'created_by' => $creator->id,
        'status' => 'ready',
    ]))->toThrow(MassAssignmentException::class);
});
