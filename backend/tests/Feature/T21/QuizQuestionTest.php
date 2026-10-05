<?php

use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Rules\QuizText;
use App\Services\Quiz\QuizContentService;

require_once __DIR__.'/helpers.php';

function vvQuizSet(): array
{
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);

    return [$course, $quiz];
}

test('tao cau hoi: 4 lua chon A-D, dung 1 dap an dung, position tang dan, audit', function () {
    vvCourseActor('admin');
    [$course, $quiz] = vvQuizSet();
    $path = vvQuestionsPath($course, $quiz);

    $r1 = vvCourseJson('POST', $path, vvQuestionPayload())->assertCreated()
        ->assertJsonPath('position', 1)
        ->assertJsonCount(4, 'options')
        ->assertJsonPath('options.1.is_correct', true)
        ->assertJsonPath('options.1.position', 2)
        ->assertJsonPath('explanation', 'Phân tích $(x-2)(x+2)=0$.');
    $r2 = vvCourseJson('POST', $path, vvQuestionPayload([], 4))->assertCreated()->assertJsonPath('position', 2);

    expect(QuizOption::query()->where('question_id', $r2->json('id'))->where('is_correct', true)->value('position'))->toBe(4);
    expect(AuditLog::query()->where('action', 'quiz_question.create')->count())->toBe(2);
    vvCourseJson('GET', $path)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $r1->json('id'));
});

test('validate cau hoi: 4 lua chon, dung 1 dap an dung, do dai, khong HTML', function () {
    vvCourseActor('admin');
    [$course, $quiz] = vvQuizSet();
    $path = vvQuestionsPath($course, $quiz);
    $post = fn (array $over) => vvCourseJson('POST', $path, vvQuestionPayload($over));

    $post(['options' => array_slice(vvQuestionPayload()['options'], 0, 3)])->assertUnprocessable()->assertJsonValidationErrors('options');
    $post(['options' => array_merge(vvQuestionPayload()['options'], [['content' => 'E', 'is_correct' => false]])])->assertUnprocessable();
    $none = vvQuestionPayload([], 9);
    $post(['options' => $none['options']])->assertUnprocessable()->assertJsonValidationErrors('options');
    $two = vvQuestionPayload()['options'];
    $two[0]['is_correct'] = true;
    $post(['options' => $two])->assertUnprocessable()->assertJsonValidationErrors('options');
    $post(['content' => ''])->assertUnprocessable()->assertJsonValidationErrors('content');
    $post(['content' => str_repeat('a', 5001)])->assertUnprocessable()->assertJsonValidationErrors('content');
    $post(['content' => str_repeat('a', 5000)])->assertCreated();
    $post(['explanation' => str_repeat('a', 5001)])->assertUnprocessable()->assertJsonValidationErrors('explanation');
    $post(['content' => 'x <script>alert(1)</script>'])->assertUnprocessable()->assertJsonValidationErrors('content');
    $post(['content' => '<img src=x onerror=alert(1)>'])->assertUnprocessable();
    $post(['explanation' => '<b>đậm</b>'])->assertUnprocessable()->assertJsonValidationErrors('explanation');
    $post(['content' => "a\x00b"])->assertUnprocessable();
    $opts = vvQuestionPayload()['options'];
    $opts[2]['content'] = str_repeat('b', 1001);
    $post(['options' => $opts])->assertUnprocessable()->assertJsonValidationErrors('options.2.content');
    $opts[2]['content'] = '<i>x</i>';
    $post(['options' => $opts])->assertUnprocessable()->assertJsonValidationErrors('options.2.content');
    $opts[2] = ['content' => 'ok', 'is_correct' => 'maybe'];
    $post(['options' => $opts])->assertUnprocessable()->assertJsonValidationErrors('options.2.is_correct');

    // Toán học có dấu so sánh đứng riêng, xuống dòng và LaTeX vẫn hợp lệ.
    $post(['content' => "Cho \$x > 2\$ và 3 < 5.\nTính \$\\frac{1}{x}\$", 'explanation' => null])->assertCreated();
    expect(QuizQuestion::query()->count())->toBe(2);
});

test('toi da 200 cau/quiz: cau thu 201 bi 422, xoa bot roi them lai duoc', function () {
    vvCourseActor('admin');
    [$course, $quiz] = vvQuizSet();
    foreach (range(1, 200) as $i) {
        QuizQuestion::factory()->for($quiz)->create(['position' => $i]);
    }
    $path = vvQuestionsPath($course, $quiz);

    vvCourseJson('POST', $path, vvQuestionPayload())->assertUnprocessable()->assertJsonPath('code', 'QUIZ_QUESTION_LIMIT');
    expect(QuizQuestion::query()->count())->toBe(200);

    QuizQuestion::query()->where('position', 200)->first()->delete();
    vvCourseJson('POST', $path, vvQuestionPayload())->assertCreated();
});

test('sua cau chua co luot lam: cap nhat tai cho, giu id', function () {
    vvCourseActor('admin');
    [$course, $quiz] = vvQuizSet();
    $q = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 1]);
    $optionIds = $q->options()->pluck('id')->all();

    vvCourseJson('PUT', vvQuizPath($course, $quiz, $q), vvQuestionPayload(['content' => 'Mới'], 3))
        ->assertOk()->assertJsonPath('id', $q->id)->assertJsonPath('content', 'Mới')->assertJsonPath('options.2.is_correct', true);

    expect($q->fresh()->content)->toBe('Mới')
        ->and($q->options()->pluck('id')->all())->toBe($optionIds)
        ->and(QuizQuestion::withTrashed()->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'quiz_question.update')->first()->changes['copy_on_write'])->toBeFalse();
});

test('copy-on-write: cau da co luot lam -> cau cu soft delete, cau moi cung position, lua chon cu giu nguyen', function () {
    vvCourseActor('admin');
    [$course, $quiz] = vvQuizSet();
    vvFakeAttemptTable();
    $q = QuizQuestion::factory()->for($quiz)->withOptions(1)->create(['position' => 5, 'content' => 'Cũ']);
    $other = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 6]);
    vvFakeAttempt($quiz, [$q->id, $other->id]);

    $res = vvCourseJson('PUT', vvQuizPath($course, $quiz, $q), vvQuestionPayload(['content' => 'Mới'], 3))->assertOk();
    $newId = $res->json('id');

    expect($newId)->not->toBe($q->id)->and($res->json('position'))->toBe(5);
    $old = QuizQuestion::withTrashed()->find($q->id);
    expect($old->trashed())->toBeTrue()->and($old->content)->toBe('Cũ')->and($old->replaced_by_id)->toBe($newId)
        ->and($old->options()->where('is_correct', true)->value('position'))->toBe(1);
    expect(QuizQuestion::query()->where('quiz_id', $quiz->id)->pluck('id')->all())->toContain($newId)->not->toContain($q->id);

    // Id cũ không còn dùng được; câu không có lượt khác vẫn sửa tại chỗ khi lượt không tham chiếu.
    vvCourseJson('PUT', vvQuizPath($course, $quiz, $q), vvQuestionPayload())->assertNotFound();
});

test('xoa cau hoi: co luot lam giu lua chon, khong luot thi xoa luon lua chon; idempotent', function () {
    vvCourseActor('admin');
    [$course, $quiz] = vvQuizSet();
    vvFakeAttemptTable();
    $used = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 1]);
    $free = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 2]);
    vvFakeAttempt($quiz, [$used->id]);

    vvCourseJson('DELETE', vvQuizPath($course, $quiz, $used))->assertNoContent();
    vvCourseJson('DELETE', vvQuizPath($course, $quiz, $free))->assertNoContent();

    expect(QuizOption::query()->where('question_id', $used->id)->count())->toBe(4)
        ->and(QuizOption::query()->where('question_id', $free->id)->count())->toBe(0)
        ->and(QuizQuestion::query()->count())->toBe(0);

    // Gọi lại với id đã xoá: route binding không thấy → 404 (không 500).
    vvCourseJson('DELETE', vvQuizPath($course, $quiz, $free))->assertNotFound();
});

test('dap an dung/giai thich khong lo qua serialize mac dinh cua model (I3)', function () {
    [, $quiz] = vvQuizSet();
    $q = QuizQuestion::factory()->for($quiz)->withOptions()->create(['position' => 1]);
    $q->load('options');

    $json = json_encode($q->toArray());
    expect($json)->not->toContain('is_correct')->and($json)->not->toContain('explanation');
    expect($q->options->first()->toArray())->not->toHaveKey('is_correct');
    // Truy cập tường minh vẫn dùng được cho chấm điểm/quản trị.
    expect($q->options->firstWhere('position', 1)->is_correct)->toBeTrue();
});

test('QuizText: bidi, zero-width, UTF-8 sai bi tu choi', function () {
    $rule = new QuizText;
    foreach (["a\u{202E}b", "a\u{200B}b", "a\u{2066}b", "a\xC3\x28b"] as $bad) {
        $failed = false;
        $rule->validate('content', $bad, function () use (&$failed) {
            $failed = true;
        });
        expect($failed)->toBeTrue();
    }
    $ok = true;
    $rule->validate('content', "Tính \$x>2\$\nđúng", function () use (&$ok) {
        $ok = false;
    });
    expect($ok)->toBeTrue();
});

test('co FEATURE_QUIZ_TIME_LIMIT tat: time_limit_minutes sai khong gay 422; tao null, sua giu nguyen', function () {
    vvCourseActor('admin');
    [$course, $chapter] = vvContentSet();
    config(['features.quiz_time_limit' => false]);

    $r = vvCourseJson('POST', vvQuizPath($course), ['title' => 'Q', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 9999])->assertCreated();
    expect($r->json('time_limit_minutes'))->toBeNull();

    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'time_limit_minutes' => 20, 'position' => 9]);
    vvCourseJson('PUT', vvQuizPath($course, $quiz), ['title' => 'Q', 'chapter_id' => $chapter->id, 'time_limit_minutes' => 'abc'])
        ->assertOk()->assertJsonPath('time_limit_minutes', 20);
});

test('service: QUIZ_OPTIONS_INVALID khi sai so luong hoac khong dung 1 dap an dung', function () {
    [$course, $quiz] = vvQuizSet();
    $svc = app(QuizContentService::class);
    $mk = fn (array $flags) => ['content' => 'c', 'options' => array_map(fn ($f) => ['content' => 'o', 'is_correct' => $f], $flags)];

    foreach ([[true, false, false], [false, false, false, false], [true, true, false, false]] as $flags) {
        try {
            $svc->create($course, $quiz, $mk($flags));
            $this->fail('phải ném DomainException');
        } catch (DomainException $e) {
            expect($e->code())->toBe('QUIZ_OPTIONS_INVALID');
        }
    }
    expect(QuizQuestion::query()->count())->toBe(0);
});
