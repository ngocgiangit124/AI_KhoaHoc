<?php

use App\Enums\VideoSource;
use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * US-009 — chương/bài/thứ tự (T09, [SEC]: S5 IDOR route lồng, S13 link ngoài).
 */
function cxUrl(string $path): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1/courses'.$path;
}

/** @return array<string, string> */
function cxHeaders(): array
{
    return ['Origin' => config('app.admin_url')];
}

function cxTeacherOf(Course $course): User
{
    $teacher = User::factory()->teacher()->create();
    $course->teachers()->attach($teacher->id);

    return $teacher;
}

/**
 * @return array{0: Course, 1: Chapter, 2: Lesson}
 */
function cxCourseWithLesson(): array
{
    $course = Course::factory()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    $lesson = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id, 'position' => 1]);

    return [$course, $chapter, $lesson];
}

/**
 * @return array<string, mixed>
 */
function cxLessonPayload(array $override = []): array
{
    return array_merge([
        'title' => 'Bài 1: Giới thiệu',
        'is_preview' => false,
        'video_source' => 'none',
    ], $override);
}

// --- Chương -----------------------------------------------------------------

test('admin tao chuong: xep cuoi, course_id tu route, ghi audit', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();
    $other = Course::factory()->create();

    $first = $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters"), [
        'title' => '  Chương 1  ',
        'course_id' => $other->id,
        'position' => 99,
    ], cxHeaders());

    $first->assertCreated();
    $first->assertJson(['title' => 'Chương 1', 'position' => 1, 'course_id' => $course->id]);

    $second = $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters"), ['title' => 'Chương 2'], cxHeaders());
    $second->assertCreated()->assertJson(['position' => 2]);

    expect(Chapter::query()->where('course_id', $other->id)->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'chapter.create')->count())->toBe(2);
});

test('tieu de chuong: rong, qua dai, chua HTML bi 422', function (mixed $title) {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters"), ['title' => $title], cxHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('title');
})->with(['', '   ', '<b>x</b>']);

test('tieu de chuong qua 255 ky tu bi 422', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters"), ['title' => str_repeat('a', 256)], cxHeaders())
        ->assertStatus(422);
});

test('GV phu trach tao/sua chuong; GV khong phu trach bi 403 truoc validate', function () {
    $course = Course::factory()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id]);
    $mine = cxTeacherOf($course);
    $stranger = User::factory()->teacher()->create();

    $this->actingAs($mine)->postJson(cxUrl("/{$course->id}/chapters"), ['title' => 'Của tôi'], cxHeaders())->assertCreated();
    $this->actingAs($mine)->putJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), ['title' => 'Đổi'], cxHeaders())->assertOk();

    // Payload sai + không có quyền => 403 (không lộ chi tiết 422).
    $this->actingAs($stranger)->postJson(cxUrl("/{$course->id}/chapters"), ['title' => ''], cxHeaders())->assertForbidden();
    $this->actingAs($stranger)->putJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), ['title' => ''], cxHeaders())->assertForbidden();
    $this->actingAs($stranger)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())->assertForbidden();
    expect($chapter->fresh()->title)->toBe('Đổi');
});

test('hoc sinh 403, khach 401 tren route chuong/bai/thu tu', function () {
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $student = User::factory()->student()->create();
    $urls = [
        ['post', "/{$course->id}/chapters"],
        ['put', "/{$course->id}/chapters/{$chapter->id}"],
        ['delete', "/{$course->id}/chapters/{$chapter->id}"],
        ['put', "/{$course->id}/curriculum/order"],
        ['post', "/{$course->id}/chapters/{$chapter->id}/lessons"],
        ['put', "/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}"],
        ['delete', "/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}"],
    ];

    foreach ($urls as [$method, $path]) {
        $this->actingAs($student)->{$method.'Json'}(cxUrl($path), [], cxHeaders())->assertForbidden();
    }

    auth()->forgetGuards();

    foreach ($urls as [$method, $path]) {
        $this->{$method.'Json'}(cxUrl($path), [], cxHeaders())->assertUnauthorized();
    }
});

test('IDOR chuong: chuong khoa khac qua URL khoa minh => 404 (admin va GV phu trach)', function () {
    $courseA = Course::factory()->create();
    $courseB = Course::factory()->create();
    $chapterB = Chapter::factory()->create(['course_id' => $courseB->id, 'title' => 'Gốc']);
    $admin = User::factory()->admin()->create();
    $teacherA = cxTeacherOf($courseA);

    foreach ([$admin, $teacherA] as $actor) {
        $this->actingAs($actor)->putJson(cxUrl("/{$courseA->id}/chapters/{$chapterB->id}"), ['title' => 'Hack'], cxHeaders())->assertNotFound();
        $this->actingAs($actor)->deleteJson(cxUrl("/{$courseA->id}/chapters/{$chapterB->id}"), [], cxHeaders())->assertNotFound();
        $this->actingAs($actor)->postJson(cxUrl("/{$courseA->id}/chapters/{$chapterB->id}/lessons"), cxLessonPayload(), cxHeaders())->assertNotFound();
    }

    expect($chapterB->fresh()->title)->toBe('Gốc');
    expect(Lesson::query()->count())->toBe(0);

    // GV chỉ phụ trách A, dùng URL khóa B + chương B => 403 (khóa gốc B không phải của họ).
    $this->actingAs($teacherA)->putJson(cxUrl("/{$courseB->id}/chapters/{$chapterB->id}"), ['title' => 'Hack'], cxHeaders())->assertForbidden();
    $this->actingAs($teacherA)->deleteJson(cxUrl("/{$courseB->id}/chapters/{$chapterB->id}"), [], cxHeaders())->assertForbidden();
    $this->actingAs($teacherA)->postJson(cxUrl("/{$courseB->id}/chapters"), ['title' => 'Hack'], cxHeaders())->assertForbidden();
});

test('chuong da xoa mem => 404; xoa chuong rong; xoa chuong cascade bai + audit', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $empty = Chapter::factory()->create(['course_id' => $course->id, 'position' => 2]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$empty->id}"), [], cxHeaders())->assertNoContent();
    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$empty->id}"), [], cxHeaders())->assertNotFound();
    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/chapters/{$empty->id}"), ['title' => 'x'], cxHeaders())->assertNotFound();

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())->assertNoContent();
    expect(Lesson::query()->whereKey($lesson->id)->exists())->toBeFalse();
    expect(Lesson::withTrashed()->whereKey($lesson->id)->exists())->toBeTrue();

    $log = AuditLog::query()->where('action', 'chapter.delete')->latest('id')->first();
    expect($log->changes['lessons_deleted'])->toBe(1);
});

test('xoa chuong con bai bi chan 409 khi khoa co hoc sinh active; chuong rong van xoa duoc', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $empty = Chapter::factory()->create(['course_id' => $course->id, 'position' => 2]);
    Enrollment::factory()->create(['course_id' => $course->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())
        ->assertStatus(409)->assertJsonPath('code', 'CHAPTER_HAS_ACTIVE_LEARNERS');
    expect($chapter->fresh())->not->toBeNull();
    expect($lesson->fresh())->not->toBeNull();

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$empty->id}"), [], cxHeaders())->assertNoContent();
});

test('enrollment khong active (rejected) khong chan xoa chuong', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter] = cxCourseWithLesson();
    Enrollment::factory()->rejected()->create(['course_id' => $course->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())->assertNoContent();
});

// --- Bài học ----------------------------------------------------------------

test('tao bai hoc: course_id/chapter_id tu route, video_asset_id/course_id/chapter_id/position bi bo qua', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $existing] = cxCourseWithLesson();
    $otherCourse = Course::factory()->create();
    $otherChapter = Chapter::factory()->create(['course_id' => $otherCourse->id]);
    $foreignLesson = Lesson::factory()->create(['course_id' => $otherCourse->id, 'chapter_id' => $otherChapter->id]);
    $foreignAsset = VideoAsset::factory()->create(['lesson_id' => $foreignLesson->id]);

    $response = $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload([
        'video_asset_id' => $foreignAsset->id,
        'course_id' => $otherCourse->id,
        'chapter_id' => $otherChapter->id,
        'position' => 50,
        'external_provider' => 'youtube',
        'external_video_id' => 'AAAAAAAAAAA',
    ]), cxHeaders());

    $response->assertCreated();
    $lesson = Lesson::query()->findOrFail($response->json('data.id') ?? $response->json('id'));
    expect($lesson->course_id)->toBe($course->id);
    expect($lesson->chapter_id)->toBe($chapter->id);
    expect($lesson->position)->toBe(2);
    expect($lesson->video_asset_id)->toBeNull();
    expect($lesson->external_video_id)->toBeNull();
    expect($lesson->external_provider)->toBeNull();
    expect($lesson->video_source)->toBe(VideoSource::None);
    expect(AuditLog::query()->where('action', 'lesson.create')->count())->toBe(1);
});

test('bai hoc: validate title / is_preview / video_source', function (array $override, string $errorKey) {
    $admin = User::factory()->admin()->create();
    [$course, $chapter] = cxCourseWithLesson();

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload($override), cxHeaders())
        ->assertStatus(422)->assertJsonValidationErrors($errorKey);
})->with([
    'title rong' => [['title' => ''], 'title'],
    'title html' => [['title' => '<script>x</script>'], 'title'],
    'is_preview thieu' => [['is_preview' => null], 'is_preview'],
    'video_source la' => [['video_source' => 'bunny'], 'video_source'],
    'duration am' => [['duration_seconds' => -1], 'duration_seconds'],
    'duration qua lon' => [['duration_seconds' => 999999], 'duration_seconds'],
    'external_url khi source none' => [['external_url' => 'https://youtu.be/dQw4w9WgXcQ'], 'external_url'],
]);

test('link ngoai: luu provider + ID (khong luu URL), chi khi is_preview', function (string $url, string $provider, string $id) {
    $admin = User::factory()->admin()->create();
    [$course, $chapter] = cxCourseWithLesson();

    $response = $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload([
        'is_preview' => true,
        'video_source' => 'external_link',
        'external_url' => $url,
        'duration_seconds' => 120,
    ]), cxHeaders());

    $response->assertCreated();
    $lesson = Lesson::query()->latest('id')->firstOrFail();
    expect($lesson->video_source)->toBe(VideoSource::ExternalLink);
    expect($lesson->external_provider)->toBe($provider);
    expect($lesson->external_video_id)->toBe($id);
    expect($lesson->duration_seconds)->toBe(120);
    expect($response->getContent())->not->toContain($url);
    expect($response->json('external_embed_url') ?? $response->json('data.external_embed_url'))->toBe(
        $provider === 'youtube'
            ? "https://www.youtube-nocookie.com/embed/{$id}"
            : "https://player.vimeo.com/video/{$id}?dnt=1"
    );
    expect(json_encode(AuditLog::query()->where('action', 'lesson.create')->first()->changes))->not->toContain($id);
})->with([
    'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ?t=5', 'youtube', 'dQw4w9WgXcQ'],
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=x', 'youtube', 'dQw4w9WgXcQ'],
    'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    'nocookie' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    'vimeo' => ['https://vimeo.com/123456789', 'vimeo', '123456789'],
    'vimeo player' => ['https://player.vimeo.com/video/123456789', 'vimeo', '123456789'],
]);

test('link ngoai khong phai preview => 422 (S13)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter] = cxCourseWithLesson();

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload([
        'is_preview' => false,
        'video_source' => 'external_link',
        'external_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]), cxHeaders())->assertStatus(422)->assertJsonValidationErrors('external_url');
});

test('link ngoai: thieu url, host la, scheme la, ID sai => 422 (S13)', function (?string $url) {
    $admin = User::factory()->admin()->create();
    [$course, $chapter] = cxCourseWithLesson();

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload([
        'is_preview' => true,
        'video_source' => 'external_link',
        'external_url' => $url,
    ]), cxHeaders())->assertStatus(422)->assertJsonValidationErrors('external_url');

    expect(Lesson::query()->count())->toBe(1); // chỉ bài có sẵn của helper
})->with([
    'null' => [null],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'http' => ['http://youtu.be/dQw4w9WgXcQ'],
    'host la' => ['https://evil.com/watch?v=dQw4w9WgXcQ'],
    'host gia' => ['https://youtube.com.evil.io/watch?v=dQw4w9WgXcQ'],
    'host hau to' => ['https://evilyoutube.com/watch?v=dQw4w9WgXcQ'],
    'userinfo' => ['https://youtube.com@evil.com/watch?v=dQw4w9WgXcQ'],
    'userinfo 2' => ['https://youtu.be@evil.com/dQw4w9WgXcQ'],
    'port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'],
    'id ngan' => ['https://youtu.be/short'],
    'id dai + payload' => ['https://youtu.be/dQw4w9WgXcQ"onload="x'],
    'id co ky tu la' => ['https://www.youtube.com/watch?v=dQw4w9WgXc%22'],
    'vimeo id chu' => ['https://vimeo.com/abcdefgh'],
    'vimeo id ngan' => ['https://vimeo.com/12345'],
    'vimeo path lat' => ['https://vimeo.com/channels/staffpicks/123456789'],
    'khoang trang' => ['https://youtu.be/dQw4w9WgXcQ evil'],
    'xuong dong' => ["https://youtu.be/dQw4w9WgXcQ\nhttps://evil.com"],
    'backslash' => ['https://youtu.be\\@evil.com/dQw4w9WgXcQ'],
    'host dau cham cuoi' => ['https://youtube.com./watch?v=dQw4w9WgXcQ'],
    'fragment @' => ['https://evil.com#@youtu.be/dQw4w9WgXcQ'],
    'query @' => ['https://evil.com?@youtu.be/dQw4w9WgXcQ'],
    // NBSP ở CUỐI bị middleware TrimStrings của Laravel cắt trước (vô hại); ở GIỮA thì phải bị loại.
    'NBSP giua' => ["https://youtu.be/dQw4w\u{00A0}9WgXcQ"],
    'host Cyrillic' => ["https://www.y\u{043E}utube.com/watch?v=dQw4w9WgXcQ"],
    'so Vimeo Unicode' => ["https://vimeo.com/\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}"],
]);

test('bai upload: bo qua duration tu request, chua co asset', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter] = cxCourseWithLesson();

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload([
        'video_source' => 'upload',
        'duration_seconds' => 500,
    ]), cxHeaders())->assertCreated();

    $lesson = Lesson::query()->latest('id')->firstOrFail();
    expect($lesson->video_source)->toBe(VideoSource::Upload);
    expect($lesson->duration_seconds)->toBeNull();
    expect($lesson->video_asset_id)->toBeNull();
});

test('sua bai: doi nguon video giu/go asset dung quy tac; PUT khong doi chapter/course', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $otherChapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 2]);
    $asset = VideoAsset::factory()->create(['lesson_id' => $lesson->id]);
    $lesson->forceFill(['video_source' => VideoSource::Upload, 'video_asset_id' => $asset->id, 'duration_seconds' => 300])->save();
    $url = cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}");

    // Giữ upload: asset + duration (do webhook) được giữ nguyên; payload gian lận bị bỏ qua.
    $this->actingAs($admin)->putJson($url, cxLessonPayload([
        'title' => 'Đổi tên', 'video_source' => 'upload', 'duration_seconds' => 1,
        'video_asset_id' => 999, 'chapter_id' => $otherChapter->id, 'course_id' => 999,
    ]), cxHeaders())->assertOk();
    $fresh = $lesson->fresh();
    expect($fresh->title)->toBe('Đổi tên');
    expect($fresh->video_asset_id)->toBe($asset->id);
    expect($fresh->duration_seconds)->toBe(300);
    expect($fresh->chapter_id)->toBe($chapter->id);
    expect($fresh->course_id)->toBe($course->id);

    // Chuyển sang link ngoài (preview): gỡ asset.
    $this->actingAs($admin)->putJson($url, cxLessonPayload([
        'is_preview' => true, 'video_source' => 'external_link', 'external_url' => 'https://vimeo.com/123456789', 'duration_seconds' => 60,
    ]), cxHeaders())->assertOk();
    $fresh = $lesson->fresh();
    expect($fresh->video_source)->toBe(VideoSource::ExternalLink);
    expect($fresh->video_asset_id)->toBeNull();
    expect($fresh->external_video_id)->toBe('123456789');

    // Bỏ preview mà vẫn giữ link ngoài => 422; đổi sang none => xoá link.
    $this->actingAs($admin)->putJson($url, cxLessonPayload([
        'is_preview' => false, 'video_source' => 'external_link', 'external_url' => 'https://vimeo.com/123456789',
    ]), cxHeaders())->assertStatus(422);
    $this->actingAs($admin)->putJson($url, cxLessonPayload(['is_preview' => false, 'video_source' => 'none']), cxHeaders())->assertOk();
    $fresh = $lesson->fresh();
    expect($fresh->external_video_id)->toBeNull();
    expect($fresh->external_provider)->toBeNull();
    expect($fresh->duration_seconds)->toBeNull();

    expect(AuditLog::query()->where('action', 'lesson.update')->count())->toBe(3);
});

test('IDOR bai: bai chuong khac / khoa khac qua URL sai => 404; GV khoa khac => 403', function () {
    [$courseA, $chapterA, $lessonA] = cxCourseWithLesson();
    [$courseB, $chapterB, $lessonB] = cxCourseWithLesson();
    $chapterA2 = Chapter::factory()->create(['course_id' => $courseA->id, 'position' => 2]);
    $admin = User::factory()->admin()->create();
    $teacherA = cxTeacherOf($courseA);

    foreach ([$admin, $teacherA] as $actor) {
        // Bài B qua khóa A + chương A.
        $this->actingAs($actor)->putJson(cxUrl("/{$courseA->id}/chapters/{$chapterA->id}/lessons/{$lessonB->id}"), cxLessonPayload(['title' => 'Hack']), cxHeaders())->assertNotFound();
        $this->actingAs($actor)->deleteJson(cxUrl("/{$courseA->id}/chapters/{$chapterA->id}/lessons/{$lessonB->id}"), [], cxHeaders())->assertNotFound();
        // Bài B qua khóa A + chương B (chương không thuộc khóa A).
        $this->actingAs($actor)->putJson(cxUrl("/{$courseA->id}/chapters/{$chapterB->id}/lessons/{$lessonB->id}"), cxLessonPayload(['title' => 'Hack']), cxHeaders())->assertNotFound();
        // Bài A qua chương A2 (cùng khóa nhưng sai chương).
        $this->actingAs($actor)->putJson(cxUrl("/{$courseA->id}/chapters/{$chapterA2->id}/lessons/{$lessonA->id}"), cxLessonPayload(['title' => 'Hack']), cxHeaders())->assertNotFound();
        $this->actingAs($actor)->deleteJson(cxUrl("/{$courseA->id}/chapters/{$chapterA2->id}/lessons/{$lessonA->id}"), [], cxHeaders())->assertNotFound();
    }

    expect($lessonB->fresh()->title)->not->toBe('Hack');
    expect($lessonA->fresh()->title)->not->toBe('Hack');

    // GV chỉ phụ trách A dùng URL khóa B đầy đủ => 403, kể cả payload sai.
    $base = "/{$courseB->id}/chapters/{$chapterB->id}/lessons";
    $this->actingAs($teacherA)->postJson(cxUrl($base), [], cxHeaders())->assertForbidden();
    $this->actingAs($teacherA)->putJson(cxUrl("{$base}/{$lessonB->id}"), [], cxHeaders())->assertForbidden();
    $this->actingAs($teacherA)->deleteJson(cxUrl("{$base}/{$lessonB->id}"), [], cxHeaders())->assertForbidden();
    expect($lessonB->fresh())->not->toBeNull();
});

test('xoa bai: xoa mem + audit; bai da xoa => 404', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $url = cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}");

    $this->actingAs($admin)->deleteJson($url, [], cxHeaders())->assertNoContent();
    expect(Lesson::withTrashed()->whereKey($lesson->id)->exists())->toBeTrue();
    expect(Lesson::query()->whereKey($lesson->id)->exists())->toBeFalse();
    expect(AuditLog::query()->where('action', 'lesson.delete')->count())->toBe(1);

    $this->actingAs($admin)->deleteJson($url, [], cxHeaders())->assertNotFound();
    $this->actingAs($admin)->putJson($url, cxLessonPayload(), cxHeaders())->assertNotFound();
});

test('GV phu trach quan ly bai hoc cua khoa minh', function () {
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $teacher = cxTeacherOf($course);
    $base = "/{$course->id}/chapters/{$chapter->id}/lessons";

    $this->actingAs($teacher)->postJson(cxUrl($base), cxLessonPayload(), cxHeaders())->assertCreated();
    $this->actingAs($teacher)->putJson(cxUrl("{$base}/{$lesson->id}"), cxLessonPayload(['title' => 'GV sửa']), cxHeaders())->assertOk();
    $this->actingAs($teacher)->deleteJson(cxUrl("{$base}/{$lesson->id}"), [], cxHeaders())->assertNoContent();
});

// --- Sắp xếp (S5) -----------------------------------------------------------

/**
 * @return array{0: Course, 1: array<int, Chapter>, 2: array<int, Lesson>}
 */
function cxTree(): array
{
    $course = Course::factory()->create();
    $chapters = [];
    $lessons = [];

    foreach ([1, 2] as $c) {
        $chapters[$c] = Chapter::factory()->create(['course_id' => $course->id, 'position' => $c]);
    }

    foreach ([[1, 1], [1, 2], [2, 1]] as $i => [$c, $p]) {
        $lessons[$i + 1] = Lesson::factory()->create([
            'course_id' => $course->id, 'chapter_id' => $chapters[$c]->id, 'position' => $p,
        ]);
    }

    return [$course, $chapters, $lessons];
}

test('sap xep: doi thu tu chuong, bai va chuyen bai sang chuong khac (AC8)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch, $ls] = cxTree();

    $response = $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[2]->id, $ls[3]->id]],
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id]],
    ], cxHeaders());

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$ch[2]->id, $ch[1]->id]);
    expect(collect($response->json('data.0.lessons'))->pluck('id')->all())->toBe([$ls[2]->id, $ls[3]->id]);

    expect($ch[2]->fresh()->position)->toBe(1);
    expect($ch[1]->fresh()->position)->toBe(2);
    expect($ls[2]->fresh()->chapter_id)->toBe($ch[2]->id);
    expect($ls[2]->fresh()->position)->toBe(1);
    expect($ls[3]->fresh()->position)->toBe(2);
    expect($ls[1]->fresh()->chapter_id)->toBe($ch[1]->id);
    expect(AuditLog::query()->where('action', 'curriculum.reorder')->count())->toBe(1);
});

test('sap xep: chuong rong van duoc (chuyen het bai ra)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch, $ls] = cxTree();

    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, $ls[3]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => []],
    ], cxHeaders())->assertOk();

    expect($ls[3]->fresh()->chapter_id)->toBe($ch[1]->id);
    expect($ls[3]->fresh()->position)->toBe(3);
});

test('sap xep sai tap ID => 422 va KHONG doi gi (S5)', function (Closure $payload) {
    $admin = User::factory()->admin()->create();
    [$course, $ch, $ls] = cxTree();
    [, $otherChapters, $otherLessons] = cxTree();
    $deleted = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $ch[1]->id, 'position' => 9]);
    $deleted->delete();

    $before = [
        Chapter::query()->orderBy('id')->pluck('position', 'id')->all(),
        Lesson::query()->orderBy('id')->get(['id', 'chapter_id', 'position'])->toArray(),
    ];

    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), $payload($ch, $ls, $otherChapters, $otherLessons, $deleted), cxHeaders())
        ->assertStatus(422);

    expect([
        Chapter::query()->orderBy('id')->pluck('position', 'id')->all(),
        Lesson::query()->orderBy('id')->get(['id', 'chapter_id', 'position'])->toArray(),
    ])->toBe($before);
})->with([
    'bai khoa khac' => [fn ($ch, $ls, $oc, $ol) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, $ol[1]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'thay bai bang bai khoa khac' => [fn ($ch, $ls, $oc, $ol) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ol[1]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'chuong khoa khac' => [fn ($ch, $ls, $oc, $ol) => [
        ['chapter_id' => $oc[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'thieu bai' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'thieu chuong' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, $ls[3]->id]],
    ]],
    'body rong' => [fn () => []],
    'bai trung giua 2 chuong' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[2]->id, $ls[3]->id]],
    ]],
    'chuong trung' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, $ls[3]->id]],
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => []],
    ]],
    'bai da xoa mem' => [fn ($ch, $ls, $oc, $ol, $deleted) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, $deleted->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'id khong ton tai' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, 99999999]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'id la chuoi' => [fn ($ch, $ls) => [
        ['chapter_id' => 'abc', 'lesson_ids' => [$ls[1]->id]],
    ]],
    'thieu lesson_ids' => [fn ($ch) => [
        ['chapter_id' => $ch[1]->id],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => []],
    ]],
    'khoa la kem theo' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id, $ls[3]->id], 'course_id' => 5],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => []],
    ]],
    'lesson_ids la object' => [fn ($ch, $ls) => [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => ['a' => $ls[1]->id, 'b' => $ls[2]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ]],
    'doi tuong thay vi danh sach' => [fn ($ch, $ls) => [
        'chapters' => [['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id]]],
    ]],
]);

test('sap xep: chuong da xoa mem khong nam trong tap hop le', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch, $ls] = cxTree();
    $ghost = Chapter::factory()->create(['course_id' => $course->id, 'position' => 3]);
    $ghost->delete();

    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
        ['chapter_id' => $ghost->id, 'lesson_ids' => []],
    ], cxHeaders())->assertStatus(422);
});

test('sap xep: GV khong phu trach 403 ke ca payload sai; GV phu trach duoc; payload khoa khac van 422', function () {
    [$course, $ch, $ls] = cxTree();
    [$courseB, $chB, $lsB] = cxTree();
    $teacherA = cxTeacherOf($course);

    $this->actingAs($teacherA)->putJson(cxUrl("/{$courseB->id}/curriculum/order"), [], cxHeaders())->assertForbidden();
    $this->actingAs($teacherA)->putJson(cxUrl("/{$courseB->id}/curriculum/order"), [
        ['chapter_id' => $chB[2]->id, 'lesson_ids' => [$lsB[3]->id]],
        ['chapter_id' => $chB[1]->id, 'lesson_ids' => [$lsB[1]->id, $lsB[2]->id]],
    ], cxHeaders())->assertForbidden();
    expect($chB[1]->fresh()->position)->toBe(1);

    // GV phụ trách A gửi ID của khóa B vào khóa A => 422.
    $this->actingAs($teacherA)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $chB[1]->id, 'lesson_ids' => [$lsB[1]->id]],
    ], cxHeaders())->assertStatus(422);

    $this->actingAs($teacherA)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[2]->id, $ls[1]->id]],
    ], cxHeaders())->assertOk();
    expect($ch[2]->fresh()->position)->toBe(1);
});

test('sap xep khoa lock: doc courses/chapters/lessons bang FOR UPDATE trong transaction', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch, $ls] = cxTree();
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[2]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ], cxHeaders())->assertOk();

    foreach (['courses', 'chapters', 'lessons'] as $table) {
        expect(collect($queries)->contains(fn ($sql) => str_contains($sql, "from `{$table}`") && str_contains($sql, 'for update')))
            ->toBeTrue("thiếu FOR UPDATE trên {$table}");
    }
});

test('xoa/tao chuong va bai deu khoa hang course + chapter (FOR UPDATE)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    $this->actingAs($admin)->postJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons"), cxLessonPayload(), cxHeaders())->assertCreated();
    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())->assertNoContent();

    foreach (['courses', 'chapters', 'lessons'] as $table) {
        expect(collect($queries)->contains(fn ($sql) => str_contains($sql, "from `{$table}`") && str_contains($sql, 'for update')))
            ->toBeTrue("thiếu FOR UPDATE trên {$table}");
    }
});

// --- Kiến trúc ---------------------------------------------------------------

test('moi route chuong/bai/thu tu la scopeBindings, co can:manageContent, ten admin.', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(
        fn ($r) => str_contains($r->uri(), '/chapters') || str_contains($r->uri(), 'curriculum')
    );

    expect($routes)->toHaveCount(7);

    foreach ($routes as $route) {
        expect($route->enforcesScopedBindings())->toBeTrue("{$route->uri()} thiếu scopeBindings");
        expect($route->gatherMiddleware())->toContain('can:manageContent,course');
        expect($route->getName())->toStartWith('admin.');
    }
});

// --- Review vòng 1: R1 (xoá bài chặn khi có học sinh active) ---------------

test('xoa bai bi chan 409 LESSON_HAS_ACTIVE_LEARNERS khi khoa co hoc sinh active (R1)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    Enrollment::factory()->create(['course_id' => $course->id]);
    $teacher = cxTeacherOf($course);
    $url = cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}");

    foreach ([$admin, $teacher] as $actor) {
        $this->actingAs($actor)->deleteJson($url, [], cxHeaders())
            ->assertStatus(409)->assertJsonPath('code', 'LESSON_HAS_ACTIVE_LEARNERS');
    }

    expect($lesson->fresh())->not->toBeNull();
    expect(AuditLog::query()->where('action', 'lesson.delete')->count())->toBe(0);
});

test('khong the lach chan bang cach xoa tung bai roi xoa chuong (R1)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    Enrollment::factory()->create(['course_id' => $course->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}"), [], cxHeaders())->assertStatus(409);
    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())->assertStatus(409);

    expect(Lesson::query()->whereKey($lesson->id)->exists())->toBeTrue();
    expect(Chapter::query()->whereKey($chapter->id)->exists())->toBeTrue();
});

test('enrollment khong active khong chan xoa bai / chuong (R1)', function (string $state) {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    $second = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id, 'position' => 2]);
    Enrollment::factory()->{$state}()->create(['course_id' => $course->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$second->id}"), [], cxHeaders())->assertNoContent();
    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())->assertNoContent();
})->with(['pendingApproval', 'rejected', 'revoked']);

test('hoc sinh active van cho doi ten / sap xep / them bai, chi chan xoa (R1)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $chapter, $lesson] = cxCourseWithLesson();
    Enrollment::factory()->create(['course_id' => $course->id]);
    $base = "/{$course->id}/chapters/{$chapter->id}/lessons";

    $this->actingAs($admin)->postJson(cxUrl($base), cxLessonPayload(), cxHeaders())->assertCreated();
    $this->actingAs($admin)->putJson(cxUrl("{$base}/{$lesson->id}"), cxLessonPayload(['title' => 'Đổi']), cxHeaders())->assertOk();
    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), ['title' => 'Đổi'], cxHeaders())->assertOk();
});

// --- R2: khóa published phải còn ít nhất 1 bài -------------------------------

test('xoa bai cuoi cua khoa published bi 409 COURSE_WOULD_BE_EMPTY (R2)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}"), [], cxHeaders())
        ->assertStatus(409)->assertJsonPath('code', 'COURSE_WOULD_BE_EMPTY');
    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}"), [], cxHeaders())
        ->assertStatus(409)->assertJsonPath('code', 'COURSE_WOULD_BE_EMPTY');

    expect($lesson->fresh())->not->toBeNull();
    expect($chapter->fresh())->not->toBeNull();
});

test('khoa published: xoa bai/chuong khi van con bai khac thi duoc (R2)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->create();
    $ch1 = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    $ch2 = Chapter::factory()->create(['course_id' => $course->id, 'position' => 2]);
    $l1 = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $ch1->id]);
    $l2 = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $ch1->id, 'position' => 2]);
    Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $ch2->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$ch1->id}/lessons/{$l1->id}"), [], cxHeaders())->assertNoContent();
    // ch1 còn l2 nhưng ch2 còn 1 bài => xoá cả chương ch1 được.
    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$ch1->id}"), [], cxHeaders())->assertNoContent();
    expect(Lesson::query()->where('course_id', $course->id)->count())->toBe(1);
    expect(Lesson::query()->whereKey($l2->id)->exists())->toBeFalse();
});

test('khoa published: chuong rong xoa duoc du la chuong cuoi (R2 khong chan chuong rong)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->published()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id]);
    $empty = Chapter::factory()->create(['course_id' => $course->id, 'position' => 2]);
    Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$empty->id}"), [], cxHeaders())->assertNoContent();
});

test('khoa draft / unpublished xoa bai cuoi van duoc (R2)', function (string $state) {
    $admin = User::factory()->admin()->create();
    $course = $state === 'unpublished' ? Course::factory()->unpublished()->create() : Course::factory()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id]);

    $this->actingAs($admin)->deleteJson(cxUrl("/{$course->id}/chapters/{$chapter->id}/lessons/{$lesson->id}"), [], cxHeaders())->assertNoContent();
})->with(['draft', 'unpublished']);

// --- R3: trần kích thước curriculum/order -----------------------------------

test('sap xep: qua 200 chuong => 422 (R3)', function () {
    $admin = User::factory()->admin()->create();
    [$course] = cxTree();
    $payload = array_map(fn (int $i) => ['chapter_id' => $i + 1, 'lesson_ids' => []], range(1, 201));

    $response = $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), $payload, cxHeaders());

    $response->assertStatus(422)->assertJsonValidationErrors('curriculum');
    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->toContain('giới hạn');
});

test('sap xep: qua 2000 bai moi chuong => 422 (R3)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch] = cxTree();

    $response = $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => range(1, 2001)],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => []],
    ], cxHeaders());

    $response->assertStatus(422)->assertJsonValidationErrors('curriculum');
    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->toContain('giới hạn');
});

test('sap xep: dung o tran (200 chuong, 2000 bai) qua validate nhanh, bi tu choi vi sai tap ID (R3)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch] = cxTree();

    $payload = [['chapter_id' => $ch[1]->id, 'lesson_ids' => range(1, 2000)]];
    foreach (range(1, 199) as $i) {
        $payload[] = ['chapter_id' => 1000 + $i, 'lesson_ids' => []];
    }

    $start = microtime(true);
    $response = $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), $payload, cxHeaders());
    $elapsed = microtime(true) - $start;

    $response->assertStatus(422)->assertJsonValidationErrors('curriculum');
    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->not->toContain('giới hạn');
    // Bỏ `distinct` bậc hai: ngưỡng rộng để không flaky, đủ bắt hồi quy (bản cũ ~2s/10k ID).
    expect($elapsed)->toBeLessThan(5.0);
});

test('sap xep: id trung trong cung mot chuong hoac giua cac chuong van bi service chan (thay distinct)', function () {
    $admin = User::factory()->admin()->create();
    [$course, $ch, $ls] = cxTree();

    $this->actingAs($admin)->putJson(cxUrl("/{$course->id}/curriculum/order"), [
        ['chapter_id' => $ch[1]->id, 'lesson_ids' => [$ls[1]->id, $ls[1]->id, $ls[2]->id]],
        ['chapter_id' => $ch[2]->id, 'lesson_ids' => [$ls[3]->id]],
    ], cxHeaders())->assertStatus(422)->assertJsonValidationErrors('curriculum');
});
