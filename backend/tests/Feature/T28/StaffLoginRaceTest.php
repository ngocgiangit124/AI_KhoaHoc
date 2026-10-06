<?php

use Symfony\Component\Process\Process;

/**
 * Sửa lỗi nhỏ 2 (BUG-1) cho đăng nhập quản trị: 15 TIẾN TRÌNH đăng nhập sai cùng lúc vào 1 tài khoản, bộ đếm dùng chung qua Redis thật
 * (store `redis-limiter`, vì store `array` không chia sẻ giữa tiến trình). Khoá theo user id của dòng test nên
 * dọn sạch trong finally.
 */
function vvStaffLoginRacePrefix(): string
{
    static $prefix = null;

    return $prefix ??= 'racetest-'.bin2hex(random_bytes(4)).'-';
}

function vvStaffLoginRaceWorker(array $args, ?string $prefix = null): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/login_race_worker.php'), 'staff-'.array_shift($args), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'CACHE_LIMITER' => 'redis-limiter',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'BCRYPT_ROUNDS' => '4',
        // Prefix riêng: khoá limiter của race test không trùng khoá của DB dev (id user trùng nhau).
        'CACHE_PREFIX' => $prefix ?? vvStaffLoginRacePrefix(),
    ]);
    $p->setTimeout(180);

    return $p;
}

function vvStaffLoginRaceOnce(array $args): array
{
    $p = vvStaffLoginRaceWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

test('Staff (that, song song): 15 tien trinh dang nhap quan tri sai vao 1 tai khoan -> toi da 10 lan so mat khau, con lai 429', function () {
    $ids = vvStaffLoginRaceOnce(['setup']);
    $ip = '10.77.'.random_int(1, 250).'.'.random_int(1, 250);

    try {
        $startAt = microtime(true) + 4.0;
        $procs = [];
        foreach (range(1, 15) as $i) {
            $procs[] = vvStaffLoginRaceWorker(['login', $ids['email'], $ip, $startAt]);
        }
        foreach ($procs as $p) {
            $p->start();
        }
        $results = [];
        foreach ($procs as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
            $results[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        $counts = array_count_values(array_column($results, 'result'));
        $checks = array_sum(array_column($results, 'checks'));

        expect($counts)->not->toHaveKey('ok')
            ->and(collect($counts)->keys()->diff(['wrong', 'throttled'])->all())->toBe([], json_encode($results))
            ->and($counts['wrong'] ?? 0)->toBe(10)
            ->and($counts['throttled'] ?? 0)->toBe(5)
            ->and($checks)->toBeLessThanOrEqual(10);
    } finally {
        vvStaffLoginRaceOnce(['cleanup', $ids['id'], $ip]);
    }
})->group('race');
