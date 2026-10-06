<?php

use Symfony\Component\Process\Process;

/**
 * Race thật (chạy riêng: `pest --group=race`): nhiều tiến trình PHP, mỗi tiến trình 1 kết nối MySQL. Dữ liệu
 * commit thật, dọn trong finally.
 */
function vvQAWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/quiz_attempt_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

function vvQAOnce(array $args): array
{
    $p = vvQAWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvQAParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvQAWorker($a), $sets);
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

function vvQAClean(array $s): void
{
    vvQAOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
}

test('race: 6 tab bat dau cung luc -> dung 1 luot dang lam, tat ca nhan cung id, khong 500/deadlock', function () {
    $s = vvQAOnce(['setup', 3]);

    try {
        $at = microtime(true) + 3.0;
        $res = vvQAParallel(array_map(fn () => ['start', $s['student'], $s['quiz'], $at], range(1, 6)));

        foreach ($res as $r) {
            expect($r['result'])->toBe('ok', json_encode($r));
        }

        $st = vvQAOnce(['state', $s['quiz']]);
        expect($st['attempts'])->toBe(1)->and($st['open'])->toBe(1)
            ->and(array_unique(array_column($res, 'attempt')))->toHaveCount(1)
            ->and(count(array_filter($res, fn ($r) => $r['created'])))->toBeGreaterThanOrEqual(1);
    } finally {
        vvQAClean($s);
    }
})->group('race');

test('race: 2 tab nop cung luc + autosave -> 1 lan cham duy nhat, submitted_at giong nhau, khong 500', function () {
    for ($round = 0; $round < 3; $round++) {
        $s = vvQAOnce(['setup', 2]);

        try {
            $attempt = vvQAOnce(['start', $s['student'], $s['quiz'], 0])['attempt'];
            $opt = vvQAOnce(['option', $s['questions'][0], 1])['id'];
            vvQAOnce(['answer', $s['student'], $attempt, $s['questions'][0], $opt, 0]);

            $at = microtime(true) + 3.0;
            $res = vvQAParallel([
                ['submit', $s['student'], $attempt, $at],
                ['submit', $s['student'], $attempt, $at],
                ['submit', $s['student'], $attempt, $at],
                ['answer', $s['student'], $attempt, $s['questions'][1], vvQAOnce(['option', $s['questions'][1], 1])['id'], $at],
            ]);

            foreach ($res as $r) {
                expect($r['result'])->not->toBe('error', json_encode($r));
            }

            foreach (array_slice($res, 0, 3) as $r) {
                expect($r['result'])->toBe('ok', json_encode($r));
            }

            // Autosave hoặc lọt trước lần nộp (đếm 2 đúng) hoặc nhận 409; 3 lần nộp phải trả cùng kết quả đã chốt.
            if ($res[3]['result'] === 'domain') {
                expect($res[3]['code'])->toBe('QUIZ_ATTEMPT_SUBMITTED');
            }

            $correct = array_unique(array_column(array_slice($res, 0, 3), 'correct'));
            expect($correct)->toHaveCount(1)->and(array_unique(array_column(array_slice($res, 0, 3), 'submitted_at')))->toHaveCount(1);

            $st = vvQAOnce(['state', $s['quiz']]);
            expect($st['attempts'])->toBe(1)->and($st['open'])->toBe(0)->and($st['rows'][0]['correct_count'])->toBe($correct[0]);
        } finally {
            vvQAClean($s);
        }
    }
})->group('race');

test('race: bat dau luot >< admin sua cau (COW) -> khong bao gio sua tai cho cau da nam trong luot', function () {
    for ($round = 0; $round < 5; $round++) {
        $s = vvQAOnce(['setup', 1]);

        try {
            $at = microtime(true) + 2.0;
            [$start, $put] = vvQAParallel([
                ['start', $s['student'], $s['quiz'], $at],
                ['put_q', $s['course'], $s['quiz'], $s['questions'][0], $at],
            ]);

            expect($start['result'])->toBe('ok', json_encode($start))->and($put['result'])->toBe('ok', json_encode($put));

            $st = vvQAOnce(['state', $s['quiz']]);
            $ids = json_decode($st['rows'][0]['question_ids'], true);
            $byId = array_column($st['questions'], null, 'id');
            $original = $s['questions'][0];

            // COW chỉ xảy ra khi lượt đã tồn tại lúc admin sửa: câu gốc bị xoá mềm <=> lượt tham chiếu câu gốc.
            if ($byId[$original]['deleted_at'] !== null) {
                expect($ids)->toBe([$original])->and($byId[$original]['replaced_by_id'])->not->toBeNull();
            } else {
                // Admin sửa tại chỗ TRƯỚC khi có lượt: lượt chốt đúng câu (đã sửa) đang sống.
                expect($ids)->toBe([$original])->and($byId[$original]['content'])->toBe('put');
            }
        } finally {
            vvQAClean($s);
        }
    }
})->group('race');
