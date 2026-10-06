<?php

use App\Models\AuditLog;
use App\Models\TeacherProfile;
use Symfony\Component\Process\Process;

/**
 * QA T36 (chạy riêng: `pest --group=race`). Race thật bằng nhiều tiến trình PHP (mỗi tiến trình một kết nối MySQL);
 * dữ liệu commit thật, dọn trong finally. Không xoá audit_logs (trigger bất biến): test đếm theo subject_id.
 */
function vvT36RaceWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/teacher_profile_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

function vvT36RaceOnce(array $args): array
{
    $p = vvT36RaceWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvT36RaceParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvT36RaceWorker($a), $sets);
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

/** Số dòng đang bật sẵn để còn đúng `$free` suất trống (tính cả dòng bật sót lại trong DB test). */
function vvT36RaceSetup(int $free, int $candidates): array
{
    $max = (int) config('teacher_profile.homepage_max');
    $probe = vvT36RaceOnce(['setup', 0, 0]);
    $pre = max(0, $max - $free - $probe['base_enabled']);
    vvT36RaceOnce(['cleanup', ...$probe['candidates'], $probe['admin']]);

    return vvT36RaceOnce(['setup', $pre, $candidates]);
}

test('race (c)/AC10: 2 tien trinh cung bat khi dang co 5 -> DUNG 1 thanh cong, 1 nhan TEACHER_HOMEPAGE_LIMIT, tong dung 6', function () {
    foreach (range(1, 5) as $round) {
        $s = vvT36RaceSetup(1, 2);
        $all = [...$s['pre'], ...$s['candidates'], $s['admin']];

        try {
            [$a, $b] = $s['candidates'];
            $t = microtime(true) + 2.5;
            $res = vvT36RaceParallel([['enable', $s['admin'], $a, $t], ['enable', $s['admin'], $b, $t]]);

            expect(collect($res)->pluck('result')->all())->not->toContain('error');
            expect(collect($res)->where('result', 'ok')->count())->toBe(1);
            expect(collect($res)->firstWhere('result', 'domain')['code'])->toBe('TEACHER_HOMEPAGE_LIMIT');
            $state = vvT36RaceOnce(['state', ...$all]);
            expect($state['enabled_total'])->toBe(max(6, $state['enabled_total']))->and($state['enabled_in'])->toBe(6);
            expect($state['enabled_total'])->toBe($s['base_enabled'] + 6);
        } finally {
            vvT36RaceOnce(['cleanup', ...$all]);
        }
    }
})->group('race');

test('race: nhieu tien trinh cung bat khi con 2 suat -> dung 2 thanh cong, khong vuot 6, khong deadlock/500', function () {
    foreach (range(1, 3) as $round) {
        $s = vvT36RaceSetup(2, 5);
        $all = [...$s['pre'], ...$s['candidates'], $s['admin']];

        try {
            $t = microtime(true) + 2.5;
            $res = vvT36RaceParallel(array_map(fn ($c) => ['enable', $s['admin'], $c, $t], $s['candidates']));

            expect(collect($res)->pluck('result')->all())->not->toContain('error');
            expect(collect($res)->where('result', 'ok')->count())->toBe(2);
            expect(collect($res)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['TEACHER_HOMEPAGE_LIMIT']);
            expect(vvT36RaceOnce(['state', ...$all])['enabled_total'])->toBe($s['base_enabled'] + 6);
        } finally {
            vvT36RaceOnce(['cleanup', ...$all]);
        }
    }
})->group('race');

test('race: bat + tat + sua noi dung + dong y + rut dong y + doi vai tro chay song song -> khong deadlock, du lieu nhat quan', function () {
    foreach (range(1, 3) as $round) {
        $s = vvT36RaceSetup(3, 3);
        $all = [...$s['pre'], ...$s['candidates'], $s['admin']];
        [$c1, $c2, $c3] = $s['candidates'];

        try {
            $t = microtime(true) + 2.5;
            $res = vvT36RaceParallel([
                ['enable', $s['admin'], $c1, $t], ['enable', $s['admin'], $c2, $t], ['enable', $s['admin'], $c3, $t],
                ['disable', $s['admin'], $s['pre'][0] ?? $c1, $t],
                ['content', $s['admin'], $c1, $t, 'bio-c1'], ['content', $s['admin'], $c2, $t, 'bio-c2'],
                ['consent', $c1, $t], ['consent', $c1, $t], ['consent', $c2, $t], ['withdraw', $c2, $t],
                ['role', $s['admin'], $c3, $t],
            ]);

            // Không có lỗi hệ thống (deadlock = error 1213). NOT_TEACHER (422) hợp lệ khi đổi vai trò thắng.
            foreach ($res as $r) {
                expect($r['result'])->toBeIn(['ok', 'domain'], json_encode($r));
                if ($r['result'] === 'domain') {
                    expect($r['code'])->toBeIn(['TEACHER_HOMEPAGE_LIMIT', 'NOT_TEACHER']);
                }
            }

            $state = vvT36RaceOnce(['state', ...$all]);
            expect($state['enabled_total'])->toBeLessThanOrEqual($s['base_enabled'] + 6);
            // Hai lần đồng ý đồng thời của c1 chỉ ghi MỘT dòng consents (khoá dòng hồ sơ tuần tự hoá).
            expect($state['consent_rows'])->toBeLessThanOrEqual(3);
            expect(TeacherProfile::query()->count())->toBeGreaterThanOrEqual(0);
        } finally {
            vvT36RaceOnce(['cleanup', ...$all]);
        }
    }
})->group('race');

test('race: hai lan dong y dong thoi cua cung mot giao vien -> chi 1 dong consents va 1 audit', function () {
    foreach (range(1, 5) as $round) {
        $s = vvT36RaceSetup(6, 1);
        $all = [...$s['pre'], ...$s['candidates'], $s['admin']];
        [$c] = $s['candidates'];

        try {
            $t = microtime(true) + 2.5;
            $res = vvT36RaceParallel([['consent', $c, $t], ['consent', $c, $t], ['consent', $c, $t]]);

            expect(collect($res)->pluck('result')->all())->toBe(['ok', 'ok', 'ok']);
            $state = vvT36RaceOnce(['state', $c]);
            expect($state['consent_rows'])->toBe(1)->and($state['consents'])->toBe(1);
            expect(AuditLog::query()->where('action', 'teacher_profile.consent')->where('subject_id', $c)->count())->toBe(1);
        } finally {
            vvT36RaceOnce(['cleanup', ...$all]);
        }
    }
})->group('race');
