<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/../T03/LoginRaceTest.php';
require_once __DIR__.'/../T28/StaffLoginRaceTest.php';

/**
 * GL-A2 — race nhiều tiến trình (Redis thật). Chỉ chạy khi máy rảnh (load < 20). Worker: `tests/Support/login_race_worker.php`.
 * Env của tiến trình con bật lại cổng captcha (ngưỡng 5) vì hai file race cũ tắt nó (ngưỡng 1000) để giữ hành vi T03/T28.
 */
function glRaceWorker(bool $staff, array $args, array $env = []): Process
{
    $env += ['AUTH_LOGIN_CAPTCHA_THRESHOLD' => '5', 'AUTH_STAFF_LOGIN_CAPTCHA_THRESHOLD' => '5', 'AUTH_LOGIN_MAX_FAILURES' => '100', 'AUTH_STAFF_LOGIN_MAX_FAILURES' => '100'];

    return $staff ? vvStaffLoginRaceWorker($args, null, $env) : vvLoginRaceWorker($args, null, $env);
}

function glRaceOnce(bool $staff, array $args, array $env = []): array
{
    $p = glRaceWorker($staff, $args, $env);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Chạy song song; mỗi phần tử $jobs = [ip, mật khẩu ('bad'|'good'), captcha ('none'|'ok')].
 *
 * @return list<array{result: string, checks: int}>
 */
function glRaceBurst(bool $staff, string $email, array $jobs, array $env = []): array
{
    $startAt = microtime(true) + 4.0;
    $procs = array_map(fn (array $j) => glRaceWorker($staff, ['login', $email, $j[0], $startAt, $j[2] === 'ok' ? 'ok' : 'none', $j[1] === 'good' ? 'good' : 'bad'], $env), $jobs);
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

dataset('gl_race_kind', [['học sinh', false], ['quản trị', true]]);

test('song song: 15 tiến trình sai không captcha -> đúng 5 lượt so mật khẩu, 10 lượt CAPTCHA_REQUIRED (%s)', function (string $label, bool $staff) {
    $ids = glRaceOnce($staff, ['setup']);
    $ip = '10.78.'.random_int(1, 250).'.'.random_int(1, 250);

    try {
        $results = glRaceBurst($staff, $ids['email'], array_fill(0, 15, [$ip, 'bad', 'none']));
        $counts = array_count_values(array_column($results, 'result'));

        expect($counts['wrong'] ?? 0)->toBe(5, json_encode($results))
            ->and($counts['captcha'] ?? 0)->toBe(10)
            ->and(array_sum(array_column($results, 'checks')))->toBe(5);
    } finally {
        glRaceOnce($staff, ['cleanup', $ids['id'], $ip]);
    }
})->with('gl_race_kind')->group('race');

test('S1 song song: IP sát trần (49/50) + tài khoản 5, 20 tiến trình không captcha -> bộ đếm tài khoản <= 6, người thật có captcha vào được (%s)', function (string $label, bool $staff) {
    $env = ['AUTH_LOGIN_MAX_FAILURES_IP' => '50', 'AUTH_STAFF_LOGIN_MAX_FAILURES_IP' => '50'];
    $ids = glRaceOnce($staff, ['setup'], $env);
    $ip = '10.80.'.random_int(1, 250).'.'.random_int(1, 250);
    $realIp = '10.81.'.random_int(1, 250).'.'.random_int(1, 250);

    try {
        glRaceOnce($staff, ['preset', $ids['id'], $ip, 5, 49], $env);

        $results = glRaceBurst($staff, $ids['email'], array_fill(0, 20, [$ip, 'bad', 'none']), $env);
        $count = glRaceOnce($staff, ['count', $ids['id'], $ip], $env);

        expect($count['account'])->toBeLessThanOrEqual(6, json_encode([$results, $count]));

        $real = glRaceBurst($staff, $ids['email'], [[$realIp, 'good', 'ok']], $env);
        expect($real[0]['result'])->toBe('ok', json_encode($real));
    } finally {
        glRaceOnce($staff, ['cleanup', $ids['id'], $ip], $env);
        glRaceOnce($staff, ['cleanup', $ids['id'], $realIp], $env);
    }
})->with('gl_race_kind')->group('race');

test('S1 song song: tài khoản ở 99 (trần 100), 10 tiến trình không captcha từ nhiều IP -> bộ đếm <= 100, người thật có captcha vào được (%s)', function (string $label, bool $staff) {
    $ids = glRaceOnce($staff, ['setup']);
    $base = '10.82.'.random_int(1, 250).'.';
    $realIp = '10.83.'.random_int(1, 250).'.'.random_int(1, 250);
    $ips = array_map(fn (int $i) => $base.$i, range(1, 10));

    try {
        glRaceOnce($staff, ['preset', $ids['id'], $ips[0], 99, 0]);

        $results = glRaceBurst($staff, $ids['email'], array_map(fn (string $ip) => [$ip, 'bad', 'none'], $ips));
        $count = glRaceOnce($staff, ['count', $ids['id'], $ips[0]]);

        expect($count['account'])->toBeLessThanOrEqual(100, json_encode([$results, $count]));

        $real = glRaceBurst($staff, $ids['email'], [[$realIp, 'good', 'ok']]);
        expect($real[0]['result'])->toBe('ok', json_encode($real));
    } finally {
        foreach ([...$ips, $realIp] as $ip) {
            glRaceOnce($staff, ['cleanup', $ids['id'], $ip]);
        }
    }
})->with('gl_race_kind')->group('race');
