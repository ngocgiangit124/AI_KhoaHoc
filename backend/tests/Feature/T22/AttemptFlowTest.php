<?php

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Quiz\QuizContentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

test('bat dau: 201, cau hoi theo thu tu, KHONG co is_correct/explanation/result; question_ids la so nguyen', function () {
    $s = vvAtSet(questions: 3);

    $res = vvAtStart($s['quiz'])->assertCreated()
        ->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('total_questions', 3)
        ->assertJsonPath('expires_at', null)
        ->assertJsonPath('remaining_seconds', null)
        ->assertJsonPath('questions.0.id', $s['questions'][0]->id)
        ->assertJsonPath('questions.2.id', $s['questions'][2]->id);

    $raw = json_encode($res->json());
    expect($raw)->not->toContain('is_correct')->and($raw)->not->toContain('explanation')->and($raw)->not->toContain('result')
        ->and($raw)->not->toContain('correct')->and($raw)->not->toContain('Lời giải');
    expect($res->json('questions.0.options'))->toHaveCount(4)
        ->and(array_keys($res->json('questions.0.options.0')))->toBe(['id', 'position', 'content']);
    expect(json_decode($res->getContent())->answers)->toBeObject();

    $types = DB::table('quiz_attempts')->selectRaw('JSON_TYPE(JSON_EXTRACT(question_ids, "$[0]")) AS t, JSON_TYPE(answers) AS a')->first();
    expect($types->t)->toBe('INTEGER')->and($types->a)->toBe('OBJECT');
});

test('bat dau lan 2 khi dang lam: 200, cung luot (resume), giu cau tra loi', function () {
    $s = vvAtSet();
    $id = vvAtStart($s['quiz'])->assertCreated()->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 2))->assertNoContent();

    $again = vvAtStart($s['quiz'])->assertOk();
    expect($again->json('id'))->toBe($id)
        ->and($again->json('answers'))->toBe([(string) $s['questions'][0]->id => vvAtOpt($s['questions'][0], 2)]);
    expect(QuizAttempt::query()->count())->toBe(1);
});

test('chua so huu khoa: 403 COURSE_NOT_OWNED; quiz rong 422 QUIZ_NOT_READY; quiz da xoa 404; chua dang nhap 401', function () {
    $s = vvAtSet(owned: false);
    vvAtStart($s['quiz'])->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    expect(QuizAttempt::query()->count())->toBe(0);

    $e = vvAtSet(questions: 0);
    vvAtStart($e['quiz'])->assertStatus(422)->assertJsonPath('code', 'QUIZ_NOT_READY');

    $d = vvAtSet();
    $d['quiz']->delete();
    vvAtStart($d['quiz'])->assertNotFound();
});

test('khong dang nhap: 401', function () {
    $this->postJson(vvApiUrl('/learn/quizzes/1/attempts'), [], vvWebHeaders())->assertUnauthorized();
});

test('autosave: 204, luu so nguyen, ghi de duoc, dung JSON_SET (khong mat cau khac)', function () {
    $s = vvAtSet();
    $id = vvAtStart($s['quiz'])->json('id');
    [$q1, $q2] = $s['questions'];

    vvAtAnswer($id, $q1, vvAtOpt($q1, 1))->assertNoContent();
    vvAtAnswer($id, $q2, vvAtOpt($q2, 3))->assertNoContent();
    vvAtAnswer($id, $q1, vvAtOpt($q1, 4))->assertNoContent();

    $row = DB::table('quiz_attempts')->where('id', $id)->first();
    $answers = json_decode($row->answers, true);
    expect($answers)->toBe([(string) $q1->id => vvAtOpt($q1, 4), (string) $q2->id => vvAtOpt($q2, 3)]);
    expect(DB::table('quiz_attempts')->where('id', $id)->selectRaw('JSON_TYPE(JSON_EXTRACT(answers, ?)) AS t', ['$."'.$q1->id.'"'])->value('t'))->toBe('INTEGER');
});

test('autosave: option khong thuoc cau, cau khong thuoc luot, body sai -> 422; luot nguoi khac -> 404', function () {
    $s = vvAtSet();
    $other = vvAtSet(questions: 1); // đăng nhập lại thành học sinh khác
    $otherAttempt = vvAtStart($other['quiz'])->json('id');
    $foreignQuestion = $other['questions'][0];

    // $other đang là người đăng nhập; tạo lượt của $s cho học sinh $s rồi thử truy cập từ $other.
    $theirs = QuizAttempt::factory()->create([
        'user_id' => $s['student']->id, 'quiz_id' => $s['quiz']->id,
        'question_ids' => array_map(fn ($q) => $q->id, $s['questions']),
    ]);
    vvAtAnswer($theirs->id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNotFound();
    vvAtSubmit($theirs->id)->assertNotFound();
    vvAtShow($theirs->id)->assertNotFound();

    // Với lượt của chính mình ($other): option của câu khác, câu ngoài lượt.
    $q = $other['questions'][0];
    vvAtAnswer($otherAttempt, $q, vvAtOpt($s['questions'][0], 1))->assertStatus(422)->assertJsonPath('code', 'QUIZ_OPTION_INVALID');
    vvAtAnswer($otherAttempt, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertStatus(422)->assertJsonPath('code', 'QUIZ_QUESTION_NOT_IN_ATTEMPT');
    vvAtAnswer($otherAttempt, $q, 999999)->assertStatus(422)->assertJsonPath('code', 'QUIZ_OPTION_INVALID');
    $this->putJson(vvApiUrl("/learn/quiz-attempts/{$otherAttempt}/answers/{$q->id}"), ['option_id' => 'abc'], vvWebHeaders())->assertStatus(422);
    $this->putJson(vvApiUrl("/learn/quiz-attempts/{$otherAttempt}/answers/{$q->id}"), [], vvWebHeaders())->assertStatus(422);
    expect($foreignQuestion->id)->toBe($q->id);
    expect(json_decode(DB::table('quiz_attempts')->where('id', $otherAttempt)->value('answers'), true))->toBe([]);
});

test('nop bai: cham o server, diem thang 10, lo dap an/giai thich sau khi nop, idempotent', function () {
    $s = vvAtSet(questions: 4);
    $id = vvAtStart($s['quiz'])->json('id');
    [$q1, $q2, $q3] = $s['questions'];
    vvAtAnswer($id, $q1, vvAtOpt($q1, 1));   // đúng
    vvAtAnswer($id, $q2, vvAtOpt($q2, 2));   // sai
    vvAtAnswer($id, $q3, vvAtOpt($q3, 1));   // đúng (câu 4 bỏ trống)

    // Trước khi nộp, xem lượt vẫn không lộ gì.
    $before = vvAtShow($id)->assertOk()->assertJsonPath('status', 'in_progress');
    expect(json_encode($before->json()))->not->toContain('explanation')->and(json_encode($before->json()))->not->toContain('is_correct');

    $res = vvAtSubmit($id)->assertOk()
        ->assertJsonPath('status', 'submitted')
        ->assertJsonPath('correct_count', 2)
        ->assertJsonPath('total_questions', 4)
        ->assertJsonPath('unanswered_count', 1)
        ->assertJsonPath('auto_submitted', false)
        ->assertJsonPath('questions.0.is_correct', true)
        ->assertJsonPath('questions.0.correct_option_id', vvAtOpt($q1, 1))
        ->assertJsonPath('questions.1.is_correct', false)
        ->assertJsonPath('questions.1.selected_option_id', vvAtOpt($q2, 2))
        ->assertJsonPath('questions.1.correct_option_id', vvAtOpt($q2, 1))
        ->assertJsonPath('questions.3.selected_option_id', null);
    expect($res->json('score'))->toEqualWithDelta(5.0, 0.001)
        ->and($res->json('questions.0.explanation'))->toBe($q1->explanation);

    $again = vvAtSubmit($id)->assertOk();
    expect($again->json('score'))->toEqualWithDelta(5.0, 0.001)->and($again->json('submitted_at'))->toBe($res->json('submitted_at'));
    vvAtShow($id)->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('correct_count', 2);
    vvAtAnswer($id, $q1, vvAtOpt($q1, 2))->assertStatus(409)->assertJsonPath('code', 'QUIZ_ATTEMPT_SUBMITTED');
    expect(QuizAttempt::query()->count())->toBe(1);
});

test('diem lam tron 2 chu so (1/3 -> 3.33), tat ca dung -> 10', function () {
    $s = vvAtSet(questions: 3);
    $id = vvAtStart($s['quiz'])->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1));
    expect(vvAtSubmit($id)->json('score'))->toEqualWithDelta(3.33, 0.001);

    $id2 = vvAtStart($s['quiz'])->assertCreated()->json('id');

    foreach ($s['questions'] as $q) {
        vvAtAnswer($id2, $q, vvAtOpt($q, 1));
    }

    expect(vvAtSubmit($id2)->json('score'))->toEqualWithDelta(10.0, 0.001);
});

test('lam lai: luot moi khong ghi de luot cu; lich su + diem cao nhat', function () {
    $s = vvAtSet(questions: 2);
    $a = vvAtStart($s['quiz'])->json('id');
    vvAtAnswer($a, $s['questions'][0], vvAtOpt($s['questions'][0], 1));
    vvAtSubmit($a);

    $b = vvAtStart($s['quiz'])->assertCreated()->json('id');
    expect($b)->not->toBe($a);
    foreach ($s['questions'] as $q) {
        vvAtAnswer($b, $q, vvAtOpt($q, 1));
    }
    vvAtSubmit($b);
    $c = vvAtStart($s['quiz'])->assertCreated()->json('id'); // đang làm

    $res = $this->getJson(vvApiUrl("/learn/quizzes/{$s['quiz']->id}/attempts"), vvWebHeaders())->assertOk();
    expect($res->json('best_score'))->toEqualWithDelta(10.0, 0.001)
        ->and($res->json('attempts_count'))->toBe(2)
        ->and(array_column($res->json('data'), 'id'))->toBe([$c, $b, $a])
        ->and($res->json('data.0.status'))->toBe('in_progress')
        ->and(json_encode($res->json()))->not->toContain('explanation')->and(json_encode($res->json()))->not->toContain('"questions"');
    expect(QuizAttempt::query()->whereKey($a)->value('score'))->toEqual(5.0);
});

test('lich su: khong lo luot cua hoc sinh khac; chua so huu 403', function () {
    $s = vvAtSet(questions: 1);
    QuizAttempt::factory()->submitted(1)->create(['quiz_id' => $s['quiz']->id, 'question_ids' => [$s['questions'][0]->id]]);

    $res = $this->getJson(vvApiUrl("/learn/quizzes/{$s['quiz']->id}/attempts"), vvWebHeaders())->assertOk();
    expect($res->json('data'))->toBe([])->and($res->json('best_score'))->toBeNull()->and($res->json('attempts_count'))->toBe(0);

    $n = vvAtSet(owned: false);
    $this->getJson(vvApiUrl("/learn/quizzes/{$n['quiz']->id}/attempts"), vvWebHeaders())->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
});

test('copy-on-write: luot dang lam giu nguyen cau cu (withTrashed), admin sua cau -> id moi, ket qua cham theo cau cu', function () {
    $s = vvAtSet(questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');
    [$q1, $q2] = $s['questions'];
    $oldContent = $q1->content;
    $oldCorrect = vvAtOpt($q1, 1);

    $new = app(QuizContentService::class)->update($s['course'], $s['quiz'], $q1, [
        'content' => 'Câu đã sửa', 'explanation' => null,
        'options' => array_map(fn ($i) => ['content' => "n$i", 'is_correct' => $i === 3], [1, 2, 3, 4]),
    ]);
    expect($new->id)->not->toBe($q1->id);

    $show = vvAtShow($id)->assertOk();
    expect($show->json('questions.0.id'))->toBe($q1->id)->and($show->json('questions.0.content'))->toBe($oldContent);

    vvAtAnswer($id, $q1, $oldCorrect)->assertNoContent();
    $res = vvAtSubmit($id)->assertOk()->assertJsonPath('questions.0.correct_option_id', $oldCorrect)->assertJsonPath('correct_count', 1);
    expect($res->json('questions.0.content'))->toBe($oldContent);

    // Lượt mới thấy câu mới, không thấy câu cũ.
    $id2 = vvAtStart($s['quiz'])->assertCreated()->json('id');
    expect(QuizAttempt::query()->find($id2)->question_ids)->toBe([$new->id, $q2->id]);
});

test('tao luot khi cau da bi xoa: bo qua cau xoa; xoa het cau -> QUIZ_NOT_READY', function () {
    $s = vvAtSet(questions: 1);
    $s['questions'][0]->delete();
    vvAtStart($s['quiz'])->assertStatus(422)->assertJsonPath('code', 'QUIZ_NOT_READY');
});

test('hasAttempts that: JSON_CONTAINS khop so nguyen, loc theo quiz', function () {
    $s = vvAtSet(questions: 2);
    $svc = app(QuizContentService::class);
    expect($svc->hasAttempts($s['quiz'], $s['questions'][0]))->toBeFalse();
    vvAtStart($s['quiz']);
    expect($svc->hasAttempts($s['quiz'], $s['questions'][0]))->toBeTrue()
        ->and($svc->hasAttempts($s['quiz'], QuizQuestion::factory()->create(['quiz_id' => $s['quiz']->id])))->toBeFalse();
});

test('CHECK DB: question_ids rong hoac > 200 bi tu choi; unique 1 luot dang lam/quiz', function () {
    $s = vvAtSet(questions: 1);
    $base = ['user_id' => $s['student']->id, 'quiz_id' => $s['quiz']->id, 'course_id' => $s['course']->id,
        'answers' => '{}', 'started_at' => now(), 'total_questions' => 1, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::table('quiz_attempts')->insert($base + ['question_ids' => '[]']))->toThrow(QueryException::class);
    expect(fn () => DB::table('quiz_attempts')->insert($base + ['question_ids' => json_encode(range(1, 201))]))->toThrow(QueryException::class);

    DB::table('quiz_attempts')->insert($base + ['question_ids' => '[1]']);
    expect(fn () => DB::table('quiz_attempts')->insert($base + ['question_ids' => '[1]']))->toThrow(QueryException::class);
    DB::table('quiz_attempts')->update(['submitted_at' => now()]);
    DB::table('quiz_attempts')->insert($base + ['question_ids' => '[1]']); // đã nộp thì được lượt mới
    expect(DB::table('quiz_attempts')->count())->toBe(2);
});

test('mat quyen so huu giua chung: autosave/nop 403', function () {
    $s = vvAtSet();
    $id = vvAtStart($s['quiz'])->json('id');
    DB::table('enrollments')->where('user_id', $s['student']->id)->update(['status' => 'revoked']);
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    vvAtSubmit($id)->assertForbidden();
    expect(User::query()->count())->toBeGreaterThan(0);
});
