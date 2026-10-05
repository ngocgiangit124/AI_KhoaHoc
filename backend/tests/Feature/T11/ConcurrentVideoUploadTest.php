<?php

use Symfony\Component\Process\Process;

/**
 * (Chạy riêng: `pest --group=race`.) Race thật bằng nhiều tiến trình PHP; dữ liệu commit thật, dọn trong finally.
 */
function vvVidWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/video_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

function vvVidOnce(array $args): array
{
    $p = vvVidWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvVidParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvVidWorker($a), $sets);
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

function vvVidClean(array $s): void
{
    vvVidOnce(['cleanup', $s['course'], $s['l1'], $s['l2'], $s['actor'], $s['creator']]);
}

test('race: 2 upload song song cung actor sat han muc 20GB -> chi 1 thanh cong, con lai VIDEO_QUOTA_EXCEEDED', function () {
    $s = vvVidOnce(['setup', 19]);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvVidParallel([
            ['upload', $s['course'], $s['l1'], $s['actor'], $startAt, 1024],
            ['upload', $s['course'], $s['l2'], $s['actor'], $startAt, 1024],
        ]);

        $by = collect($results)->groupBy('result');
        expect($by->get('ok', collect())->count())->toBe(1)
            ->and($by->get('domain', collect())->pluck('code')->all())->toBe(['VIDEO_QUOTA_EXCEEDED'])
            ->and($by->has('error'))->toBeFalse();

        $st = vvVidOnce(['state', $s['actor'], $s['course']]);
        expect(collect($st['assets'])->sum('declared_size_bytes'))->toBeLessThanOrEqual(20 * 1024 * 1024 * 1024);
    } finally {
        vvVidClean($s);
    }
})->group('race');

test('race: upload + tao bai + reorder cung khoa -> khong deadlock/500, position khong trung', function () {
    $s = vvVidOnce(['setup', 0]);

    try {
        $items = json_encode([['chapter_id' => $s['chapter'], 'lesson_ids' => [$s['l2'], $s['l1']]]]);
        $startAt = microtime(true) + 3.0;
        $results = vvVidParallel([
            ['upload', $s['course'], $s['l1'], $s['actor'], $startAt, 10],
            ['upload', $s['course'], $s['l2'], $s['actor'], $startAt, 10],
            ['create_lesson', $s['course'], $startAt, $s['chapter']],
            ['reorder', $s['course'], $startAt, $items],
        ]);

        foreach ($results as $r) {
            expect($r['result'])->not->toBe('error', json_encode($r));
        }

        $st = vvVidOnce(['state', $s['actor'], $s['course']]);
        $pos = collect($st['lessons'])->pluck('position');
        expect($pos->unique()->count())->toBe($pos->count());
    } finally {
        vvVidClean($s);
    }
})->group('race');
