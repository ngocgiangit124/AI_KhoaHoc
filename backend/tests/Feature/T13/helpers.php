<?php

use App\Enums\EnrollmentStatus;
use App\Enums\VideoAssetStatus;
use App\Enums\VideoSource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T11/helpers.php';
require_once __DIR__.'/../T04/helpers.php';

/**
 * Khóa published, 1 chương, 1 bài upload (asset fake `ready`, thời lượng $duration). Học sinh đăng nhập (actingAs),
 * có enrollment active nếu $owned.
 *
 * @return array{student: User, course: Course, chapter: Chapter, lesson: Lesson, asset: VideoAsset}
 */
function vvLearnSet(bool $owned = true, int $duration = 100, array $courseState = []): array
{
    vvVideoFake();
    $student = vvActAsStudent(User::factory()->create());
    $course = Course::factory()->published()->create($courseState);
    $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
    $lesson = Lesson::factory()->for($course)->for($chapter)->create([
        'position' => 1,
        'video_source' => VideoSource::Upload,
        'duration_seconds' => $duration,
    ]);
    $asset = VideoAsset::factory()->ready($duration)->create(['provider' => 'fake', 'lesson_id' => $lesson->id]);
    $lesson->forceFill(['video_asset_id' => $asset->id])->save();

    if ($owned) {
        Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    }

    return compact('student', 'course', 'chapter', 'lesson', 'asset');
}

function vvLearnGet(string $path, array $server = []): TestResponse
{
    return test()->withServerVariables($server)->getJson(vvApiUrl($path), vvWebHeaders());
}

function vvHeartbeat(int|Lesson $lesson, int $position, int $delta): TestResponse
{
    $id = $lesson instanceof Lesson ? $lesson->id : $lesson;

    return test()->postJson(vvApiUrl("/learn/lessons/{$id}/heartbeat"), [
        'position_seconds' => $position,
        'watched_delta_seconds' => $delta,
    ], vvWebHeaders());
}

function vvProgressRow(User $student, Lesson $lesson): ?object
{
    return DB::table('lesson_progress')->where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();
}

function vvAddLesson(Course $course, Chapter $chapter, int $position, array $attrs = []): Lesson
{
    return Lesson::factory()->for($course)->for($chapter)->create(['position' => $position] + $attrs);
}

function vvSetEnrollment(User $student, Course $course, EnrollmentStatus $status): void
{
    Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->update(['status' => $status->value]);
}

// Dùng cho test chưa có asset ready.
function vvSetAssetStatus(VideoAsset $asset, VideoAssetStatus $status): void
{
    $asset->forceFill(['status' => $status])->save();
}
