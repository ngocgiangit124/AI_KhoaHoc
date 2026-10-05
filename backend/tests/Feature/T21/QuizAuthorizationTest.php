<?php

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;

require_once __DIR__.'/helpers.php';

test('giao vien duoc gan quan ly quiz khoa minh; page manager cung duoc', function () {
    $teacher = vvCourseActor('teacher');
    [$course, $chapter] = vvContentSet();
    vvAssign($course, $teacher);

    $quiz = vvCourseJson('POST', vvQuizPath($course), ['title' => 'Q', 'chapter_id' => $chapter->id])->assertCreated();
    $path = vvQuizPath($course).'/'.$quiz->json('id');
    vvCourseJson('POST', "$path/questions", vvQuestionPayload())->assertCreated();
    vvCourseJson('PUT', $path, ['title' => 'Q2', 'chapter_id' => $chapter->id])->assertOk();
    vvCourseJson('GET', $path)->assertOk()->assertJsonPath('questions.0.options.1.is_correct', true);
    vvCourseJson('DELETE', $path)->assertNoContent();
});

test('IDOR: giao vien khong duoc gan -> 403 o moi route, du lieu khong doi', function () {
    vvCourseActor('teacher');
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
    $q = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 1]);
    $base = vvQuizPath($course);

    $calls = [
        ['GET', $base, []],
        ['POST', $base, ['title' => 'x', 'chapter_id' => $chapter->id]],
        ['GET', "$base/{$quiz->id}", []],
        ['PUT', "$base/{$quiz->id}", ['title' => 'x', 'chapter_id' => $chapter->id]],
        ['DELETE', "$base/{$quiz->id}", []],
        ['GET', "$base/{$quiz->id}/questions", []],
        ['POST', "$base/{$quiz->id}/questions", vvQuestionPayload()],
        ['GET', "$base/{$quiz->id}/questions/{$q->id}", []],
        ['PUT', "$base/{$quiz->id}/questions/{$q->id}", vvQuestionPayload(['content' => 'x'])],
        ['DELETE', "$base/{$quiz->id}/questions/{$q->id}", []],
    ];

    foreach ($calls as [$method, $path, $data]) {
        vvCourseJson($method, $path, $data)->assertForbidden();
    }

    expect(Quiz::query()->count())->toBe(1)->and($quiz->fresh()->title)->not->toBe('x')
        ->and(QuizQuestion::query()->count())->toBe(1)->and($q->fresh()->content)->not->toBe('x');
});

test('IDOR scopeBindings: quiz/cau hoi cua khoa khac duoi khoa minh -> 404', function () {
    $teacher = vvCourseActor('teacher');
    [$mine, $myChapter] = vvContentSet();
    vvAssign($mine, $teacher);
    [$theirs, $theirChapter] = vvContentSet();
    $theirQuiz = Quiz::factory()->for($theirs)->create(['chapter_id' => $theirChapter->id, 'position' => 1]);
    $theirQ = QuizQuestion::factory()->for($theirQuiz)->withOptions()->create(['position' => 1]);
    $myQuiz = Quiz::factory()->for($mine)->create(['chapter_id' => $myChapter->id, 'position' => 1]);
    $b = vvQuizPath($mine);

    vvCourseJson('GET', "$b/{$theirQuiz->id}")->assertNotFound();
    vvCourseJson('PUT', "$b/{$theirQuiz->id}", ['title' => 'x', 'chapter_id' => $myChapter->id])->assertNotFound();
    vvCourseJson('DELETE', "$b/{$theirQuiz->id}")->assertNotFound();
    vvCourseJson('POST', "$b/{$theirQuiz->id}/questions", vvQuestionPayload())->assertNotFound();
    // Câu của quiz khóa khác đặt dưới quiz của mình.
    vvCourseJson('GET', "$b/{$myQuiz->id}/questions/{$theirQ->id}")->assertNotFound();
    vvCourseJson('PUT', "$b/{$myQuiz->id}/questions/{$theirQ->id}", vvQuestionPayload(['content' => 'x']))->assertNotFound();
    vvCourseJson('DELETE', "$b/{$myQuiz->id}/questions/{$theirQ->id}")->assertNotFound();
    // Gắn quiz của mình vào chương của khóa khác → 422.
    vvCourseJson('PUT', "$b/{$myQuiz->id}", ['title' => 'x', 'chapter_id' => $theirChapter->id])->assertUnprocessable();

    expect($theirQuiz->fresh()->title)->not->toBe('x')->and($theirQ->fresh()->content)->not->toBe('x')
        ->and($myQuiz->fresh()->chapter_id)->toBe($myChapter->id);
});

test('hoc sinh va khach khong goi duoc API soan quiz', function () {
    [$course, $chapter] = vvContentSet();
    $url = vvAdminUrl(vvQuizPath($course));

    $this->getJson($url, vvAdminHeaders())->assertUnauthorized();

    $student = User::factory()->create();
    $this->actingAs($student);
    $this->getJson($url, vvAdminHeaders())->assertStatus(403);
});
