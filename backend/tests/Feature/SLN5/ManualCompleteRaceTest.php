<?php

require_once __DIR__.'/../T13/ConcurrentProgressTest.php';

test('race: 8 /complete dong thoi cung hoc sinh+bai link ngoai -> 1 dong, completed_at khong doi, khong 500/deadlock', function () {
    $s = vvProgOnce(['setup']);

    try {
        vvProgOnce(['make_external', $s['l1']]);
        $startAt = microtime(true) + 3.0;
        $results = vvProgParallel(array_map(fn () => ['complete', $s['student'], $s['l1'], $startAt], range(0, 7)));

        foreach ($results as $r) {
            expect($r['result'])->toBe('ok', json_encode($r))->and($r['completed'])->toBeTrue();
        }

        $st = vvProgOnce(['completed_state', $s['student'], $s['l1']]);
        expect($st['rows'])->toBe(1)->and($st['status'])->toBe('completed')->and($st['completed_at'])->not->toBeNull();
    } finally {
        vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
    }
})->group('race');

test('race: /complete >< heartbeat cung bai -> khong 500/deadlock, ket qua cuoi completed', function () {
    $s = vvProgOnce(['setup']);

    try {
        vvProgOnce(['make_external', $s['l1']]);
        $startAt = microtime(true) + 3.0;
        $sets = [];
        foreach (range(0, 3) as $i) {
            $sets[] = ['complete', $s['student'], $s['l1'], $startAt];
            $sets[] = ['heartbeat', $s['student'], $s['l1'], 10 + $i, 30, $startAt];
        }
        foreach (vvProgParallel($sets) as $r) {
            expect($r['result'])->toBe('ok', json_encode($r));
        }

        $st = vvProgOnce(['completed_state', $s['student'], $s['l1']]);
        expect($st['rows'])->toBe(1)->and($st['status'])->toBe('completed');
    } finally {
        vvProgOnce(['cleanup', $s['student'], $s['course'], $s['creator']]);
    }
})->group('race');
