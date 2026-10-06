<?php

use Symfony\Component\Process\Process;

/**
 * (Chạy riêng: `pest --group=race`; loại: `--exclude-group=race`.) Từ T18 `enrollments.order_id` có FK → `orders.id`:
 * worker `setup` tạo đơn thật và test truyền `$ids['order']` cho `grantPurchase`.
 *
 * Race thật bằng nhiều tiến trình PHP (mỗi tiến trình một kết nối MySQL), cùng mốc xuất phát. Dữ liệu commit thật
 * nên dọn trong finally. Chạy nối tiếp với các test ghi DB thật khác (T05/T07).
 */
function vvEnrollWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/enrollment_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

/** @return array<string, mixed> */
function vvEnrollOnce(array $args): array
{
    $p = vvEnrollWorker($args);
    $p->mustRun();
    $out = trim($p->getOutput());
    expect(json_validate($out))->toBeTrue($out.$p->getErrorOutput());

    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvEnrollParallel(array $argSets): array
{
    $procs = array_map(fn ($a) => vvEnrollWorker($a), $argSets);
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

function vvEnrollCleanup(array $ids): void
{
    vvEnrollOnce(['cleanup', $ids['user'], $ids['course'], $ids['creator'], $ids['admin']]);
}

test('race: 8 tien trinh cung xin hoc 1 khoa -> dung 1 thanh cong, 7 ENROLLMENT_PENDING 409, khong 500, 1 dong live', function () {
    $ids = vvEnrollOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvEnrollParallel(array_fill(0, 8, ['request', $ids['user'], $ids['course'], $startAt]));

        $kinds = collect($results)->countBy('result')->all();
        expect($kinds['ok'] ?? 0)->toBe(1)
            ->and($kinds['domain'] ?? 0)->toBe(7)
            ->and($kinds)->not->toHaveKey('error')
            ->and(collect($results)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['ENROLLMENT_PENDING']);

        $state = vvEnrollOnce(['state', $ids['user'], $ids['course']]);
        expect($state['rows'])->toBe(1)->and($state['live'])->toBe(1);
    } finally {
        vvEnrollCleanup($ids);
    }
})->group('race');

test('race: 6 tien trinh cung duyet 1 yeu cau -> dung 1 thanh cong, 5 ALREADY_PROCESSED, count = 1', function () {
    $ids = vvEnrollOnce(['setup']);

    try {
        $enrollmentId = vvEnrollOnce(['pending', $ids['user'], $ids['course']])['enrollment'];
        $startAt = microtime(true) + 3.0;
        $results = vvEnrollParallel(array_fill(0, 6, ['approve', $enrollmentId, $ids['admin'], $startAt]));

        $kinds = collect($results)->countBy('result')->all();
        expect($kinds['ok'] ?? 0)->toBe(1)
            ->and($kinds['domain'] ?? 0)->toBe(5)
            ->and($kinds)->not->toHaveKey('error')
            ->and(collect($results)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['ALREADY_PROCESSED']);

        $state = vvEnrollOnce(['state', $ids['user'], $ids['course']]);
        expect($state['active'])->toBe(1)->and($state['count'])->toBe(1);
    } finally {
        vvEnrollCleanup($ids);
    }
})->group('race');

test('race: duyet va tu choi cung luc -> chi 1 ben thang, count khop trang thai', function () {
    $ids = vvEnrollOnce(['setup']);

    try {
        $enrollmentId = vvEnrollOnce(['pending', $ids['user'], $ids['course']])['enrollment'];
        $startAt = microtime(true) + 3.0;
        $results = vvEnrollParallel([
            ['approve', $enrollmentId, $ids['admin'], $startAt],
            ['reject', $enrollmentId, $ids['admin'], $startAt],
            ['approve', $enrollmentId, $ids['admin'], $startAt],
            ['reject', $enrollmentId, $ids['admin'], $startAt],
        ]);

        expect(collect($results)->where('result', 'ok'))->toHaveCount(1)
            ->and(collect($results)->where('result', 'error'))->toHaveCount(0);

        $state = vvEnrollOnce(['state', $ids['user'], $ids['course']]);
        expect($state['count'])->toBe($state['active']);
    } finally {
        vvEnrollCleanup($ids);
    }
})->group('race');

test('race: 6 tien trinh grantPurchase (IPN trung) -> 1 dong active, count = 1, khong loi', function () {
    $ids = vvEnrollOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvEnrollParallel(array_fill(0, 6, ['grant', $ids['user'], $ids['course'], $ids['order'], $startAt]));

        expect(collect($results)->pluck('result')->unique()->all())->toBe(['ok'])
            ->and(collect($results)->pluck('id')->unique())->toHaveCount(1);

        $state = vvEnrollOnce(['state', $ids['user'], $ids['course']]);
        expect($state['rows'])->toBe(1)->and($state['active'])->toBe(1)->and($state['count'])->toBe(1);
    } finally {
        vvEnrollCleanup($ids);
    }
})->group('race');

test('race: xin hoc mien phi dong thoi voi grantPurchase -> van chi 1 dong live, khong 500', function () {
    $ids = vvEnrollOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvEnrollParallel([
            ['request', $ids['user'], $ids['course'], $startAt],
            ['grant', $ids['user'], $ids['course'], $ids['order'], $startAt],
            ['request', $ids['user'], $ids['course'], $startAt],
            ['grant', $ids['user'], $ids['course'], $ids['order'], $startAt],
        ]);

        expect(collect($results)->where('result', 'error')->values()->all())->toBe([]);
        $state = vvEnrollOnce(['state', $ids['user'], $ids['course']]);
        expect($state['live'])->toBe(1)->and($state['rows'])->toBe(1);
    } finally {
        vvEnrollCleanup($ids);
    }
})->group('race');

test('race (T08 R1): xoa khoa song song voi xin hoc/grantPurchase -> khong bao gio co enrollment tren khoa da xoa, khong 500', function () {
    $ids = vvEnrollOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $results = vvEnrollParallel([
            ['delete_course', $ids['course'], $startAt],
            ['request', $ids['user'], $ids['course'], $startAt],
            ['delete_course', $ids['course'], $startAt],
            ['grant', $ids['user'], $ids['course'], $ids['order'], $startAt],
        ]);

        expect(collect($results)->where('result', 'error')->values()->all())->toBe([]);

        $state = vvEnrollOnce(['course_state', $ids['course']]);
        // Hoặc xoá thắng (0 enrollment), hoặc ghi danh thắng (khóa còn nguyên; xoá bị COURSE_HAS_ENROLLMENTS).
        expect($state['trashed'] && $state['enrollments'] > 0)->toBeFalse()
            ->and($state['count'])->toBeLessThanOrEqual($state['enrollments']);
    } finally {
        vvEnrollCleanup($ids);
    }
})->group('race');
