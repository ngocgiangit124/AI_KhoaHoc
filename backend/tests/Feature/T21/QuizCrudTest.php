<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizQuestion;

require_once __DIR__.'/helpers.php';

test('tao quiz gan chuong va gan bai; course_id suy ra tu route, bo qua course_id gui len', function () {
    vvCourseActor('admin');
    [$course, $chapter, $lesson] = vvContentSet();
    $other = Course::factory()->create();

    $a = vvCourseJson('POST', vvQuizPath($course), ['title' => 'Quiz chương', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 15, 'course_id' => $other->id])
        ->assertCreated()
        ->assertJsonPath('course_id', $course->id)
        ->assertJsonPath('parent_type', 'chapter')
        ->assertJsonPath('time_limit_minutes', 15)
        ->assertJsonPath('questions_count', 0);
    $b = vvCourseJson('POST', vvQuizPath($course), ['title' => 'Quiz bài', 'lesson_id' => $lesson->id])
        ->assertCreated()->assertJsonPath('parent_type', 'lesson')->assertJsonPath('parent_title', $lesson->title);

    expect($b->json('position'))->toBe($a->json('position') + 1);
    expect(AuditLog::query()->where('action', 'quiz.create')->count())->toBe(2);

    vvCourseJson('GET', vvQuizPath($course))->assertOk()->assertJsonCount(2, 'data');
});

test('validate quiz: phai dung 1 noi gan, thuoc dung khoa, time limit 1-300', function () {
    vvCourseActor('admin');
    [$course, $chapter, $lesson] = vvContentSet();
    [, $foreignChapter] = vvContentSet();
    $path = vvQuizPath($course);

    vvCourseJson('POST', $path, ['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('chapter_id');
    vvCourseJson('POST', $path, ['title' => 'x', 'chapter_id' => $chapter->id, 'lesson_id' => $lesson->id])->assertUnprocessable();
    vvCourseJson('POST', $path, ['title' => 'x', 'chapter_id' => $foreignChapter->id])->assertUnprocessable()->assertJsonValidationErrors('chapter_id');
    vvCourseJson('POST', $path, ['title' => '', 'chapter_id' => $chapter->id])->assertUnprocessable()->assertJsonValidationErrors('title');
    vvCourseJson('POST', $path, ['title' => '<b>x</b>', 'chapter_id' => $chapter->id])->assertUnprocessable()->assertJsonValidationErrors('title');
    vvCourseJson('POST', $path, ['title' => 'x', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 0])->assertUnprocessable();
    vvCourseJson('POST', $path, ['title' => 'x', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 301])->assertUnprocessable();
    vvCourseJson('POST', $path, ['title' => 'x', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 300])->assertCreated();

    expect(Quiz::query()->count())->toBe(1);
});

test('cap nhat quiz: doi noi gan, bo gioi han thoi gian; cho flag tat thi bo qua time limit', function () {
    vvCourseActor('admin');
    [$course, $chapter, $lesson] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'time_limit_minutes' => 10, 'position' => 1]);

    vvCourseJson('PUT', vvQuizPath($course, $quiz), ['title' => 'Mới', 'lesson_id' => $lesson->id, 'time_limit_minutes' => null])
        ->assertOk()->assertJsonPath('parent_type', 'lesson')->assertJsonPath('time_limit_minutes', null);
    expect($quiz->fresh()->chapter_id)->toBeNull()->and($quiz->fresh()->lesson_id)->toBe($lesson->id);

    config(['features.quiz_time_limit' => false]);
    vvCourseJson('PUT', vvQuizPath($course, $quiz), ['title' => 'Mới', 'lesson_id' => $lesson->id, 'time_limit_minutes' => 99])
        ->assertOk()->assertJsonPath('time_limit_minutes', null);
    $created = vvCourseJson('POST', vvQuizPath($course), ['title' => 'Q2', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 20])->assertCreated();
    expect($created->json('time_limit_minutes'))->toBeNull();
});

test('xoa quiz: soft delete, 404 khi goi lai theo id; xoa bai/chuong keo theo quiz', function () {
    vvCourseActor('admin');
    [$course, $chapter, $lesson] = vvContentSet();
    $q1 = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
    $q2 = Quiz::factory()->forLesson($lesson)->create(['position' => 2]);
    $q3 = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 3]);

    vvCourseJson('DELETE', vvQuizPath($course, $q1))->assertNoContent();
    expect(Quiz::withTrashed()->find($q1->id)->trashed())->toBeTrue();
    vvCourseJson('GET', vvQuizPath($course, $q1))->assertNotFound();

    // Bài thêm 1 bài nữa để khóa (draft) không vướng ràng buộc bài cuối.
    $lesson2 = Lesson::factory()->for($course)->for($chapter)->create(['position' => 2]);
    vvCourseJson('DELETE', vvLessonPath($course, $chapter, $lesson))->assertNoContent();
    expect(Quiz::query()->find($q2->id))->toBeNull()->and(Quiz::query()->find($q3->id))->not->toBeNull();

    vvCourseJson('DELETE', "/admin/courses/{$course->id}/chapters/{$chapter->id}")->assertNoContent();
    expect(Quiz::query()->find($q3->id))->toBeNull()->and($lesson2->fresh()->trashed())->toBeTrue();
});

test('quiz chi hien trong danh sach cua khoa minh va dem so cau dang dung', function () {
    vvCourseActor('admin');
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
    QuizQuestion::factory()->for($quiz)->withOptions()->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 4, 'deleted_at' => now()]);
    Quiz::factory()->create();

    vvCourseJson('GET', vvQuizPath($course))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.questions_count', 3);
    vvCourseJson('GET', vvQuizPath($course, $quiz))->assertOk()->assertJsonCount(3, 'questions');
});
