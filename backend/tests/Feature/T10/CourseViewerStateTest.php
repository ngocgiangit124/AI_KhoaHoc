<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

function vvGetViewerState(Course $course)
{
    return test()->getJson('http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state", [
        'Origin' => config('app.frontend_url'),
    ]);
}

test('viewer-state doi hoi dang nhap (401)', function () {
    $course = Course::factory()->published()->create();

    $response = vvGetViewerState($course);

    $response->assertStatus(401);
});

test('viewer-state tra can_buy khi khoa co phi va chua mua', function () {
    $course = Course::factory()->published()->create(['price' => 199000]);
    $user = User::factory()->student()->verified()->create();

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertJson(['viewer_state' => 'can_buy', 'resume_lesson_id' => null]);
});

test('viewer-state tra can_register_free khi khoa mien phi va chua dang ky (BR5)', function () {
    $course = Course::factory()->published()->free()->create();
    $user = User::factory()->student()->verified()->create();

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertJson(['viewer_state' => 'can_register_free']);
});

test('viewer-state tra pending_approval khi dang cho duyet (edge case US-003)', function () {
    $course = Course::factory()->published()->free()->create();
    $user = User::factory()->student()->verified()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $user->id, 'course_id' => $course->id]);

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertJson(['viewer_state' => 'pending_approval']);
});

test('viewer-state tra owned + resume_lesson_id la bai dau tien khi chua hoc bai nao (AC4)', function () {
    $course = Course::factory()->published()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    $firstLesson = Lesson::factory()->create([
        'course_id' => $course->id, 'chapter_id' => $chapter->id, 'position' => 1,
    ]);
    Lesson::factory()->create([
        'course_id' => $course->id, 'chapter_id' => $chapter->id, 'position' => 2,
    ]);
    $user = User::factory()->student()->verified()->create();
    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertJson(['viewer_state' => 'owned', 'resume_lesson_id' => $firstLesson->id]);
});

test('viewer-state resume_lesson_id bo qua chuong dau da bi xoa mem (R2)', function () {
    $course = Course::factory()->published()->create();
    $deletedChapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    Lesson::factory()->create([
        'course_id' => $course->id, 'chapter_id' => $deletedChapter->id, 'position' => 1,
    ]);
    $deletedChapter->delete();

    $chapter2 = Chapter::factory()->create(['course_id' => $course->id, 'position' => 2]);
    $firstLessonOfRemainingChapter = Lesson::factory()->create([
        'course_id' => $course->id, 'chapter_id' => $chapter2->id, 'position' => 1,
    ]);
    $user = User::factory()->student()->verified()->create();
    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertJson([
        'viewer_state' => 'owned',
        'resume_lesson_id' => $firstLessonOfRemainingChapter->id,
    ]);
});

test('viewer-state tra owned + resume_lesson_id la bai dang hoc do gan nhat (BR6)', function () {
    $course = Course::factory()->published()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id, 'position' => 1]);
    $lesson1 = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id, 'position' => 1]);
    $lesson2 = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id, 'position' => 2]);
    $user = User::factory()->student()->verified()->create();
    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);

    LessonProgress::factory()->create([
        'user_id' => $user->id, 'lesson_id' => $lesson1->id, 'course_id' => $course->id,
        'last_accessed_at' => now()->subHour(),
    ]);
    LessonProgress::factory()->create([
        'user_id' => $user->id, 'lesson_id' => $lesson2->id, 'course_id' => $course->id,
        'last_accessed_at' => now(),
    ]);

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertJson(['viewer_state' => 'owned', 'resume_lesson_id' => $lesson2->id]);
});

test('viewer-state tra no_store Cache-Control (S16)', function () {
    $course = Course::factory()->published()->create();
    $user = User::factory()->student()->verified()->create();

    $response = test()->actingAs($user)->getJson(
        'http://'.config('app.api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.frontend_url')]
    );

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('viewer-state khong the goi tren host admin-api', function () {
    $course = Course::factory()->published()->create();

    $response = test()->getJson(
        'http://'.config('app.admin_api_host')."/api/v1/courses/{$course->slug}/viewer-state",
        ['Origin' => config('app.admin_url')]
    );

    $response->assertNotFound();
});
