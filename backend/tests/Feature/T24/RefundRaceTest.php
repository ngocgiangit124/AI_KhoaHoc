<?php

use Symfony\Component\Process\Process;

/**
 * Race thật nhiều tiến trình (mỗi tiến trình một kết nối MySQL) cho hoàn tiền (T24-V1, review R2). Chạy: `pest --group=race`.
 * Dữ liệu commit thật nên dọn trong finally (audit_logs bất biến nên các dòng audit ở lại, test đếm theo subject_id).
 */
function vvRfWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/refund_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    ]);
    $p->setTimeout(180);

    return $p;
}

/** @return array<string, mixed> */
function vvRfOnce(array $args): array
{
    $p = vvRfWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvRfParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvRfWorker($a), $sets);
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

test('race: 2-6 tien trinh hoan tien cung don -> dung 1 thanh cong, con lai 409 ALREADY_PROCESSED, enrollment thu hoi dung 1 lan', function (int $workers) {
    $s = vvRfOnce(['setup', 2]);

    try {
        $startAt = microtime(true) + 3.0;
        $res = vvRfParallel(array_fill(0, $workers, ['refund', $s['order'], $s['staff'], $startAt]));

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        expect(collect($res)->where('result', 'ok'))->toHaveCount(1)
            ->and(collect($res)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['ALREADY_PROCESSED'])
            ->and(collect($res)->where('result', 'domain')->pluck('status')->unique()->all())->toBe([409]);

        $state = vvRfOnce(['state', $s['order'], implode(',', $s['courses'])]);
        expect($state['status'])->toBe('refunded')
            ->and($state['revoked'])->toBe(2)->and($state['active'])->toBe(0)
            ->and($state['counts'])->toBe([0, 0]) // enrollments_count giảm đúng 1 lần mỗi khóa
            ->and($state['refund_logs'])->toBe(1)
            ->and($state['audit_refund'])->toBe(1)
            ->and($state['audit_revoke'])->toBe(2);
    } finally {
        vvRfOnce(['cleanup', $s['student'], $s['staff'], $s['order'], implode(',', $s['courses']), $s['creator']]);
    }
})->with([2, 6])->group('race');
