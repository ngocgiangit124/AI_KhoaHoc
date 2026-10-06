<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * (Chạy riêng: `pest --group=race`.) Race thật bằng nhiều tiến trình PHP; dữ liệu commit thật, dọn trong finally.
 */
function vvProgWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/progress_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

function vvProgOnce(array $args): array
{
    $p = vvProgWorker($args);
    $p->mustRun();

    return vvProgDecode($p);
}

/** Lấy dòng JSON cuối của worker; lỗi thì in nguyên stdout/stderr để biết nguyên nhân. */
function vvProgDecode(Process $p): array
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $p->getOutput())), fn ($l) => $l !== ''));
    $last = $lines === [] ? '' : $lines[array_key_last($lines)];
    $data = json_decode($last, true);
    expect(is_array($data))->toBeTrue('Worker output: '.$p->getOutput().' | stderr: '.$p->getErrorOutput());

    return $data;
}

/** @return list<array<string, mixed>> */
function vvProgParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvProgWorker($a), $sets);
    foreach ($procs as $p) {
        $p->start();
    }
    $res = [];
    foreach ($procs as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        $res[] = vvProgDecode($p);
    }

    return $res;
}

test('race: 8 heartbeat dong thoi cung hoc sinh+bai -> 1 dong, khong 500/deadlock, khong cong don ao', function () {
    $s = vvProgOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvProgParallel(array_map(
            fn ($i) => ['heartbeat', $s['student'], $s['l1'], 10 + $i, 60, $startAt],
            range(0, 7),
        ));

        foreach ($results as $r) {
            expect($r['result'])->toBe('ok', json_encode($r));
        }

        $st = vvProgOnce(['state', $s['student'], $s['l1']]);
        // Lan dau cap 45s; 7 lan con lai trong cung ~1 giay: moi lan toi da 2s/giay troi qua (khong 8 x 60).
        expect($st['rows'])->toBe(1)->and($st['watched'])->toBeGreaterThanOrEqual(45)->and($st['watched'])->toBeLessThanOrEqual(45 + 2 * 3);
    } finally {
        vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
    }
})->group('race');

test('race: heartbeat dau tien >< xoa bai (T09-2) -> khong bao gio xoa duoc bai da co tien do', function () {
    for ($round = 0; $round < 6; $round++) {
        $s = vvProgOnce(['setup']);

        try {
            $startAt = microtime(true) + 2.0;
            [$hb, $del] = vvProgParallel([
                ['heartbeat', $s['student'], $s['l2'], 5, 5, $startAt],
                ['delete_lesson', $s['course'], $s['chapter'], $s['l2'], $startAt],
            ]);

            expect($hb['result'])->not->toBe('error', json_encode($hb))->and($del['result'])->not->toBe('error', json_encode($del));

            $st = vvProgOnce(['state', $s['student'], $s['l2']]);

            // Hoac: heartbeat ghi truoc -> xoa bi 409, bai con; hoac: xoa truoc -> heartbeat 404, khong co dong tien do.
            if ($hb['result'] === 'ok') {
                expect($del['code'] ?? null)->toBe('LESSON_HAS_PROGRESS')->and($st['rows'])->toBe(1)->and($st['deleted'])->toBeFalse();
            } else {
                expect($hb['status'])->toBe(404)->and($del['result'])->toBe('ok')->and($st['rows'])->toBe(0)->and($st['deleted'])->toBeTrue();
            }
        } finally {
            vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
        }
    }
})->group('race');

test('race QA: heartbeat >< thu hoi enrollment -> khong 500/deadlock; sau thu hoi heartbeat bi chan', function () {
    for ($round = 0; $round < 4; $round++) {
        $s = vvProgOnce(['setup']);

        try {
            $startAt = microtime(true) + 2.0;
            [$hb, $rv] = vvProgParallel([
                ['heartbeat', $s['student'], $s['l1'], 5, 5, $startAt],
                ['revoke', $s['student'], $s['course'], $startAt],
            ]);

            expect($hb['result'])->not->toBe('error', json_encode($hb))->and($rv['result'])->toBe('ok', json_encode($rv));
            if ($hb['result'] === 'domain') {
                expect($hb['code'])->toBe('COURSE_NOT_OWNED');
            }

            $after = vvProgParallel([['heartbeat', $s['student'], $s['l1'], 9, 9, microtime(true)]]);
            expect($after[0]['result'])->toBe('domain')->and($after[0]['code'])->toBe('COURSE_NOT_OWNED');
        } finally {
            vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
        }
    }
})->group('race');

test('race QA: heartbeat >< ghi courses.enrollments_count (T14) -> khong deadlock, count khong mat', function () {
    $s = vvProgOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $sets = [];
        for ($i = 0; $i < 4; $i++) {
            $sets[] = ['heartbeat', $s['student'], $s['l1'], 10 + $i, 30, $startAt];
            $sets[] = ['bump_count', $s['course'], $startAt];
        }
        foreach (vvProgParallel($sets) as $r) {
            expect($r['result'])->toBe('ok', json_encode($r));
        }

        expect((int) DB::table('courses')->where('id', $s['course'])->value('enrollments_count'))->toBeGreaterThanOrEqual(4);
    } finally {
        vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
    }
})->group('race');

test('race QA: cung user 2 tab, 2 bai khac nhau dong thoi -> ca hai ghi duoc, moi bai 1 dong', function () {
    $s = vvProgOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $res = vvProgParallel([
            ['heartbeat', $s['student'], $s['l1'], 10, 30, $startAt],
            ['heartbeat', $s['student'], $s['l2'], 10, 30, $startAt],
        ]);
        foreach ($res as $r) {
            expect($r['result'])->toBe('ok', json_encode($r));
        }
        expect(vvProgOnce(['state', $s['student'], $s['l1']])['rows'])->toBe(1)
            ->and(vvProgOnce(['state', $s['student'], $s['l2']])['rows'])->toBe(1);
    } finally {
        vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
    }
})->group('race');
