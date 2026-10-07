<?php

use Symfony\Component\Process\Process;

/**
 * QA T37 (chạy riêng: `pest --group=race`). Race thật bằng nhiều tiến trình PHP, provider Bunny (Http::fake trong tiến trình
 * con); dữ liệu commit thật, dọn trong finally.
 */
function bqWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/bunny_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

function bqOnce(array $args): array
{
    $p = bqWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function bqParallel(array $sets): array
{
    $procs = array_map(fn ($a) => bqWorker($a), $sets);
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

test('race T37: 2 upload Bunny song song cung nguoi, so 19GB + 2x1GB -> chi 1 thanh cong, so ghi dung 20GB', function () {
    $s = bqOnce(['setup', 19, 2]);

    try {
        $startAt = microtime(true) + 3.0;
        $results = bqParallel(array_map(fn ($l) => ['upload', $s['course'], $l, $s['actor'], $startAt, 1024], $s['lessons']));

        $by = collect($results)->groupBy('result');
        expect($by->get('ok', collect())->count())->toBe(1)
            ->and($by->get('domain', collect())->pluck('code')->all())->toBe(['VIDEO_QUOTA_EXCEEDED'])
            ->and($by->has('error'))->toBeFalse();

        $st = bqOnce(['state', $s['actor']]);
        expect($st['ledger'])->toBe(20 * 1024 * 1024 * 1024)
            ->and($st['assets'])->toHaveCount(1)
            ->and($st['assets'][0]['provider'])->toBe('bunny')->and($st['assets'][0]['provider_library_id'])->toBe('777');
    } finally {
        bqOnce(['cleanup', $s['course'], $s['actor'], $s['creator']]);
    }
})->group('race');

test('race T37: bien 18GB + 3 upload 1GB song song -> dung 2 thanh cong (cham tran 20GB), 1 bi VIDEO_QUOTA_EXCEEDED', function () {
    $s = bqOnce(['setup', 18, 3]);

    try {
        $startAt = microtime(true) + 3.0;
        $results = bqParallel(array_map(fn ($l) => ['upload', $s['course'], $l, $s['actor'], $startAt, 1024], $s['lessons']));

        $by = collect($results)->groupBy('result');
        expect($by->get('ok', collect())->count())->toBe(2)
            ->and($by->get('domain', collect())->pluck('code')->all())->toBe(['VIDEO_QUOTA_EXCEEDED'])
            ->and($by->has('error'))->toBeFalse();

        $st = bqOnce(['state', $s['actor']]);
        expect($st['ledger'])->toBe(20 * 1024 * 1024 * 1024)->and($st['assets'])->toHaveCount(2);
    } finally {
        bqOnce(['cleanup', $s['course'], $s['actor'], $s['creator']]);
    }
})->group('race');
