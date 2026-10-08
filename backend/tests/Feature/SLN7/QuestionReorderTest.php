<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\Quiz\QuizContentService;

require_once __DIR__.'/../T21/helpers.php';
require_once __DIR__.'/../T22/helpers.php';

/** @return array{0: Course, 1: Quiz, 2: list<QuizQuestion>} */
function vvReorderSet(int $n = 3): array
{
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
    $qs = [];

    for ($i = 1; $i <= $n; $i++) {
        $qs[] = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => $i]);
    }

    return [$course, $quiz, $qs];
}

function vvReorderPath(Course $course, Quiz $quiz): string
{
    return vvQuestionsPath($course, $quiz).'/order';
}

/** @param list<QuizQuestion> $qs */
function vvIds(array $qs): array
{
    return array_map(fn ($q) => $q->id, $qs);
}

test('reorder thanh cong: position 1..n, tra data theo thu tu moi, giu id, audit khong ghi noi dung', function (string $state) {
    vvCourseActor($state);
    [$course, $quiz, [$a, $b, $c]] = vvReorderSet();

    $res = vvCourseJson('PUT', vvReorderPath($course, $quiz), ['question_ids' => [$c->id, $a->id, $b->id]])->assertOk();

    expect(array_column($res->json('data'), 'id'))->toBe([$c->id, $a->id, $b->id])
        ->and(array_column($res->json('data'), 'position'))->toBe([1, 2, 3])
        ->and($res->json('data.0.options.0'))->toHaveKey('is_correct')
        ->and($c->fresh()->position)->toBe(1)->and($a->fresh()->position)->toBe(2)->and($b->fresh()->position)->toBe(3);

    $log = AuditLog::query()->where('action', 'quiz_question.reorder')->where('subject_id', $quiz->id)->sole();
    expect($log->subject_id)->toBe($quiz->id)->and(json_encode($log->changes))->not->toContain($a->content);

    // GET danh sách theo thứ tự mới
    expect(array_column(vvCourseJson('GET', vvQuestionsPath($course, $quiz))->json('data'), 'id'))->toBe([$c->id, $a->id, $b->id]);
})->with(['admin', 'pageManager']);

test('giao vien duoc gan reorder duoc', function () {
    $teacher = vvCourseActor('teacher');
    [$course, $quiz, [$a, $b]] = vvReorderSet(2);
    vvAssign($course, $teacher);

    vvCourseJson('PUT', vvReorderPath($course, $quiz), ['question_ids' => [$b->id, $a->id]])->assertOk();
    expect($b->fresh()->position)->toBe(1);
});

test('giao vien khong duoc gan -> 403, du lieu khong doi', function () {
    vvCourseActor('teacher');
    [$course, $quiz, [$a, $b]] = vvReorderSet(2);

    vvCourseJson('PUT', vvReorderPath($course, $quiz), ['question_ids' => [$b->id, $a->id]])->assertForbidden();
    expect($a->fresh()->position)->toBe(1)->and(AuditLog::query()->where('action', 'quiz_question.reorder')->where('subject_id', $quiz->id)->count())->toBe(0);
});

test('chua dang nhap -> 401', function () {
    [$course, $quiz] = vvReorderSet(2);

    test()->json('PUT', vvAdminUrl(vvReorderPath($course, $quiz)), ['question_ids' => []], vvAdminHeaders())->assertUnauthorized();
});

test('422: thieu / thua / trung / id khoa khac / id da xoa / sai kieu; khong ghi gi', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b, $c]] = vvReorderSet();
    [, $other, [$x]] = vvReorderSet(1);
    $deleted = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 4]);
    $deleted->delete();
    $path = vvReorderPath($course, $quiz);

    $mismatch = [
        [$a->id, $b->id],                     // thiếu
        [$a->id, $b->id, $c->id, $x->id],     // thừa (câu quiz khác)
        [$a->id, $b->id, $x->id],             // thay bằng id quiz khác
        [$a->id, $b->id, $c->id, $deleted->id], // câu đã xoá
        [],
    ];

    foreach ($mismatch as $ids) {
        vvCourseJson('PUT', $path, ['question_ids' => $ids])->assertStatus(422)->assertJsonPath('code', 'QUIZ_QUESTIONS_MISMATCH');
    }

    foreach ([[$a->id, $a->id, $b->id], [$a->id, $b->id, 'x'], [$a->id, $b->id, 0], 'abc'] as $ids) {
        vvCourseJson('PUT', $path, ['question_ids' => $ids])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    vvCourseJson('PUT', $path, [])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

    expect($a->fresh()->position)->toBe(1)->and($b->fresh()->position)->toBe(2)->and($c->fresh()->position)->toBe(3)
        ->and($x->fresh()->position)->toBe(1)
        ->and(AuditLog::query()->where('action', 'quiz_question.reorder')->where('subject_id', $quiz->id)->count())->toBe(0);
});

test('404: quiz khoa khac, quiz da xoa', function () {
    vvCourseActor();
    [$course, $quiz, [$a]] = vvReorderSet(1);
    [$other, $otherQuiz] = vvReorderSet(1);

    vvCourseJson('PUT', vvReorderPath($course, $otherQuiz), ['question_ids' => [$a->id]])->assertNotFound();
    $quiz->delete();
    vvCourseJson('PUT', vvReorderPath($course, $quiz), ['question_ids' => [$a->id]])->assertNotFound();
});

test('"order" khong bi hieu la id cau: GET /questions/order -> 404', function () {
    vvCourseActor();
    [$course, $quiz] = vvReorderSet(1);

    vvCourseJson('GET', vvReorderPath($course, $quiz))->assertNotFound();
});

test('dong thoi: danh sach cu sau khi 1 admin da xoa/doi -> 422, khong ghi de', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b, $c]] = vvReorderSet();
    $path = vvReorderPath($course, $quiz);

    // Admin 1 xoá câu b; admin 2 vẫn gửi danh sách cũ.
    vvCourseJson('DELETE', vvQuizPath($course, $quiz, $b))->assertNoContent();
    vvCourseJson('PUT', $path, ['question_ids' => [$c->id, $b->id, $a->id]])->assertStatus(422);
    expect($a->fresh()->position)->toBe(1)->and($c->fresh()->position)->toBe(2);

    // Gọi 2 lần liên tiếp với cùng danh sách: idempotent.
    vvCourseJson('PUT', $path, ['question_ids' => [$c->id, $a->id]])->assertOk();
    vvCourseJson('PUT', $path, ['question_ids' => [$c->id, $a->id]])->assertOk();
    expect($c->fresh()->position)->toBe(1)->and($a->fresh()->position)->toBe(2);
});

test('service doc quiz voi khoa dong (lockForUpdate)', function () {
    [$course, $quiz, [$a, $b]] = vvReorderSet(2);
    DB::enableQueryLog();
    app(QuizContentService::class)->reorder($course, $quiz, [$b->id, $a->id]);
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($sql)->toContain('for update');
});

test('xoa cau danh lai position lien mach 1..n', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b, $c]] = vvReorderSet();

    vvCourseJson('DELETE', vvQuizPath($course, $quiz, $a))->assertNoContent();

    expect($b->fresh()->position)->toBe(1)->and($c->fresh()->position)->toBe(2);
    vvCourseJson('POST', vvQuestionsPath($course, $quiz), vvQuestionPayload())->assertCreated()->assertJsonPath('position', 3);
});

test('reorder khong kich hoat copy-on-write khi cau da co luot lam', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b]] = vvReorderSet(2);
    vvFakeAttempt($quiz, [$a->id, $b->id]);

    $res = vvCourseJson('PUT', vvReorderPath($course, $quiz), ['question_ids' => [$b->id, $a->id]])->assertOk();

    expect(array_column($res->json('data'), 'id'))->toBe([$b->id, $a->id])
        ->and(QuizQuestion::withTrashed()->where('quiz_id', $quiz->id)->count())->toBe(2)
        ->and($a->fresh()->replaced_by_id)->toBeNull();
});

test('luot dang lam va luot da nop khong doi thu tu/diem sau reorder', function () {
    ['quiz' => $quiz, 'course' => $course, 'questions' => [$q1, $q2, $q3]] = vvAtSet(true, 3);

    // Lượt 1: nộp (đúng câu 1, sai câu 2, bỏ câu 3).
    $first = vvAtStart($quiz)->assertCreated()->json('id');
    vvAtAnswer($first, $q1, vvAtOpt($q1, 1))->assertSuccessful();
    vvAtAnswer($first, $q2, vvAtOpt($q2, 2))->assertSuccessful();
    $submitted = vvAtSubmit($first)->assertOk()->json();

    // Lượt 2: đang làm, đã trả lời câu 3.
    $second = vvAtStart($quiz)->assertCreated()->json('id');
    vvAtAnswer($second, $q3, vvAtOpt($q3, 1))->assertSuccessful();
    $beforeShow = vvAtShow($second)->assertOk()->json();
    $order = array_column($beforeShow['questions'], 'id');
    expect($order)->toBe([$q1->id, $q2->id, $q3->id]);

    // Gọi service (cùng đường với controller; API đã được test ở trên) để không phải đổi phiên học sinh/quản trị.
    app(QuizContentService::class)->reorder($course, $quiz, [$q3->id, $q1->id, $q2->id]);

    $afterShow = vvAtShow($second)->assertOk()->json();
    expect(array_column($afterShow['questions'], 'id'))->toBe($order)
        ->and($afterShow['answers'])->toEqual($beforeShow['answers']);

    // Lượt đang làm nộp sau reorder: thứ tự + điểm theo thứ tự chốt.
    $res = vvAtSubmit($second)->assertOk()->json();
    expect(array_column($res['questions'], 'id'))->toBe($order)->and($res['correct_count'])->toBe(1);

    $again = vvAtShow($first)->assertOk()->json();
    expect(array_column($again['questions'], 'id'))->toBe(array_column($submitted['questions'], 'id'))
        ->and($again['score'])->toEqual($submitted['score'])->and($again['correct_count'])->toBe($submitted['correct_count']);

    // Lượt MỚI sau reorder dùng thứ tự mới.
    $third = vvAtStart($quiz)->assertCreated()->json();
    expect(array_column($third['questions'], 'id'))->toBe([$q3->id, $q1->id, $q2->id]);
});
