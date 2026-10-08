<?php

use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/../T22/helpers.php';

afterEach(fn () => Carbon::setTestNow());

function vvQaStart(int $questions = 2): array
{
    Carbon::setTestNow('2026-10-06 10:00:00');
    config(['quiz.submit_grace_seconds' => 30]);
    $s = vvAtSet(timeLimit: 10, questions: $questions);

    return [$s, vvAtStart($s['quiz'])->json('id')];
}

test('QA SLN6: nop tay trong an han -> submitted_at = gio nop (khong bi keo ve expires_at), diem giu nguyen', function () {
    [$s, $id] = vvQaStart();
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();
    Carbon::setTestNow('2026-10-06 10:10:20');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true)->assertJsonPath('correct_count', 1)->assertJsonPath('unanswered_count', 1);
    $a = QuizAttempt::query()->find($id);
    expect($a->submitted_at->toDateTimeString())->toBe('2026-10-06 10:10:20')->and($a->auto_submitted)->toBeTrue();
});

test('QA SLN6: dung moc an han (+30s) van chap nhan autosave; +31s -> 409 QUIZ_ATTEMPT_EXPIRED va luot duoc tu nop', function () {
    [$s, $id] = vvQaStart();
    Carbon::setTestNow('2026-10-06 10:10:30');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();
    Carbon::setTestNow('2026-10-06 10:10:31');
    vvAtAnswer($id, $s['questions'][1], vvAtOpt($s['questions'][1], 1))->assertStatus(409);
    $a = QuizAttempt::query()->find($id);
    expect($a->auto_submitted)->toBeTrue()->and($a->correct_count)->toBe(1)->and($a->submitted_at->toDateTimeString())->toBe('2026-10-06 10:10:00');
});

test('QA SLN6: dung moc an han (+30s) nop tay -> submitted_at = now, auto_submitted=true', function () {
    [, $id] = vvQaStart();
    Carbon::setTestNow('2026-10-06 10:10:30');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true);
    expect(QuizAttempt::query()->find($id)->submitted_at->toDateTimeString())->toBe('2026-10-06 10:10:30');
});

test('QA SLN6: GET luot qua han (lazy settle) -> auto_submitted=true; nop lai khong doi', function () {
    [, $id] = vvQaStart();
    Carbon::setTestNow('2026-10-06 10:11:00');
    vvAtShow($id)->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('auto_submitted', true);
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true);
});

test('QA SLN6: lich su (index) phan anh auto_submitted cua nop tay trong an han', function () {
    [$s, $id] = vvQaStart();
    Carbon::setTestNow('2026-10-06 10:10:05');
    vvAtSubmit($id)->assertOk();
    $this->getJson("/api/v1/learn/quizzes/{$s['quiz']->id}/attempts")->assertOk()->assertJsonPath('data.0.auto_submitted', true);
});
