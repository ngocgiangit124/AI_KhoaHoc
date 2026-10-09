<?php

use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T21/helpers.php';

/** @return array{0: Course, 1: Quiz, 2: QuizQuestion, 3: QuizQuestion} */
function vvBeb1Quiz(): array
{
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id]);
    $used = QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $quiz->id, 'position' => 1]);
    $fresh = QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $quiz->id, 'position' => 2]);

    return [$course, $quiz, $used, $fresh];
}

test('BE-backlog-1 FA5: has_attempts o quiz va tung cau (list, show, question list/show); khong co luot -> false', function () {
    vvCourseActor('admin');
    [$course, $quiz, $used, $fresh] = vvBeb1Quiz();
    $other = Quiz::factory()->for($course)->create(['chapter_id' => $quiz->chapter_id, 'position' => 2]);

    vvCourseJson('GET', vvQuizPath($course))->assertOk()
        ->assertJsonPath('data.0.has_attempts', false)->assertJsonPath('data.1.has_attempts', false);
    vvCourseJson('GET', vvQuestionsPath($course, $quiz))->assertOk()
        ->assertJsonPath('data.0.has_attempts', false)->assertJsonPath('data.1.has_attempts', false);

    vvFakeAttempt($quiz, [$used->id]);

    $list = vvCourseJson('GET', vvQuizPath($course))->assertOk();
    expect(collect($list->json('data'))->pluck('has_attempts', 'id')->all())->toEqual([$quiz->id => true, $other->id => false]);

    vvCourseJson('GET', vvQuizPath($course, $quiz))->assertOk()
        ->assertJsonPath('has_attempts', true)
        ->assertJsonPath('questions.0.id', $used->id)->assertJsonPath('questions.0.has_attempts', true)
        ->assertJsonPath('questions.1.id', $fresh->id)->assertJsonPath('questions.1.has_attempts', false);

    $qs = vvCourseJson('GET', vvQuestionsPath($course, $quiz))->assertOk();
    expect(collect($qs->json('data'))->pluck('has_attempts', 'id')->all())->toEqual([$used->id => true, $fresh->id => false]);

    vvCourseJson('GET', vvQuizPath($course, $quiz, $used))->assertOk()->assertJsonPath('has_attempts', true);
    vvCourseJson('GET', vvQuizPath($course, $quiz, $fresh))->assertOk()->assertJsonPath('has_attempts', false);
});

test('BE-backlog-1 FA5: tao quiz/cau moi -> has_attempts=false; sua cau da co luot (copy-on-write) tra cau moi has_attempts=false', function () {
    vvCourseActor('admin');
    [$course, $quiz, $used] = vvBeb1Quiz();
    vvFakeAttempt($quiz, [$used->id]);

    vvCourseJson('POST', vvQuizPath($course), ['title' => 'Mới', 'chapter_id' => $quiz->chapter_id])
        ->assertCreated()->assertJsonPath('has_attempts', false);
    vvCourseJson('PUT', vvQuizPath($course, $quiz), ['title' => 'Đổi tên', 'chapter_id' => $quiz->chapter_id])
        ->assertOk()->assertJsonPath('has_attempts', true);
    vvCourseJson('POST', vvQuestionsPath($course, $quiz), vvQuestionPayload())->assertCreated()->assertJsonPath('has_attempts', false);

    $res = vvCourseJson('PUT', vvQuizPath($course, $quiz, $used), vvQuestionPayload(['content' => 'Nội dung sửa']))->assertOk();
    expect($res->json('id'))->not->toBe($used->id);
    $res->assertJsonPath('has_attempts', false);
});

test('BE-backlog-1 FA5: has_attempts khong N+1 (so truy van khong doi khi them cau)', function () {
    vvCourseActor('admin');
    [$course, $quiz, $used] = vvBeb1Quiz();
    vvFakeAttempt($quiz, [$used->id]);

    $count = function () use ($course, $quiz): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        vvCourseJson('GET', vvQuestionsPath($course, $quiz))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count(); // khởi động (phiên/cache)
    $before = $count();
    QuizQuestion::factory()->withOptions(1)->count(5)->sequence(fn ($s) => ['position' => 10 + $s->index])->create(['quiz_id' => $quiz->id]);

    expect($count())->toBe($before);
});

test('BE-backlog-1 FA5: /admin/auth/me co quiz_time_limit_enabled theo FEATURE_QUIZ_TIME_LIMIT', function () {
    vvCourseActor('admin');

    config(['features.quiz_time_limit' => true]);
    vvCourseJson('GET', '/admin/auth/me')->assertOk()->assertJsonPath('quiz_time_limit_enabled', true)->assertJsonPath('role', 'admin');

    config(['features.quiz_time_limit' => false]);
    vvCourseJson('GET', '/admin/auth/me')->assertOk()->assertJsonPath('quiz_time_limit_enabled', false);
});
