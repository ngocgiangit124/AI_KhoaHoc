<?php

use Symfony\Component\Process\Process;

/**
 * Race thật nhiều tiến trình (mỗi tiến trình một kết nối MySQL). Chạy: `pest --group=race`.
 * Dữ liệu commit thật nên dọn trong finally. DBA checklist §5.6.
 */
function vvCoWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/checkout_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(90);

    return $p;
}

/** @return array<string, mixed> */
function vvCoOnce(array $args): array
{
    $p = vvCoWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvCoParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvCoWorker($a), $sets);
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

function vvCoClean(array $ids): void
{
    vvCoOnce(['cleanup', implode(',', $ids['users']), $ids['course'], $ids['coupon'] ?? '', $ids['creator']]);
}

test('race 2 tab: 6 tien trinh checkout cung HS cung luc -> dung 1 don pending, 1 attempt, khong loi', function () {
    $ids = vvCoOnce(['setup', 1, 0, 0]);

    try {
        $startAt = microtime(true) + 3.0;
        $res = vvCoParallel(array_fill(0, 6, ['checkout', $ids['users'][0], 100000, $startAt]));

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        expect(collect($res)->pluck('order')->filter()->unique()->count())->toBe(1);
        $state = vvCoOnce(['state', $ids['users'][0], $ids['course']]);
        expect($state['orders'])->toBe(1)->and($state['pending'])->toBe(1)->and($state['attempts'])->toBe(1);
    } finally {
        vvCoClean($ids);
    }
})->group('race');

test('race ma cuoi cung: 6 HS cung checkout voi ma max_uses=1 -> dung 1 don giu cho ma, con lai CHECKOUT_CHANGED', function () {
    $ids = vvCoOnce(['setup', 6, 1, 1]);

    try {
        $startAt = microtime(true) + 3.0;
        $res = vvCoParallel(array_map(fn ($u) => ['checkout', $u, 90000, $startAt], $ids['users']));

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        expect(collect($res)->where('result', 'ok'))->toHaveCount(1)
            ->and(collect($res)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['CHECKOUT_CHANGED']);

        $state = vvCoOnce(['state', implode(',', $ids['users']), $ids['course'], $ids['coupon']]);
        expect($state['pending_with_coupon'])->toBe(1)->and($state['pending'])->toBe(1);
    } finally {
        vvCoClean($ids);
    }
})->group('race');

test('race: xoa khoa song song voi checkout -> khong bao gio co don pending tren khoa da xoa mem, khong 500', function () {
    for ($round = 0; $round < 3; $round++) {
        $ids = vvCoOnce(['setup', 2, 0, 0]);

        try {
            $startAt = microtime(true) + 3.0;
            $res = vvCoParallel([
                ['delete_course', $ids['course'], $startAt],
                ['checkout', $ids['users'][0], 100000, $startAt],
                ['checkout', $ids['users'][1], 100000, $startAt],
                ['delete_course', $ids['course'], $startAt],
            ]);

            expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
            $state = vvCoOnce(['state', implode(',', $ids['users']), $ids['course']]);
            expect($state['course_trashed'] && $state['pending_items_on_course'] > 0)->toBeFalse();
        } finally {
            vvCoClean($ids);
        }
    }
})->group('race');

test('race QA: 2 HS cung ma max_uses=1 checkout + markPaid cua HS thu 3 song song, gio 3 khoa thu tu id dao -> khong deadlock/500, khong vuot max_uses', function () {
    $ids = vvCoOnce(['setup_multi', 3, 1]);
    $courseIds = implode(',', $ids['courses']);
    $ids['course'] = $courseIds;

    try {
        // HS thu 3 (index 2, thu tu xuoi): tao don truoc (giu cho ma), sau do cho markPaid chay cung luc voi checkout cua HS 0 va 1.
        $third = vvCoOnce(['prepare_order', $ids['users'][2], 216000]);
        expect($third['result'])->toBe('ok');

        $startAt = microtime(true) + 3.0;
        $res = vvCoParallel([
            ['mark_paid', $third['order_id'], $startAt],
            ['checkout', $ids['users'][0], 216000, $startAt],
            ['checkout', $ids['users'][1], 216000, $startAt],
        ]);

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        $state = vvCoOnce(['state_multi', implode(',', $ids['users']), $ids['coupon']]);
        expect($state['paid'])->toBe(1)->and($state['enrollments'])->toBe(3)->and($state['usages'])->toBe(1)->and($state['used_count'])->toBe(1)
            ->and($state['pending_hold'])->toBe(0);
    } finally {
        vvCoClean($ids);
    }
})->group('race');

test('race QA: 3 HS nhieu khoa thu tu dao cung checkout roi markPaid song song (deadlock checkout <-> fulfillment) -> khong 500', function () {
    $ids = vvCoOnce(['setup_multi', 4, 10]);
    $ids['course'] = implode(',', $ids['courses']);

    try {
        $orders = [];
        foreach ([0, 1] as $i) {
            $orders[$i] = vvCoOnce(['prepare_order', $ids['users'][$i], 216000])['order_id'];
        }
        $startAt = microtime(true) + 3.0;
        $res = vvCoParallel([
            ['mark_paid', $orders[0], $startAt],
            ['mark_paid', $orders[1], $startAt],
            ['checkout', $ids['users'][2], 216000, $startAt],
            ['checkout', $ids['users'][3], 216000, $startAt],
            ['mark_paid', $orders[0], $startAt],
        ]);

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        $state = vvCoOnce(['state_multi', implode(',', $ids['users']), $ids['coupon']]);
        expect($state['paid'])->toBe(2)->and($state['enrollments'])->toBe(6)->and($state['usages'])->toBe(2)->and($state['used_count'])->toBe(2);
    } finally {
        vvCoClean($ids);
    }
})->group('race');
