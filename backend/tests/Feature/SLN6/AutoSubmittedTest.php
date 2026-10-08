<?php

use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/../T22/helpers.php';

afterEach(fn () => Carbon::setTestNow());

function vvSlnStart(): array
{
    Carbon::setTestNow('2026-10-06 10:00:00');
    config(['quiz.submit_grace_seconds' => 30]);
    $s = vvAtSet(timeLimit: 10, questions: 2);

    return [$s, vvAtStart($s['quiz'])->json('id')];
}

test('nop truoc expires_at -> auto_submitted=false', function () {
    [, $id] = vvSlnStart();
    Carbon::setTestNow('2026-10-06 10:09:59');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', false);
    expect(QuizAttempt::query()->find($id)->auto_submitted)->toBeFalse();
});

test('nop dung expires_at -> auto_submitted=true, giu cham diem', function () {
    [$s, $id] = vvSlnStart();
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();
    Carbon::setTestNow('2026-10-06 10:10:00');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true)->assertJsonPath('correct_count', 1);
});

test('nop trong an han (+29s) -> auto_submitted=true', function () {
    [, $id] = vvSlnStart();
    Carbon::setTestNow('2026-10-06 10:10:29');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true);
});

test('nop sau an han (+31s) -> auto_submitted=true, submitted_at = expires_at (hanh vi cu)', function () {
    [, $id] = vvSlnStart();
    Carbon::setTestNow('2026-10-06 10:10:31');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true);
    expect(QuizAttempt::query()->find($id)->submitted_at->toDateTimeString())->toBe('2026-10-06 10:10:00');
});

test('nop lai idempotent: giu auto_submitted/submitted_at cua lan dau', function () {
    [, $id] = vvSlnStart();
    Carbon::setTestNow('2026-10-06 10:10:05');
    $first = vvAtSubmit($id)->assertOk()->json();
    Carbon::setTestNow('2026-10-06 10:20:00');
    $second = vvAtSubmit($id)->assertOk()->json();
    expect($second['auto_submitted'])->toBeTrue()->and($second['submitted_at'])->toBe($first['submitted_at']);
});

test('khong gioi han thoi gian -> nop tay van auto_submitted=false', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(questions: 1);
    $id = vvAtStart($s['quiz'])->json('id');
    Carbon::setTestNow('2026-10-06 18:00:00');
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', false);
});
