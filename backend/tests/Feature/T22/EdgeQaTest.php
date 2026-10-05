<?php

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

afterEach(fn () => Carbon::setTestNow());

test('QA AC7: bien an han +29s nhan autosave va nop tay, +31s autosave 409 EXPIRED', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    config(['quiz.submit_grace_seconds' => 30]);
    $s = vvAtSet(timeLimit: 10, questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');

    Carbon::setTestNow('2026-10-06 10:10:29');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();
    $s2 = vvAtSet(timeLimit: 10, questions: 1);
    Carbon::setTestNow('2026-10-06 10:00:00');
    $id2 = vvAtStart($s2['quiz'])->json('id');
    Carbon::setTestNow('2026-10-06 10:10:29');
    vvAtSubmit($id2)->assertOk()->assertJsonPath('auto_submitted', false);

    $s3 = vvAtSet(timeLimit: 10, questions: 1);
    Carbon::setTestNow('2026-10-06 10:00:00');
    $id3 = vvAtStart($s3['quiz'])->json('id');
    Carbon::setTestNow('2026-10-06 10:10:31');
    vvAtAnswer($id3, $s3['questions'][0], vvAtOpt($s3['questions'][0], 1))->assertStatus(409)->assertJsonPath('code', 'QUIZ_ATTEMPT_EXPIRED');
    $a = QuizAttempt::query()->findOrFail($id3);
    expect($a->auto_submitted)->toBeTrue()->and($a->submitted_at->toDateTimeString())->toBe('2026-10-06 10:10:00')->and($a->correct_count)->toBe(0);

    $s4 = vvAtSet(timeLimit: 10, questions: 1);
    Carbon::setTestNow('2026-10-06 10:00:00');
    $id4 = vvAtStart($s4['quiz'])->json('id');
    Carbon::setTestNow('2026-10-06 10:10:31');
    vvAtSubmit($id4)->assertOk()->assertJsonPath('auto_submitted', true);
});

test('QA: luot da bi command tu nop, tab cu autosave 409 SUBMITTED, submit tra ket qua cu', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(timeLimit: 10, questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();

    Carbon::setTestNow('2026-10-06 10:20:00');
    Artisan::call('quizzes:auto-submit-expired');

    vvAtAnswer($id, $s['questions'][1], vvAtOpt($s['questions'][1], 1))->assertStatus(409)->assertJsonPath('code', 'QUIZ_ATTEMPT_SUBMITTED');
    $res = vvAtSubmit($id)->assertOk()->assertJsonPath('correct_count', 1)->assertJsonPath('auto_submitted', true);
    expect(Carbon::parse($res->json('submitted_at'))->toDateTimeString())->toBe('2026-10-06 10:10:00');
    expect(QuizAttempt::query()->find($id)->answers)->toHaveCount(1);
});

test('QA: option_id 0, am, chuoi, so thuc, so rat lon, mang -> 422 va khong ghi', function () {
    $s = vvAtSet(questions: 1);
    $id = vvAtStart($s['quiz'])->json('id');
    $q = $s['questions'][0];
    foreach ([0, -5, 'abc', '1abc', 1.5, 99999999999999999999, [1], null, true] as $bad) {
        $res = $this->putJson(vvApiUrl("/learn/quiz-attempts/{$id}/answers/{$q->id}"), ['option_id' => $bad], vvWebHeaders());
        expect($res->status())->toBe(422, 'option_id='.json_encode($bad));
    }
    expect(json_decode(DB::table('quiz_attempts')->where('id', $id)->value('answers'), true))->toBe([]);
    // question id khong ton tai / am
    $this->putJson(vvApiUrl("/learn/quiz-attempts/{$id}/answers/999999"), ['option_id' => vvAtOpt($q, 1)], vvWebHeaders())->assertStatus(422);
});

test('QA: option cua quiz khac cung khoa -> 422 QUIZ_OPTION_INVALID', function () {
    $s = vvAtSet(questions: 1);
    $q2 = QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => Quiz::factory()->for($s['course'])->create(['position' => 2])->id, 'position' => 1]);
    $id = vvAtStart($s['quiz'])->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($q2, 1))->assertStatus(422)->assertJsonPath('code', 'QUIZ_OPTION_INVALID');
});

test('QA: admin xoa mem cau, quiz va chuong khi dang lam -> van cham theo cau cu', function () {
    $s = vvAtSet(questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');
    [$q1, $q2] = $s['questions'];
    $c1 = vvAtOpt($q1, 1);
    $c2 = vvAtOpt($q2, 1);
    vvAtAnswer($id, $q1, $c1)->assertNoContent();

    $q1->delete();
    $s['quiz']->delete();
    $s['quiz']->chapter?->delete();

    vvAtShow($id)->assertOk()->assertJsonCount(2, 'questions');
    vvAtAnswer($id, $q2, $c2)->assertNoContent();
    vvAtSubmit($id)->assertOk()->assertJsonPath('correct_count', 2)->assertJsonPath('score', 10);
});

test('QA: doi co FEATURE_QUIZ_TIME_LIMIT giua chung khong lam doi han luot dang lam', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    config(['features.quiz_time_limit' => true]);
    $s = vvAtSet(timeLimit: 10, questions: 1);
    $id = vvAtStart($s['quiz'])->json('id');
    config(['features.quiz_time_limit' => false]);
    Carbon::setTestNow('2026-10-06 10:11:00');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertStatus(409);
});

test('QA: khong lo is_correct/explanation/correct truoc khi nop (start, show, resume)', function () {
    $s = vvAtSet(questions: 2);
    $start = vvAtStart($s['quiz'])->assertCreated();
    $id = $start->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1));
    foreach ([$start->getContent(), vvAtShow($id)->getContent(), vvAtStart($s['quiz'])->getContent()] as $body) {
        expect($body)->not->toContain('is_correct')->not->toContain('explanation')->not->toContain('correct_option_id')->not->toContain('correct_count');
    }
});

test('QA: quiz 200 cau - so truy van start/show/submit khong tang theo so cau (khong N+1)', function () {
    $s = vvAtSet(questions: 200);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $id = vvAtStart($s['quiz'])->assertCreated()->json('id');
    $startQ = count(DB::getQueryLog());
    DB::flushQueryLog();
    vvAtShow($id)->assertOk();
    $showQ = count(DB::getQueryLog());
    DB::flushQueryLog();
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();
    DB::flushQueryLog();
    vvAtSubmit($id)->assertOk();
    $submitQ = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($startQ)->toBeLessThan(40)->and($showQ)->toBeLessThan(30)->and($submitQ)->toBeLessThan(40);
});
