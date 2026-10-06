<?php

use App\Enums\CourseStatus;
use App\Enums\VideoSource;
use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\VideoAsset;

require_once __DIR__.'/helpers.php';

test('chuong: tao/sua/xoa, position tang dan, audit', function (string $state) {
    $actor = vvCourseActor($state);
    $course = Course::factory()->create();

    $a = vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => '  Chương   1 '])
        ->assertCreated()->assertJsonPath('title', 'Chương 1')->assertJsonPath('position', 1)->assertJsonPath('lessons', []);
    $b = vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'Chương 2'])->assertJsonPath('position', 2);

    vvCourseJson('PUT', "/admin/courses/{$course->id}/chapters/{$a->json('id')}", ['title' => 'Đổi tên'])
        ->assertOk()->assertJsonPath('title', 'Đổi tên');

    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$b->json('id')}")->assertNoContent();
    expect(Chapter::query()->count())->toBe(1)
        ->and(Chapter::withTrashed()->count())->toBe(2);

    // chương mới sau khi xoá: vẫn lớn hơn chương còn lại
    vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'C3'])->assertJsonPath('position', 2);

    foreach (['chapter.create', 'chapter.update', 'chapter.delete'] as $action) {
        $log = AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
        expect($log->actor_id)->toBe($actor->id);
    }
})->with(['admin', 'pageManager']);

test('chuong: validate ten (rong, HTML, qua dai); khong nhan position/course_id', function () {
    vvCourseActor();
    $course = Course::factory()->create();
    $other = Course::factory()->create();

    foreach ([[''], ['<b>x</b>'], [str_repeat('a', 256)]] as [$title]) {
        vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => $title])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    $res = vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'A', 'position' => 99, 'course_id' => $other->id])->assertCreated();
    expect($res->json('position'))->toBe(1)->and($res->json('course_id'))->toBe($course->id);
});

test('bai: tao none/preview link ngoai, parse ID, khong luu URL', function () {
    vvCourseActor();
    [$course, $chapter] = vvContentSet();

    $r = vvCourseJson('POST', vvLessonPath($course, $chapter), ['title' => 'Bài 2'])
        ->assertCreated()->assertJsonPath('video_source', 'none')->assertJsonPath('position', 2)->assertJsonPath('is_preview', false);
    expect($r->json('external_embed_url'))->toBeNull();

    $r = vvCourseJson('POST', vvLessonPath($course, $chapter), [
        'title' => 'Xem thử', 'is_preview' => true, 'video_source' => 'external_link',
        'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'duration_seconds' => 212,
    ])->assertCreated()
        ->assertJsonPath('external_provider', 'youtube')
        ->assertJsonPath('external_video_id', 'dQw4w9WgXcQ')
        ->assertJsonPath('external_embed_url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->assertJsonPath('duration_seconds', 212);
    expect($r->json())->not->toHaveKey('external_url');

    $lesson = Lesson::query()->findOrFail($r->json('id'));
    expect($lesson->course_id)->toBe($course->id)->and($lesson->chapter_id)->toBe($chapter->id);

    vvCourseJson('POST', vvLessonPath($course, $chapter), [
        'title' => 'Vimeo', 'is_preview' => true, 'video_source' => 'external_link', 'external_url' => 'https://vimeo.com/123456789',
    ])->assertCreated()->assertJsonPath('external_embed_url', 'https://player.vimeo.com/video/123456789?dnt=1');
});

test('bai: link ngoai chi cho preview; URL xau bi 422', function (string $url) {
    vvCourseActor();
    [$course, $chapter] = vvContentSet();

    vvCourseJson('POST', vvLessonPath($course, $chapter), ['title' => 'X', 'is_preview' => true, 'video_source' => 'external_link', 'external_url' => $url])
        ->assertStatus(422)->assertJsonValidationErrors('external_url');
    expect(Lesson::query()->count())->toBe(1);
})->with([
    'javascript' => 'javascript:alert(1)',
    'http' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'host la' => 'https://evil.com/watch?v=dQw4w9WgXcQ',
    'host giong' => 'https://www.youtube.com.evil.com/watch?v=dQw4w9WgXcQ',
    'userinfo' => 'https://youtube.com@evil.com/watch?v=dQw4w9WgXcQ',
    'id sai' => 'https://www.youtube.com/watch?v=short',
    'vimeo id sai' => 'https://vimeo.com/abc',
    'cong la' => 'https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ',
    'khoang trang' => "https://youtu.be/dQw4w9WgXcQ\n.evil.com",
    'rong' => '',
]);

test('bai: link ngoai khong preview -> 422; doi sang khong preview khi dang link ngoai -> 422', function () {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();

    vvCourseJson('POST', vvLessonPath($course, $chapter), ['title' => 'X', 'video_source' => 'external_link', 'external_url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->assertStatus(422)->assertJsonValidationErrors('video_source');

    $lesson->update(['is_preview' => true, 'video_source' => 'external_link', 'external_provider' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ']);
    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['is_preview' => false])->assertStatus(422);

    // đổi sang none và bỏ preview: xoá ID ngoài
    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['is_preview' => false, 'video_source' => 'none'])
        ->assertOk()->assertJsonPath('external_video_id', null)->assertJsonPath('external_embed_url', null);
});

test('bai: giu link cu khi sua tieu de; doi link moi khi gui URL', function () {
    vvCourseActor();
    [$course, $chapter] = vvContentSet();
    $lesson = Lesson::factory()->for($course)->for($chapter)->preview()->external()->create(['position' => 2]);

    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['title' => 'Tên mới'])
        ->assertOk()->assertJsonPath('external_video_id', 'dQw4w9WgXcQ')->assertJsonPath('title', 'Tên mới');

    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['external_url' => 'https://youtu.be/abcdefghijk'])
        ->assertOk()->assertJsonPath('external_video_id', 'abcdefghijk');
});

test('bai: upload chi hop le khi da co video_asset (T11 gan); khong nhan video_asset_id/course_id/chapter_id', function () {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();
    $other = Lesson::factory()->create();
    $foreignAsset = VideoAsset::factory()->create(['lesson_id' => $other->id]);
    $otherChapter = Chapter::factory()->create();

    vvCourseJson('POST', vvLessonPath($course, $chapter), ['title' => 'U', 'video_source' => 'upload'])
        ->assertStatus(422)->assertJsonValidationErrors('video_source');
    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['video_source' => 'upload'])->assertStatus(422);

    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), [
        'title' => 'Giữ', 'video_asset_id' => $foreignAsset->id, 'course_id' => $other->course_id, 'chapter_id' => $otherChapter->id,
    ])->assertOk()->assertJsonPath('has_video_asset', false);
    $fresh = $lesson->fresh();
    expect($fresh->video_asset_id)->toBeNull()->and($fresh->course_id)->toBe($course->id)->and($fresh->chapter_id)->toBe($chapter->id);

    // bài có asset của chính nó: sửa tiêu đề giữ upload; chuyển sang none thì gỡ asset
    $asset = VideoAsset::factory()->ready(300)->create(['lesson_id' => $lesson->id]);
    $lesson->forceFill(['video_source' => VideoSource::Upload, 'video_asset_id' => $asset->id, 'duration_seconds' => 300])->save();

    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['title' => 'Vẫn upload', 'duration_seconds' => 5])
        ->assertOk()->assertJsonPath('video_source', 'upload')->assertJsonPath('has_video_asset', true)
        ->assertJsonPath('video_status', 'ready')->assertJsonPath('duration_seconds', 300);

    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['video_source' => 'none'])
        ->assertOk()->assertJsonPath('has_video_asset', false);
    expect($lesson->fresh()->video_asset_id)->toBeNull();
});

test('xoa bai: mem, audit; bai co tien do hoc sinh -> 409 LESSON_HAS_PROGRESS', function () {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();
    $busy = Lesson::factory()->for($course)->for($chapter)->create(['position' => 2]);
    LessonProgress::factory()->create(['lesson_id' => $busy->id]);

    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $busy))->assertStatus(409)->assertJsonPath('code', 'LESSON_HAS_PROGRESS');
    expect($busy->fresh())->not->toBeNull();

    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $lesson))->assertNoContent();
    expect(Lesson::withTrashed()->find($lesson->id)->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'lesson.delete')->where('subject_id', $lesson->id)->count())->toBe(1);

    // xoá lại: bản ghi đã xoá mềm không còn bind được -> 404
    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $lesson))->assertNotFound();
});

test('xoa chuong: xoa mem ca bai; co tien do -> 409 CHAPTER_HAS_PROGRESS va khong xoa gi', function () {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();
    $second = Lesson::factory()->for($course)->for($chapter)->create(['position' => 2]);

    LessonProgress::factory()->create(['lesson_id' => $second->id]);
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertStatus(409)->assertJsonPath('code', 'CHAPTER_HAS_PROGRESS');
    expect(Chapter::query()->count())->toBe(1)->and(Lesson::query()->count())->toBe(2);

    LessonProgress::query()->delete();
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertNoContent();
    expect(Chapter::query()->count())->toBe(0)->and(Lesson::query()->count())->toBe(0)
        ->and(Lesson::withTrashed()->count())->toBe(2);
});

test('xoa het chuong/bai thi khoa khong con publish duoc (T08)', function () {
    vvCourseActor();
    [$course, $chapter] = vvContentSet();
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertNoContent();
    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertStatus(422)->assertJsonPath('code', 'COURSE_NOT_PUBLISHABLE');
});

test('cay chuong/bai: dung thu tu, khong gom ban ghi da xoa', function () {
    vvCourseActor();
    $course = Course::factory()->create();
    $c2 = Chapter::factory()->for($course)->create(['position' => 2]);
    $c1 = Chapter::factory()->for($course)->create(['position' => 1]);
    Chapter::factory()->for($course)->create(['position' => 3, 'deleted_at' => now()]);
    Lesson::factory()->for($course)->for($c1)->create(['position' => 2, 'title' => 'b']);
    Lesson::factory()->for($course)->for($c1)->create(['position' => 1, 'title' => 'a']);
    Lesson::factory()->for($course)->for($c1)->create(['position' => 3, 'deleted_at' => now()]);

    $res = vvCourseJson('GET', "/admin/courses/{$course->id}/chapters")->assertOk();
    expect(array_column($res->json('chapters'), 'id'))->toBe([$c1->id, $c2->id])
        ->and(array_column($res->json('chapters.0.lessons'), 'title'))->toBe(['a', 'b']);
});

test('khoa published: xoa bai cuoi / chuong cuoi -> 409 COURSE_LAST_LESSON; con bai khac thi xoa duoc; draft xoa duoc', function () {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();
    $course->forceFill(['status' => CourseStatus::Published, 'published_at' => now()])->save();

    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $lesson))->assertStatus(409)->assertJsonPath('code', 'COURSE_LAST_LESSON');
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertStatus(409)->assertJsonPath('code', 'COURSE_LAST_LESSON');
    expect(Lesson::query()->count())->toBe(1)->and(Chapter::query()->count())->toBe(1);

    $second = Lesson::factory()->for($course)->for($chapter)->create(['position' => 2]);
    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $second))->assertNoContent();

    // chương rỗng bên cạnh xoá được; chương chứa bài cuối thì không
    $empty = Chapter::factory()->for($course)->create(['position' => 2]);
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$empty->id}")->assertNoContent();
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertStatus(409);

    [$draft, $dChapter, $dLesson] = vvContentSet();
    vvCourseJson('DELETE', vvLessonPath($draft, $dChapter, $dLesson))->assertNoContent();
    vvCourseJson('DELETE', "/admin/courses/{$draft->id}/chapters/{$dChapter->id}")->assertNoContent();
});

test('khoa published co enrollment nhung chua co progress: xoa bai/chuong (con bai khac) van duoc', function () {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();
    $course->forceFill(['status' => CourseStatus::Published, 'published_at' => now()])->save();
    $keep = Chapter::factory()->for($course)->create(['position' => 2]);
    Lesson::factory()->for($course)->for($keep)->create(['position' => 1]);
    Enrollment::factory()->create(['course_id' => $course->id]);

    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $lesson))->assertNoContent();
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertNoContent();
});

test('AC: response bai khong lo URL goc cua nguoi nhap', function () {
    vvCourseActor();
    [$course, $chapter] = vvContentSet();
    $res = vvCourseJson('POST', vvLessonPath($course, $chapter), [
        'title' => 'P', 'is_preview' => true, 'video_source' => 'external_link',
        'external_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=SECRET123',
    ])->assertCreated();

    expect($res->getContent())->not->toContain('SECRET123')->not->toContain('watch?v=')
        ->and($res->json('external_video_id'))->toBe('dQw4w9WgXcQ');
});
