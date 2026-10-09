<?php

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AdminQuizHasAttemptsTest.php';
require_once __DIR__.'/../SLN7/QuestionReorderTest.php';

test('QA FA5: luot DANG LAM (chua nop) van lam has_attempts=true cho quiz va cau trong question_ids', function () {
    vvCourseActor('admin');
    [$course, $quiz, $used, $fresh] = vvBeb1Quiz();
    $a = QuizAttempt::factory()->create(['quiz_id' => $quiz->id, 'question_ids' => [$used->id]]);
    expect($a->submitted_at)->toBeNull();

    vvCourseJson('GET', vvQuizPath($course, $quiz))->assertOk()
        ->assertJsonPath('has_attempts', true)
        ->assertJsonPath('questions.0.has_attempts', true)->assertJsonPath('questions.1.has_attempts', false);
    expect($fresh->id)->not->toBe($used->id);
});

test('QA FA5: luot cua quiz KHAC chua id cau trung khong lam ban co (loc theo quiz_id)', function () {
    vvCourseActor('admin');
    [$course, $quiz, $used] = vvBeb1Quiz();
    $other = Quiz::factory()->for($course)->create(['chapter_id' => $quiz->chapter_id, 'position' => 9]);
    QuizAttempt::factory()->create(['quiz_id' => $other->id, 'question_ids' => [$used->id]]);

    vvCourseJson('GET', vvQuestionsPath($course, $quiz))->assertOk()
        ->assertJsonPath('data.0.has_attempts', false)->assertJsonPath('data.1.has_attempts', false);
    // id cau la tien to cua id khac (vd 1 vs 12): JSON_CONTAINS khong khop chuoi con
    $list = vvCourseJson('GET', vvQuizPath($course))->assertOk();
    $flags = collect($list->json('data'))->pluck('has_attempts', 'id')->all();
    expect($flags[$quiz->id])->toBeFalse()->and($flags[$other->id])->toBeTrue();
});

test('QA FA5: reorder tra has_attempts dung tung cau; quiz xoa mem khong ro; store cau moi false', function () {
    vvCourseActor('admin');
    [$course, $quiz, $used, $fresh] = vvBeb1Quiz();
    vvFakeAttempt($quiz, [$used->id]);

    $res = vvCourseJson('PUT', vvQuestionsPath($course, $quiz).'/order', ['question_ids' => [$fresh->id, $used->id]])->assertOk();
    expect(collect($res->json('data'))->pluck('has_attempts', 'id')->all())->toEqual([$fresh->id => false, $used->id => true]);

    // Sua cau da co luot: ban goc giu true khi doc lai, ban sao false.
    $new = vvCourseJson('PUT', vvQuizPath($course, $quiz, $used), vvQuestionPayload(['content' => 'Đã sửa']))->assertOk();
    $newId = $new->json('id');
    $new->assertJsonPath('has_attempts', false);
    $list = vvCourseJson('GET', vvQuestionsPath($course, $quiz))->assertOk();
    $flags = collect($list->json('data'))->pluck('has_attempts', 'id')->all();
    expect($flags[$newId] ?? null)->toBeFalse();
});

test('QA FA5: giao vien duoc giao khoa thay has_attempts; giao vien khong duoc giao 403; khach 401', function () {
    [$course, $quiz, $used] = vvBeb1Quiz();
    vvFakeAttempt($quiz, [$used->id]);

    vvCourseJson('GET', vvQuizPath($course, $quiz))->assertUnauthorized();

    $t = vvCourseActor('teacher');
    vvCourseJson('GET', vvQuizPath($course, $quiz))->assertForbidden();
    expect($t->id)->toBeInt();
});

test('QA FA5: API hoc sinh KHONG lo has_attempts (QuizResource/QuestionResource dung chung)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvBeb1Course($s, 1);
    $q = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $q->id, 'position' => 1]);
    QuizAttempt::factory()->submitted(1, 5)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);

    $res = vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();
    expect(json_encode($res->json()))->not->toContain('has_attempts');
    expect(DB::table('quiz_attempts')->count())->toBe(1);
});

test('QA FA5: /admin/auth/me cho giao vien va quan ly trang cung co quiz_time_limit_enabled (bool); khach 401', function (string $state) {
    vvCourseActor($state);
    config(['features.quiz_time_limit' => true]);
    $r = vvCourseJson('GET', '/admin/auth/me')->assertOk();
    expect($r->json('quiz_time_limit_enabled'))->toBeTrue()->and($r->json())->toHaveKeys(['id', 'role']);
    expect(array_keys($r->json()))->not->toContain('password')->not->toContain('remember_token')->not->toContain('two_factor_secret');
})->with(['teacher', 'pageManager']);

test('QA FA5: /admin/auth/me khach 401', function () {
    vvCourseJson('GET', '/admin/auth/me')->assertUnauthorized();
});
