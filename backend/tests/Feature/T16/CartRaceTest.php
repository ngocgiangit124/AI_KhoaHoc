<?php

use Symfony\Component\Process\Process;

/**
 * Race thật bằng nhiều tiến trình (mỗi tiến trình một kết nối MySQL). Chạy: `pest --group=race`.
 * Dữ liệu commit thật nên dọn trong finally.
 */
function vvCartWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/cart_race_worker.php'), ...array_map('strval', $args)], base_path(), [
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

/** @return array<string, mixed> */
function vvCartOnce(array $args): array
{
    $p = vvCartWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

test('race: 8 tien trinh cung them 1 khoa (gio chua ton tai) -> dung 1 thanh cong, 7 ALREADY_IN_CART, 1 gio, 1 dong', function () {
    $ids = vvCartOnce(['setup']);

    try {
        $startAt = microtime(true) + 3.0;
        $procs = array_map(fn () => vvCartWorker(['add', $ids['user'], $ids['course'], $startAt]), range(1, 8));
        foreach ($procs as $p) {
            $p->start();
        }
        $results = [];
        foreach ($procs as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
            $results[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        $kinds = collect($results)->countBy('result')->all();
        expect($kinds['ok'] ?? 0)->toBe(1)
            ->and($kinds['domain'] ?? 0)->toBe(7)
            ->and($kinds)->not->toHaveKey('error')
            ->and(collect($results)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['ALREADY_IN_CART']);

        expect(vvCartOnce(['count', $ids['user']]))->toBe(['rows' => 1, 'carts' => 1]);
    } finally {
        vvCartOnce(['cleanup', $ids['user'], $ids['course'], $ids['creator']]);
    }
})->group('race');

test('race QA: ap ma gioi han song song voi xoa khoa duy nhat trong pham vi -> gio khong bao gio giu ma sai pham vi', function () {
    for ($round = 0; $round < 4; $round++) {
        $ids = vvCartOnce(['setup_scoped']);

        try {
            $startAt = microtime(true) + 3.0;
            $procs = [
                vvCartWorker(['apply', $ids['user'], $ids['code'], $startAt]),
                vvCartWorker(['remove', $ids['user'], $ids['course'], $startAt]),
                vvCartWorker(['apply', $ids['user'], $ids['code'], $startAt]),
            ];
            foreach ($procs as $p) {
                $p->start();
            }
            foreach ($procs as $p) {
                $p->wait();
                expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
                $r = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                expect($r['result'])->not->toBe('error', json_encode($r));
                if ($r['result'] === 'domain') {
                    expect($r['code'])->toBe('COUPON_NOT_APPLICABLE');
                }
            }

            $state = vvCartOnce(['cart_state', $ids['user']]);
            expect($state['items'])->toBe([$ids['other']])->and($state['coupon_id'])->toBeNull();
        } finally {
            vvCartOnce(['cleanup_scoped', $ids['user'], $ids['course'], $ids['other'], $ids['coupon'], $ids['creator']]);
        }
    }
})->group('race');
