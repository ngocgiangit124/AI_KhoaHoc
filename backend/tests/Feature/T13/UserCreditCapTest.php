<?php

use App\Support\AtomicCounter;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/helpers.php';

function vvCreditSum($student, ...$lessons): int
{
    return (int) collect($lessons)->sum(fn ($l) => (int) (vvProgressRow($student, $l)->watched_seconds ?? 0));
}

test('R11: tran mac dinh = max_speed*(cua so + nhip heartbeat) + slack (165/60s), lay tu config', function () {
    $cfg = config('learning.heartbeat');
    expect($cfg['user_credit_cap_seconds'])->toBeNull()
        ->and($cfg['max_speed'] * ($cfg['user_credit_window_seconds'] + $cfg['first_interval_seconds']) + $cfg['slack_seconds'])->toBe(165);
});

test('R6: xem MOT bai o toc do 2x trong 60 giay duoc cong du (khong bi cat oan)', function () {
    $s = vvLearnSet(duration: 900);
    $this->freezeTime();

    // 2x: mỗi 20s thật xem 40s video, heartbeat báo delta 40. Bài 1 lần đầu coi như 20s (cap 45) rồi 2*20+5 = 45.
    vvHeartbeat($s['lesson'], 40, 40)->assertOk();
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 80, 40)->assertOk();
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 120, 40)->assertOk();

    expect(vvCreditSum($s['student'], $s['lesson']))->toBe(120);
});

test('R6/L1: phat song song 3 bai thi tong giay duoc cong <= tran 165 trong cua so 60s, van 200', function () {
    $s = vvLearnSet(duration: 900);
    $l2 = vvAddLesson($s['course'], $s['chapter'], 2, ['video_source' => 'none', 'duration_seconds' => 900]);
    $l3 = vvAddLesson($s['course'], $s['chapter'], 3, ['video_source' => 'none', 'duration_seconds' => 900]);
    $this->freezeTime();

    foreach ([0, 1, 2] as $round) {
        if ($round > 0) {
            $this->travel(20)->seconds();
        }
        foreach ([$s['lesson'], $l2, $l3] as $i => $lesson) {
            vvHeartbeat($lesson, 100 + $round * 40, 40)->assertOk();
        }
    }

    expect(vvCreditSum($s['student'], $s['lesson'], $l2, $l3))->toBeLessThanOrEqual(165)->and(vvCreditSum($s['student'], $s['lesson'], $l2, $l3))->toBeGreaterThan(100);
});

test('R10: hai tab cung mot bai (cung giay) khong cong gap doi; sau cua so 60s duoc cong tiep', function () {
    $s = vvLearnSet(duration: 900);
    $this->freezeTime();

    vvHeartbeat($s['lesson'], 20, 20)->assertOk();
    vvHeartbeat($s['lesson'], 20, 20)->assertOk(); // tab 2 gui trung vi tri cung luc
    expect(vvCreditSum($s['student'], $s['lesson']))->toBe(20);

    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 40, 20)->assertOk();
    vvHeartbeat($s['lesson'], 41, 20)->assertOk(); // tab 2 lech 1s, cung giay: cap theo bai = 0 nho cua so replay/elapsed 0
    expect(vvCreditSum($s['student'], $s['lesson']))->toBeLessThanOrEqual(45);

    $this->travel(61)->seconds();
    vvHeartbeat($s['lesson'], 70, 25)->assertOk();
    expect(vvCreditSum($s['student'], $s['lesson']))->toBeGreaterThan(40);
});

test('R10: heartbeat don lai do mang cham (delta toi da sau nhieu giay) bi chan boi tran, khong qua tran trong cua so', function () {
    $s = vvLearnSet(duration: 3000);
    $this->freezeTime();

    vvHeartbeat($s['lesson'], 20, 20)->assertOk();
    $this->travel(300)->seconds(); // mất mạng 5 phút, player gom 300s vào một heartbeat
    vvHeartbeat($s['lesson'], 320, 60)->assertOk();

    expect(vvCreditSum($s['student'], $s['lesson']))->toBeLessThanOrEqual(20 + 165);
});

test('R8: AtomicCounter::add am khi khoa het han khong tao khoa vinh vien (luon co TTL hoac bien mat)', function () {
    $key = 'hb-credit:test-r8';
    Cache::store(config('cache.limiter'))->forget($key);

    expect(AtomicCounter::add($key, 10, 60))->toBe(10);
    $this->travel(61)->seconds(); // het cua so
    $after = AtomicCounter::add($key, -4, 60);

    // Store array: khoa moi duoc tao voi TTL nen het han sau cua so; khong bao gio ton tai vinh vien.
    expect($after)->toBeLessThanOrEqual(0);
    $this->travel(61)->seconds();
    expect(AtomicCounter::attempts($key))->toBe(0);
});

test('R8 (Redis that): add am len khoa chua ton tai/het han van dat TTL, khong tao khoa vinh vien', function () {
    config(['cache.limiter' => 'redis-limiter']);
    $store = Cache::store('redis-limiter')->getStore();
    $key = 'hb-credit:test-r8-'.bin2hex(random_bytes(4));
    $full = $store->getPrefix().$key;

    try {
        expect(AtomicCounter::add($key, -4, 60))->toBe(-4);
        $ttl = $store->connection()->ttl($full);
        expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

        // Cộng tiếp khi khoá còn TTL không làm mới TTL.
        $store->connection()->expire($full, 30);
        AtomicCounter::add($key, 4, 60);
        expect($store->connection()->ttl($full))->toBeLessThanOrEqual(30);
    } finally {
        $store->connection()->del($full);
    }
});

test('R11: xem 2x keo dai 8 heartbeat, moi nhip lech +-1 giay quanh moc 20s/60s -> duoc cong >= 95% so giay da xem', function (array $jitter) {
    $s = vvLearnSet(duration: 900);
    $this->freezeTime();

    $watched = 0;
    foreach ($jitter as $i => $j) {
        if ($i > 0) {
            $this->travel(20 + $j)->seconds();
        }
        // 2x: ~20s that = 40s video; heartbeat báo đúng phần đã xem.
        vvHeartbeat($s['lesson'], 40 * ($i + 1), 40)->assertOk();
        $watched += 40;
    }

    expect(vvCreditSum($s['student'], $s['lesson']))->toBeGreaterThanOrEqual((int) ceil($watched * 0.95));
})->with([
    'deu' => [[0, 0, 0, 0, 0, 0, 0, 0]],
    'som' => [[0, -1, -1, -1, -1, -1, -1, -1]],
    'tre' => [[0, 1, 1, 1, 1, 1, 1, 1]],
    'xen ke' => [[0, 1, -1, 1, -1, 1, -1, 1]],
    'dom' => [[0, -1, -1, 1, 1, -1, -1, 1]],
]);
