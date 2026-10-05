<?php

use App\Models\QuizAttempt;
use App\Services\Quiz\QuizAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

afterEach(fn () => Carbon::setTestNow());

test('co gioi han: expires_at = started_at + phut do server, remaining_seconds, server_now', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(timeLimit: 15);

    $res = vvAtStart($s['quiz'])->assertCreated();
    expect(Carbon::parse($res->json('expires_at'))->toDateTimeString())->toBe('2026-10-06 10:15:00')
        ->and($res->json('remaining_seconds'))->toBe(900)
        ->and(Carbon::parse($res->json('server_now'))->toDateTimeString())->toBe('2026-10-06 10:00:00');

    Carbon::setTestNow('2026-10-06 10:05:00');
    expect(vvAtShow($res->json('id'))->json('remaining_seconds'))->toBe(600);
});

test('cong tat FEATURE_QUIZ_TIME_LIMIT: bo qua gioi han cu', function () {
    config(['features.quiz_time_limit' => false]);
    $s = vvAtSet(timeLimit: 15);
    vvAtStart($s['quiz'])->assertCreated()->assertJsonPath('expires_at', null);
});

test('trong an han: autosave va nop van nhan (khong auto_submitted)', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    config(['quiz.submit_grace_seconds' => 30]);
    $s = vvAtSet(timeLimit: 10, questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');

    Carbon::setTestNow('2026-10-06 10:10:20'); // quá hạn 20s < 30s
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();
    vvAtShow($id)->assertJsonPath('status', 'in_progress')->assertJsonPath('remaining_seconds', 0);
    vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', false)->assertJsonPath('correct_count', 1);
});

test('qua an han: autosave 409 EXPIRED va tu nop bang dap an da luu, submitted_at = expires_at', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    config(['quiz.submit_grace_seconds' => 30]);
    $s = vvAtSet(timeLimit: 10, questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1))->assertNoContent();

    Carbon::setTestNow('2026-10-06 10:11:00');
    vvAtAnswer($id, $s['questions'][1], vvAtOpt($s['questions'][1], 1))->assertStatus(409)->assertJsonPath('code', 'QUIZ_ATTEMPT_EXPIRED');

    $attempt = QuizAttempt::query()->findOrFail($id);
    expect($attempt->isSubmitted())->toBeTrue()->and($attempt->auto_submitted)->toBeTrue()
        ->and($attempt->submitted_at->toDateTimeString())->toBe('2026-10-06 10:10:00')
        ->and($attempt->correct_count)->toBe(1)->and($attempt->answers)->toHaveCount(1);
    vvAtShow($id)->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('auto_submitted', true);
});

test('nop tre sau an han: van 200 voi ket qua cham theo autosave, danh dau auto_submitted', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(timeLimit: 10, questions: 2);
    $id = vvAtStart($s['quiz'])->json('id');
    vvAtAnswer($id, $s['questions'][0], vvAtOpt($s['questions'][0], 1));

    Carbon::setTestNow('2026-10-06 11:00:00');
    $res = vvAtSubmit($id)->assertOk()->assertJsonPath('auto_submitted', true)->assertJsonPath('correct_count', 1);
    expect(Carbon::parse($res->json('submitted_at'))->toDateTimeString())->toBe('2026-10-06 10:10:00');
});

test('bat dau khi luot cu da qua han: luot cu duoc tu nop, tao luot moi', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(timeLimit: 10);
    $old = vvAtStart($s['quiz'])->json('id');

    Carbon::setTestNow('2026-10-06 12:00:00');
    $new = vvAtStart($s['quiz'])->assertCreated()->json('id');
    expect($new)->not->toBe($old)->and(QuizAttempt::query()->find($old)->auto_submitted)->toBeTrue();
});

test('command quizzes:auto-submit-expired: chot luot qua han, bo qua luot con han va khong gioi han', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(timeLimit: 10);
    $expired = vvAtStart($s['quiz'])->json('id');

    $u2 = vvAtSet(timeLimit: 120);
    $alive = QuizAttempt::query()->findOrFail(vvAtStart($u2['quiz'])->json('id'));
    $u3 = vvAtSet();
    $free = QuizAttempt::query()->findOrFail(vvAtStart($u3['quiz'])->json('id'));

    Carbon::setTestNow('2026-10-06 10:30:00');
    Artisan::call('quizzes:auto-submit-expired');
    Artisan::call('quizzes:auto-submit-expired'); // idempotent

    expect(QuizAttempt::query()->find($expired)->isSubmitted())->toBeTrue()
        ->and(QuizAttempt::query()->find($expired)->auto_submitted)->toBeTrue()
        ->and($alive->fresh()->isSubmitted())->toBeFalse()
        ->and($free->fresh()->isSubmitted())->toBeFalse();
});

test('lich su: luot qua han duoc tu nop truoc khi liet ke', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $s = vvAtSet(timeLimit: 5);
    $id = vvAtStart($s['quiz'])->json('id');
    Carbon::setTestNow('2026-10-06 10:30:00');

    $res = $this->getJson(vvApiUrl("/learn/quizzes/{$s['quiz']->id}/attempts"), vvWebHeaders())->assertOk();
    expect($res->json('data.0.id'))->toBe($id)->and($res->json('data.0.status'))->toBe('submitted')->and($res->json('attempts_count'))->toBe(1);
});

test('command: mot luot loi khong chan cac luot sau', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $a = vvAtSet(timeLimit: 5);
    $bad = vvAtStart($a['quiz'])->json('id');
    $b = vvAtSet(timeLimit: 5);
    $good = vvAtStart($b['quiz'])->json('id');
    Carbon::setTestNow('2026-10-06 11:00:00');

    // Lượt hỏng: question_ids trỏ tới câu không tồn tại không gây lỗi; ép lỗi bằng service ném khi lượt này.
    $real = app(QuizAttemptService::class);
    $mock = Mockery::mock($real)->makePartial();
    $mock->shouldReceive('finalizeIfExpired')->andReturnUsing(function (QuizAttempt $att) use ($bad, $real) {
        if ($att->getKey() === $bad) {
            throw new RuntimeException('boom');
        }

        return $real->finalizeIfExpired($att);
    });
    app()->instance(QuizAttemptService::class, $mock);

    Artisan::call('quizzes:auto-submit-expired');

    expect(QuizAttempt::query()->find($bad)->isSubmitted())->toBeFalse()
        ->and(QuizAttempt::query()->find($good)->isSubmitted())->toBeTrue();
});
