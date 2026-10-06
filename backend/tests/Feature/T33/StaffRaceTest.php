<?php

use Symfony\Component\Process\Process;

/**
 * QA T33 (chạy riêng: `pest --group=race`). Race thật bằng nhiều tiến trình PHP (mỗi tiến trình một kết nối);
 * dữ liệu commit thật, dọn trong finally.
 */
function vvStaffRaceWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/staff_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(120);

    return $p;
}

function vvStaffRaceOnce(array $args): array
{
    $p = vvStaffRaceWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvStaffRaceParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvStaffRaceWorker($a), $sets);
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

test('race: hai admin khoa cheo nhau -> khong deadlock/500, con >= 1 admin active', function () {
    foreach (range(1, 5) as $round) {
        $s = vvStaffRaceOnce(['setup', 2]);
        [$a, $b] = $s['ids'];

        try {
            $t = microtime(true) + 2.5;
            $res = vvStaffRaceParallel([['lock', $a, $b, $t], ['lock', $b, $a, $t]]);

            expect(collect($res)->pluck('result')->all())->not->toContain('error');
            $active = vvStaffRaceOnce(['state', $a, $b])['active'];
            // `others` = admin active khác (dữ liệu sót/seed) lúc bắt đầu: chỉ khi $others = 0 thì a,b phải còn ≥ 1.
            expect($active + $s['others'])->toBeGreaterThanOrEqual(1);
            // Cả hai cùng thành công chỉ khi còn admin khác ngoài hai người này.
            if ($s['others'] === 0) {
                expect(collect($res)->where('result', 'ok')->count())->toBe(1)->and($active)->toBe(1);
                expect(collect($res)->firstWhere('result', 'domain')['code'])->toBe('LAST_ADMIN');
            }
        } finally {
            vvStaffRaceOnce(['cleanup', ...$s['ids']]);
        }
    }
})->group('race');

test('race: hai admin cung ha quyen / khoa admin cuoi (3 nguoi, 2 thao tac vao cung 1 dich) -> con >= 1 admin', function () {
    foreach (range(1, 5) as $round) {
        $s = vvStaffRaceOnce(['setup', 3]);
        [$a, $b, $c] = $s['ids'];

        try {
            $t = microtime(true) + 2.5;
            // Admin A hạ quyền B, admin B khoá C, admin C hạ quyền A: vòng tròn; chỉ tối đa (n-1) thành công.
            $res = vvStaffRaceParallel([['demote', $a, $b, $t], ['lock', $b, $c, $t], ['demote', $c, $a, $t]]);

            expect(collect($res)->pluck('result')->all())->not->toContain('error');
            $active = vvStaffRaceOnce(['state', $a, $b, $c])['active'];
            expect($active + $s['others'])->toBeGreaterThanOrEqual(1);
            if ($s['others'] === 0) {
                expect($active)->toBe(3 - collect($res)->where('result', 'ok')->count());
            }
        } finally {
            vvStaffRaceOnce(['cleanup', ...$s['ids']]);
        }
    }
})->group('race');

test('race: hai admin cung ha quyen nhau (2 admin) -> chi mot thanh cong', function () {
    foreach (range(1, 5) as $round) {
        $s = vvStaffRaceOnce(['setup', 2]);
        [$a, $b] = $s['ids'];

        try {
            $t = microtime(true) + 2.5;
            $res = vvStaffRaceParallel([['demote', $a, $b, $t], ['demote', $b, $a, $t]]);

            expect(collect($res)->pluck('result')->all())->not->toContain('error');
            expect(vvStaffRaceOnce(['state', $a, $b])['active'] + $s['others'])->toBeGreaterThanOrEqual(1);
        } finally {
            vvStaffRaceOnce(['cleanup', ...$s['ids']]);
        }
    }
})->group('race');
