<?php

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

test('giao vien duoc gan: quan ly chuong/bai khoa minh', function () {
    $teacher = vvCourseActor('teacher');
    [$course, $chapter, $lesson] = vvContentSet();
    vvAssign($course, $teacher);

    vvCourseJson('GET', "/admin/courses/{$course->id}/chapters")->assertOk();
    $new = vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'C'])->assertCreated();
    vvCourseJson('POST', vvLessonPath($course, $chapter), ['title' => 'L'])->assertCreated();
    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['title' => 'L2'])->assertOk();
    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", [
        ['chapter_id' => $new->json('id'), 'lesson_ids' => []],
        ['chapter_id' => $chapter->id, 'lesson_ids' => Lesson::query()->where('chapter_id', $chapter->id)->orderBy('position')->pluck('id')->all()],
    ])->assertOk();
    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$new->json('id')}")->assertNoContent();
});

test('IDOR: giao vien khong duoc gan -> 403 o moi route, du lieu khong doi', function () {
    vvCourseActor('teacher');
    [$course, $chapter, $lesson] = vvContentSet();
    $base = "/admin/courses/{$course->id}";

    $calls = [
        ['GET', "$base/chapters", []],
        ['POST', "$base/chapters", ['title' => 'x']],
        ['PUT', "$base/chapters/{$chapter->id}", ['title' => 'x']],
        ['DELETE', "$base/chapters/{$chapter->id}", []],
        ['PUT', "$base/curriculum/order", [['chapter_id' => $chapter->id, 'lesson_ids' => [$lesson->id]]]],
        ['POST', vvLessonPath($course, $chapter), ['title' => 'x']],
        ['PUT', vvLessonPath($course, $chapter, $lesson), ['title' => 'x']],
        ['DELETE', vvLessonPath($course, $chapter, $lesson), []],
    ];

    foreach ($calls as [$method, $path, $data]) {
        vvCourseJson($method, $path, $data)->assertForbidden();
    }

    expect(Chapter::query()->count())->toBe(1)->and($chapter->fresh()->title)->not->toBe('x')->and(Lesson::query()->count())->toBe(1);
});

test('IDOR scopeBindings: chuong/bai cua khoa khac duoi khoa minh -> 404', function () {
    $teacher = vvCourseActor('teacher');
    [$mine, $myChapter, $myLesson] = vvContentSet();
    vvAssign($mine, $teacher);
    [$other, $otherChapter, $otherLesson] = vvContentSet();

    vvCourseJson('PUT', "/admin/courses/{$mine->id}/chapters/{$otherChapter->id}", ['title' => 'hack'])->assertNotFound();
    vvCourseJson('DELETE', "/admin/courses/{$mine->id}/chapters/{$otherChapter->id}")->assertNotFound();
    vvCourseJson('POST', vvLessonPath($mine, $otherChapter), ['title' => 'hack'])->assertNotFound();
    vvCourseJson('PUT', vvLessonPath($mine, $otherChapter, $otherLesson), ['title' => 'hack'])->assertNotFound();
    vvCourseJson('PUT', vvLessonPath($mine, $myChapter, $otherLesson), ['title' => 'hack'])->assertNotFound();
    vvCourseJson('DELETE', vvLessonPath($mine, $myChapter, $otherLesson))->assertNotFound();
    // bài của khóa mình nhưng chương khác trong cùng khóa
    $second = Chapter::factory()->for($mine)->create(['position' => 2]);
    vvCourseJson('PUT', vvLessonPath($mine, $second, $myLesson), ['title' => 'hack'])->assertNotFound();

    expect($otherChapter->fresh()->title)->not->toBe('hack')->and($otherLesson->fresh()->title)->not->toBe('hack');
});

test('IDOR reorder: payload chua bai/chuong khoa khac -> 422, khong dong vao khoa kia', function () {
    $teacher = vvCourseActor('teacher');
    [$mine, $myChapter, $myLesson] = vvContentSet();
    vvAssign($mine, $teacher);
    [, $otherChapter, $otherLesson] = vvContentSet();

    vvCourseJson('PUT', "/admin/courses/{$mine->id}/curriculum/order", [
        ['chapter_id' => $myChapter->id, 'lesson_ids' => [$myLesson->id, $otherLesson->id]],
    ])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$mine->id}/curriculum/order", [
        ['chapter_id' => $otherChapter->id, 'lesson_ids' => [$otherLesson->id]],
    ])->assertStatus(422);

    expect($otherLesson->fresh()->chapter_id)->toBe($otherChapter->id)->and($otherLesson->fresh()->course_id)->not->toBe($mine->id);
});

test('chua dang nhap / hoc sinh -> 401/403; khoa da xoa -> 404', function () {
    [$course] = vvContentSet();
    test()->json('GET', vvAdminUrl("/admin/courses/{$course->id}/chapters"), [], vvAdminHeaders())->assertUnauthorized();

    vvCourseActor('admin');
    $course->delete();
    vvCourseJson('GET', "/admin/courses/{$course->id}/chapters")->assertNotFound();
    vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'x'])->assertNotFound();
});

test('GV bi go khoi khoa thi mat quyen noi dung', function () {
    $teacher = vvCourseActor('teacher');
    [$course, $chapter] = vvContentSet();
    vvAssign($course, $teacher);
    vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'ok'])->assertCreated();

    DB::table('course_teacher')->where('course_id', $course->id)->delete();
    vvCourseJson('PUT', "/admin/courses/{$course->id}/chapters/{$chapter->id}", ['title' => 'x'])->assertForbidden();
    expect(User::query()->find($teacher->id))->not->toBeNull();
});
