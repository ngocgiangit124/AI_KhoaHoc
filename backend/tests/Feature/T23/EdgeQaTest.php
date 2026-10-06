<?php

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/MyCoursesTest.php';

test('QA AC5: per_page=31 va per_page=0 -> 422, per_page=30 -> 200', function () {
    vvActAsStudent(User::factory()->create());

    vvMcGet('/me/courses?per_page=31')->assertStatus(422);
    vvMcGet('/me/courses?per_page=0')->assertStatus(422);
    vvMcGet('/me/courses?per_page=-1')->assertStatus(422);
    vvMcGet('/me/courses?page=abc')->assertStatus(422);
    vvMcGet('/me/courses?per_page=30')->assertOk();
});

test('QA IDOR: HS A goi progress khoa cua HS B -> 403, danh sach A khong chua khoa B', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $courseB = vvMcCourse($b, 3, 2);
    vvActAsStudent($a);
    vvMcCourse($a, 1);

    vvMcGet("/me/courses/{$courseB['course']->id}/progress")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    $ids = array_map(fn ($i) => $i['course']['id'], vvMcGet()->json('data'));
    expect($ids)->not->toContain($courseB['course']->id);
});

test('QA: expired/revoked khong hien o data va progress 403', function () {
    $s = vvActAsStudent(User::factory()->create());
    $revoked = vvMcCourse($s, 2, 0, ['status' => EnrollmentStatus::Revoked, 'revoked_at' => now()]);
    $expired = vvMcCourse($s, 2);
    DB::table('enrollments')->where('id', $expired['enrollment']->id)->update(['status' => 'expired']);

    vvMcGet()->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    vvMcGet("/me/courses/{$revoked['course']->id}/progress")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    vvMcGet("/me/courses/{$expired['course']->id}/progress")->assertForbidden();
});

test('QA AC4: cung khong hoc -> sap theo activated_at moi nhat; hoc roi truoc chua hoc', function () {
    $s = vvActAsStudent(User::factory()->create());
    $n1 = vvMcCourse($s, 1, 0, ['last_accessed_at' => null, 'activated_at' => now()->subDays(5)]);
    $n2 = vvMcCourse($s, 1, 0, ['last_accessed_at' => null, 'activated_at' => now()->subDay()]);
    $l = vvMcCourse($s, 1, 0, ['last_accessed_at' => now()->subDays(30)]);

    $ids = array_map(fn ($i) => $i['course']['id'], vvMcGet()->json('data'));
    expect($ids)->toBe([$l['course']->id, $n2['course']->id, $n1['course']->id]);
});

test('QA AC5: progress lesson status/watched_seconds khop lesson_progress (in_progress)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 3, 1);
    DB::table('lesson_progress')->insert([
        'user_id' => $s->id, 'lesson_id' => $a['lessons'][1]->id, 'course_id' => $a['course']->id,
        'watched_seconds' => 7, 'last_position_seconds' => 7, 'status' => 'in_progress', 'last_accessed_at' => now(),
        'completed_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    vvMcGet("/me/courses/{$a['course']->id}/progress")->assertOk()
        ->assertJsonPath('chapters.0.lessons.0.status', 'completed')
        ->assertJsonPath('chapters.0.lessons.0.watched_seconds', 5)
        ->assertJsonPath('chapters.0.lessons.1.status', 'in_progress')
        ->assertJsonPath('chapters.0.lessons.1.watched_seconds', 7)
        ->assertJsonPath('chapters.0.lessons.1.completed_at', null)
        ->assertJsonPath('chapters.0.lessons.2.status', 'not_started')
        ->assertJsonPath('chapters.0.lessons.2.watched_seconds', 0)
        ->assertJsonPath('progress.completed_lessons', 1)
        ->assertJsonPath('resume_lesson_id', $a['lessons'][1]->id);
});

test('QA: diem quiz thang 10 chinh xac, lan chua nop khong tinh, danh sach khop chi tiet', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 2);
    $q1 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    $q2 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 2]);
    QuizAttempt::factory()->submitted(1, 3.33)->create(['user_id' => $s->id, 'quiz_id' => $q1->id]);
    QuizAttempt::factory()->submitted(1, 10)->create(['user_id' => $s->id, 'quiz_id' => $q2->id]);
    QuizAttempt::factory()->create(['user_id' => $s->id, 'quiz_id' => $q1->id]);

    vvMcGet()->assertJsonPath('data.0.best_quiz_score', 10);
    $res = vvMcGet("/me/courses/{$a['course']->id}/progress")->assertOk();
    expect($res->json('quizzes.0.best_score'))->toBe(3.33)
        ->and($res->json('quizzes.0.attempts_count'))->toBe(1)
        ->and($res->json('quizzes.1.best_score'))->toEqual(10);
});

test('QA: khoa 0 bai trong danh sach co has_content=false va resume null', function () {
    $s = vvActAsStudent(User::factory()->create());
    vvMcCourse($s, 0);

    vvMcGet()->assertOk()->assertJsonPath('data.0.progress.has_content', false)
        ->assertJsonPath('data.0.progress.is_completed', false)->assertJsonPath('data.0.resume_lesson_id', null);
});

test('QA: bi tu choi nhieu lan roi xin lai -> chi con pending', function () {
    $s = vvActAsStudent(User::factory()->create());
    $c = Course::factory()->published()->create();
    Enrollment::factory()->rejected('lan 1')->create(['user_id' => $s->id, 'course_id' => $c->id]);
    Enrollment::factory()->rejected('lan 2')->create(['user_id' => $s->id, 'course_id' => $c->id]);
    Enrollment::factory()->pendingApproval()->create(['user_id' => $s->id, 'course_id' => $c->id]);

    vvMcGet()->assertOk()->assertJsonCount(1, 'pending')->assertJsonCount(0, 'rejected')->assertJsonCount(0, 'data');
});
