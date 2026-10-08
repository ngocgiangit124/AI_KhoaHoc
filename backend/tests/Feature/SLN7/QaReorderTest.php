<?php

use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Foundation\Testing\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../T21/helpers.php';
require_once __DIR__.'/../T22/helpers.php';

/** @return array{0: Course, 1: Quiz, 2: list<QuizQuestion>} */
function vvQrSet(int $n, bool $options = false): array
{
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
    $qs = [];

    for ($i = 1; $i <= $n; $i++) {
        $f = QuizQuestion::factory()->for($quiz);
        $qs[] = ($options ? $f->withOptions() : $f)->create(['position' => $i]);
    }

    return [$course, $quiz, $qs];
}

function vvQrPath($course, $quiz): string
{
    return vvQuestionsPath($course, $quiz).'/order';
}

/** @return list<int> */
function vvQrLivePositions(Quiz $quiz): array
{
    return QuizQuestion::query()->where('quiz_id', $quiz->id)->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all();
}

test('QA: 200 cau dao nguoc -> 200, position 1..200, id giu nguyen', function () {
    vvCourseActor();
    [$course, $quiz, $qs] = vvQrSet(200);
    $ids = array_reverse(array_map(fn ($q) => $q->id, $qs));

    $res = vvCourseJson('PUT', vvQrPath($course, $quiz), ['question_ids' => $ids])->assertOk();

    expect(array_column($res->json('data'), 'id'))->toBe($ids)
        ->and(array_column($res->json('data'), 'position'))->toBe(range(1, 200))
        ->and(vvQrLivePositions($quiz))->toBe(range(1, 200));
});

test('QA: 201 phan tu -> 422 VALIDATION_ERROR (khong 500)', function () {
    vvCourseActor();
    [$course, $quiz] = vvQrSet(1);

    vvCourseJson('PUT', vvQrPath($course, $quiz), ['question_ids' => range(1, 201)])
        ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
});

test('QA: kieu du lieu question_ids', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b]] = vvQrSet(2);
    $path = vvQrPath($course, $quiz);

    // Gia tri sai -> VALIDATION_ERROR, khong ghi.
    foreach ([['question_ids' => null], [], ['question_ids' => 'x'], ['question_ids' => [1.5, $b->id]], ['question_ids' => [null, $b->id]],
        ['question_ids' => [[1], $b->id]], ['question_ids' => [true, $b->id]], ['question_ids' => [-1, $b->id]], ['question_ids' => ['1e2', $b->id]],
        ['question_ids' => ['id' => $a->id, 'x' => $b->id]] + []] as $body) {
        $r = vvCourseJson('PUT', $path, $body);
        expect($r->status())->toBe($body === ['question_ids' => ['id' => $a->id, 'x' => $b->id]] ? $r->status() : 422);
        expect($r->status())->not->toBe(500);
    }
    expect($a->fresh()->position)->toBe(1)->and($b->fresh()->position)->toBe(2);

    // Chuoi so nguyen hop le "id" duoc chap nhan (Laravel integer rule) va thuc su doi thu tu.
    vvCourseJson('PUT', $path, ['question_ids' => [(string) $b->id, (string) $a->id]])->assertOk();
    expect($b->fresh()->position)->toBe(1)->and($a->fresh()->position)->toBe(2);

    // Trung kieu: "5" va 5 coi la trung.
    vvCourseJson('PUT', $path, ['question_ids' => [(string) $a->id, $a->id]])->assertStatus(422);

    // Mang rong khi quiz khong con cau nao -> 200 mang rong (khong loi).
    $a->delete();
    $b->delete();
    vvCourseJson('PUT', $path, ['question_ids' => []])->assertOk()->assertJsonPath('data', []);
});

test('QA: id lon vuot int64 / so thuc dang chuoi khong gay 500', function () {
    vvCourseActor();
    [$course, $quiz, [$a]] = vvQrSet(1);

    foreach ([['99999999999999999999'], ['1.0'], [1.0], [PHP_INT_MAX]] as $ids) {
        $r = vvCourseJson('PUT', vvQrPath($course, $quiz), ['question_ids' => $ids]);
        expect($r->status())->toBeIn([200, 422]);
    }
});

test('QA: DELETE roi POST -> position dung (ke ca quiz co cau gan luot lam / da copy-on-write)', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b, $c]] = vvQrSet(3, true);
    vvFakeAttempt($quiz, [$a->id, $b->id, $c->id]);

    // Copy-on-write cau b (da co luot lam) -> id moi, cau cu xoa mem.
    $put = vvCourseJson('PUT', vvQuestionsPath($course, $quiz)."/{$b->id}", vvQuestionPayload())->assertOk();
    $b2 = $put->json('data.id') ?? $put->json('id');
    expect($b2)->not->toBe($b->id);
    expect(vvQrLivePositions($quiz))->toBe([1, 2, 3]);

    // Xoa cau dau (co luot lam -> xoa mem giu lua chon) -> renumber 1..2.
    vvCourseJson('DELETE', vvQuestionsPath($course, $quiz)."/{$a->id}")->assertNoContent();
    expect(vvQrLivePositions($quiz))->toBe([1, 2]);

    // POST moi = cuoi + 1 = 3, khong trung voi cau da xoa mem.
    $new = vvCourseJson('POST', vvQuestionsPath($course, $quiz), vvQuestionPayload())->assertCreated();
    expect((int) ($new->json('data.position') ?? $new->json('position')))->toBe(3)
        ->and(vvQrLivePositions($quiz))->toBe([1, 2, 3]);

    // Reorder sau do: chi tinh cau song, cau xoa mem khong lam 422.
    $live = QuizQuestion::query()->where('quiz_id', $quiz->id)->orderBy('position')->pluck('id')->all();
    vvCourseJson('PUT', vvQrPath($course, $quiz), ['question_ids' => array_reverse($live)])->assertOk();
    expect(QuizQuestion::query()->where('quiz_id', $quiz->id)->orderBy('position')->pluck('id')->all())->toBe(array_reverse($live))
        ->and(vvQrLivePositions($quiz))->toBe([1, 2, 3]);

    // Xoa giua, POST, vong lap: luon liên mạch.
    for ($i = 0; $i < 3; $i++) {
        $mid = QuizQuestion::query()->where('quiz_id', $quiz->id)->where('position', 2)->value('id');
        vvCourseJson('DELETE', vvQuestionsPath($course, $quiz)."/{$mid}")->assertNoContent();
        vvCourseJson('POST', vvQuestionsPath($course, $quiz), vvQuestionPayload())->assertCreated();
        expect(vvQrLivePositions($quiz))->toBe([1, 2, 3]);
    }
});

test('QA: xoa cau da xoa (idempotent) khong lam hong position', function () {
    vvCourseActor();
    [$course, $quiz, [$a, $b, $c]] = vvQrSet(3, true);

    vvCourseJson('DELETE', vvQuestionsPath($course, $quiz)."/{$b->id}")->assertNoContent();
    expect(vvQrLivePositions($quiz))->toBe([1, 2])->and($c->fresh()->position)->toBe(2);
});

test('QA: luot dang lam / da nop khong doi qua API that sau reorder qua HTTP quan tri', function () {
    // Dang nhap quan tri truoc (giu cookie), roi chuyen sang hoc sinh, roi quay lai quan tri.
    vvCourseActor();
    $adminCookie = (string) (new ReflectionProperty(TestCase::class, 'defaultCookies'))->getValue($this)[(string) config('session.admin_cookie')];

    $setup = vvAtSet(true, 3);
    ['quiz' => $quiz, 'course' => $course, 'questions' => [$q1, $q2, $q3]] = $setup;
    $first = vvAtStart($quiz)->assertCreated()->json('id');
    $order = array_column(vvAtShow($first)->assertOk()->json('questions'), 'id');

    app('auth')->forgetGuards();
    vvAdminUseCookie($adminCookie);
    vvCourseJson('PUT', vvQrPath($course, $quiz), ['question_ids' => [$q3->id, $q2->id, $q1->id]])->assertOk();

    vvActAsStudent($setup['student']);
    expect(array_column(vvAtShow($first)->assertOk()->json('questions'), 'id'))->toBe($order);

    vvAtSubmit($first)->assertOk();
    $second = vvAtStart($quiz)->assertCreated()->json('id');
    expect(array_column(vvAtShow($second)->assertOk()->json('questions'), 'id'))->toBe([$q3->id, $q2->id, $q1->id]);
});

// ---------------------------------------------------------------- race

function vvQrWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/quiz_reorder_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(180);

    return $p;
}

function vvQrOnce(array $args): array
{
    $p = vvQrWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

function vvQrParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvQrWorker($a), $sets);
    foreach ($procs as $p) {
        $p->start();
    }
    $res = [];
    foreach ($procs as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        $res[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    return $res;
}

function vvQrClean(array $s): void
{
    vvQrOnce(['cleanup', $s['course'], $s['creator'], implode(',', $s['students'])]);
}

test('race: reorder song song delete -> position 1..n lien mach, khong mat/trung cau', function () {
    foreach (range(1, 5) as $round) {
        $s = vvQrOnce(['setup', 6, 0]);

        try {
            $t = microtime(true) + 3.0;
            $qs = $s['questions'];
            $results = vvQrParallel([
                ['reorder', $s['course'], $t, $s['quiz'], implode(',', array_reverse($qs))],
                ['delete', $s['course'], $t, $s['quiz'], $qs[2]],
                ['reorder', $s['course'], $t, $s['quiz'], implode(',', [$qs[1], $qs[0], $qs[2], $qs[3], $qs[4], $qs[5]])],
                ['delete', $s['course'], $t, $s['quiz'], $qs[4]],
            ]);

            $codes = collect($results)->map(fn ($r) => $r['result'] === 'domain' ? $r['code'] : $r['result']);
            expect($codes->all())->not->toContain('error');
            foreach ($results as $r) {
                if ($r['result'] === 'domain') {
                    expect($r['code'])->toBe('QUIZ_QUESTIONS_MISMATCH');
                }
            }
            $st = vvQrOnce(['state', $s['quiz']]);
            // Hai delete luon thanh cong -> con 4 cau, position 1..4 doc nhat.
            expect(array_column($st['live'], 1))->toBe([1, 2, 3, 4]);
            $liveIds = array_column($st['live'], 0);
            expect(count(array_unique($liveIds)))->toBe(4)
                ->and(array_diff($liveIds, [$qs[0], $qs[1], $qs[3], $qs[5]]))->toBe([]);
        } finally {
            vvQrClean($s);
        }
    }
})->group('race');

test('race: reorder song song PUT copy-on-write (cau co luot lam) -> position lien mach, khong 500', function () {
    foreach (range(1, 4) as $round) {
        $s = vvQrOnce(['setup', 4, 1]);

        try {
            // Tao 1 luot lam de moi cau deu bi tham chieu -> PUT se copy-on-write.
            expect(vvQrOnce(['start', $s['students'][0], microtime(true), $s['quiz']])['result'])->toBe('ok');
            $qs = $s['questions'];
            $t = microtime(true) + 3.0;
            $results = vvQrParallel([
                ['reorder', $s['course'], $t, $s['quiz'], implode(',', array_reverse($qs))],
                ['put_q', $s['course'], $t, $s['quiz'], $qs[0]],
                ['put_q', $s['course'], $t, $s['quiz'], $qs[2]],
                ['reorder', $s['course'], $t, $s['quiz'], implode(',', [$qs[1], $qs[0], $qs[3], $qs[2]])],
            ]);

            // PUT cau da bi thay boi PUT khac co the 404/domain; khong duoc la 'error' (500/deadlock).
            expect(collect($results)->pluck('result')->all())->not->toContain('error');
            $st = vvQrOnce(['state', $s['quiz']]);
            expect(array_column($st['live'], 1))->toBe(range(1, count($st['live'])))
                ->and(count($st['live']))->toBe(4);
            // Luot lam cu giu nguyen thu tu ban dau.
            expect($st['attempts'][0])->toBe($qs);
        } finally {
            vvQrClean($s);
        }
    }
})->group('race');

test('race: reorder song song start attempt -> moi luot chot dung 1 trong 2 thu tu nguyen ven', function () {
    foreach (range(1, 4) as $round) {
        $s = vvQrOnce(['setup', 8, 6]);

        try {
            $qs = $s['questions'];
            $rev = array_reverse($qs);
            $t = microtime(true) + 3.0;
            $sets = [['reorder', $s['course'], $t, $s['quiz'], implode(',', $rev)]];
            foreach ($s['students'] as $stu) {
                $sets[] = ['start', $stu, $t, $s['quiz']];
            }
            $results = vvQrParallel($sets);

            expect(collect($results)->pluck('result')->all())->not->toContain('error')->and($results[0]['result'])->toBe('ok');
            $st = vvQrOnce(['state', $s['quiz']]);
            expect($st['attempts'])->toHaveCount(6);
            foreach ($st['attempts'] as $ids) {
                expect($ids)->toBeIn([$qs, $rev]);
            }
            expect(array_column($st['live'], 0))->toBe($rev)->and(array_column($st['live'], 1))->toBe(range(1, 8));
        } finally {
            vvQrClean($s);
        }
    }
})->group('race');

test('race: reorder + delete + add_q + start cung luc -> bat bien chung', function () {
    foreach (range(1, 3) as $round) {
        $s = vvQrOnce(['setup', 5, 3]);

        try {
            $qs = $s['questions'];
            $t = microtime(true) + 3.0;
            $results = vvQrParallel([
                ['reorder', $s['course'], $t, $s['quiz'], implode(',', array_reverse($qs))],
                ['delete', $s['course'], $t, $s['quiz'], $qs[0]],
                ['add_q', $s['course'], $t, $s['quiz'], 'n1'],
                ['start', $s['students'][0], $t, $s['quiz']],
                ['start', $s['students'][1], $t, $s['quiz']],
                ['start', $s['students'][2], $t, $s['quiz']],
            ]);

            expect(collect($results)->pluck('result')->all())->not->toContain('error');
            $st = vvQrOnce(['state', $s['quiz']]);
            expect(array_column($st['live'], 1))->toBe(range(1, count($st['live'])));
            $liveIds = array_column($st['live'], 0);
            expect(count(array_unique($liveIds)))->toBe(count($liveIds));
            foreach ($st['attempts'] as $ids) {
                expect(count(array_unique($ids)))->toBe(count($ids))->and(array_diff($ids, $st['all']))->toBe([]);
            }
        } finally {
            vvQrClean($s);
        }
    }
})->group('race');
