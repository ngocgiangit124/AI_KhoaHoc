<?php

use Symfony\Component\Process\Process;

/**
 * DBA checklist §5 mục 2 bằng NHIỀU TIẾN TRÌNH PHP thật (mỗi tiến trình một kết nối MySQL, autocommit), bắt đầu
 * cùng một mốc thời gian. Dữ liệu được commit thật nên dọn thủ công trong finally (RefreshDatabase không dọn được).
 */
function vvWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(180);

    return $p;
}

/** @return array<string, mixed> */
function vvWorkerOnce(array $args): array
{
    $p = vvWorker($args);
    $p->mustRun();
    $out = trim($p->getOutput());
    expect(json_validate($out))->toBeTrue($out.$p->getErrorOutput());

    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  list<list<string|int|float>>  $argSets
 * @return list<array<string, mixed>>
 */
function vvWorkersParallel(array $argSets): array
{
    $procs = array_map(fn ($a) => vvWorker($a), $argSets);
    foreach ($procs as $p) {
        $p->start();
    }
    $results = [];
    foreach ($procs as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        $results[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

test('DBA checklist 2 (that, song song): 8 tien trinh cung tao enrollment pending/active -> dung 1 ban ghi, con lai 1062', function () {
    $ids = vvWorkerOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0; // chờ các tiến trình boot xong rồi mới cùng xuất phát
        $sets = [];
        foreach (['pending_approval', 'active', 'pending_approval', 'active', 'active', 'pending_approval', 'active', 'pending_approval'] as $st) {
            $sets[] = ['enroll', $ids['user'], $ids['course'], $st, $startAt];
        }

        $results = vvWorkersParallel($sets);

        $ok = array_values(array_filter($results, fn ($r) => $r['result'] === 'ok'));
        $err = array_values(array_filter($results, fn ($r) => $r['result'] === 'error'));

        expect($ok)->toHaveCount(1)
            ->and($err)->toHaveCount(7)
            ->and(collect($err)->pluck('code')->unique()->all())->toBe([1062]);

        $rows = DB::table('enrollments')->where('user_id', $ids['user'])->where('course_id', $ids['course'])->get();
        expect($rows)->toHaveCount(1)->and($rows[0]->live_flag)->toBe(1);
    } finally {
        vvWorkerOnce(['cleanup', $ids['user'], $ids['course'], $ids['creator']]);
    }
});

test('R2 (that, song song): 6 tien trinh cung tao chuyen de TRUNG TEN -> dung 1 thanh cong, con lai validation errors.name, khong 500', function () {
    $ids = vvWorkerOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvWorkersParallel(array_fill(0, 6, ['create_subject', 'RACE-Đại số', $startAt]));

        $kinds = collect($results)->countBy('result')->all();
        expect($kinds['ok'] ?? 0)->toBe(1)
            ->and($kinds['validation'] ?? 0)->toBe(5)
            ->and($kinds)->not->toHaveKey('error');
        expect(DB::table('subjects')->where('name', 'like', 'RACE-%')->count())->toBe(1);
    } finally {
        vvWorkerOnce(['cleanup', $ids['user'], $ids['course'], $ids['creator']]);
    }
});

test('R2 (that, song song): ten KHAC nhau nhung cung slug (RACE-C++ / RACE-C# / RACE-C) -> ca 3 deu tao duoc voi slug khac nhau', function () {
    $ids = vvWorkerOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvWorkersParallel([
            ['create_subject', 'RACE-C++', $startAt],
            ['create_subject', 'RACE-C#', $startAt],
            ['create_subject', 'RACE-C', $startAt],
            ['create_subject', 'RACE-C!', $startAt],
        ]);

        expect(collect($results)->pluck('result')->unique()->all())->toBe(['ok']);
        $slugs = DB::table('subjects')->where('name', 'like', 'RACE-%')->pluck('slug');
        expect($slugs)->toHaveCount(4)->and($slugs->unique())->toHaveCount(4);
    } finally {
        vvWorkerOnce(['cleanup', $ids['user'], $ids['course'], $ids['creator']]);
    }
});
