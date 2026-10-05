<?php

use Symfony\Component\Process\Process;

/**
 * (Chạy riêng: `pest --group=race`.) Race thật bằng nhiều tiến trình PHP; dữ liệu commit thật, dọn trong finally.
 */
function vvCurWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/curriculum_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(60);

    return $p;
}

function vvCurOnce(array $args): array
{
    $p = vvCurWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvCurParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvCurWorker($a), $sets);
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

test('race: 2 reorder dong thoi (bo cuc khac nhau) -> ca 2 ok, position lien tuc 1..n, khong 500', function () {
    $s = vvCurOnce(['setup']);

    try {
        $a = json_encode([['chapter_id' => $s['c2'], 'lesson_ids' => [$s['l2']]], ['chapter_id' => $s['c1'], 'lesson_ids' => [$s['l1']]]]);
        $b = json_encode([['chapter_id' => $s['c1'], 'lesson_ids' => [$s['l1'], $s['l2']]], ['chapter_id' => $s['c2'], 'lesson_ids' => []]]);
        $startAt = microtime(true) + 3.0;
        $results = vvCurParallel([
            ['reorder', $s['course'], $startAt, $a], ['reorder', $s['course'], $startAt, $b],
            ['reorder', $s['course'], $startAt, $a], ['reorder', $s['course'], $startAt, $b],
        ]);

        expect(collect($results)->pluck('result')->unique()->all())->toBe(['ok']);

        $st = vvCurOnce(['state', $s['course']]);
        expect(array_column($st['chapters'], 'position'))->toBe([1, 2]);
        $byChapter = collect($st['lessons'])->groupBy('chapter_id');
        foreach ($byChapter as $ls) {
            expect($ls->pluck('position')->all())->toBe(range(1, $ls->count()));
        }
        expect(count($st['lessons']))->toBe(2);
    } finally {
        vvCurOnce(['cleanup', $s['course'], $s['creator']]);
    }
})->group('race');

test('race: reorder + tao bai dong thoi -> khong 500, nguoi sau CURRICULUM_MISMATCH hoac ok, position khong trung', function () {
    $s = vvCurOnce(['setup']);

    try {
        $items = json_encode([['chapter_id' => $s['c1'], 'lesson_ids' => [$s['l1']]], ['chapter_id' => $s['c2'], 'lesson_ids' => [$s['l2']]]]);
        $startAt = microtime(true) + 3.0;
        $results = vvCurParallel([
            ['reorder', $s['course'], $startAt, $items],
            ['create_lesson', $s['course'], $startAt, $s['c1']],
            ['create_lesson', $s['course'], $startAt, $s['c1']],
        ]);

        expect(collect($results)->pluck('result')->all())->not->toContain('error');
        foreach ($results as $r) {
            if ($r['result'] === 'domain') {
                expect($r['code'])->toBe('CURRICULUM_MISMATCH');
            }
        }

        $st = vvCurOnce(['state', $s['course']]);
        $c1 = collect($st['lessons'])->where('chapter_id', $s['c1']);
        expect($c1->pluck('position')->unique()->count())->toBe($c1->count())->and($c1->count())->toBe(3);
    } finally {
        vvCurOnce(['cleanup', $s['course'], $s['creator']]);
    }
})->group('race');
