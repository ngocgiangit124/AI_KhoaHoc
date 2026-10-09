<?php

use App\Models\Coupon;
use App\Services\Orders\CouponCapacity;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * Race thật nhiều tiến trình (mỗi tiến trình một kết nối MySQL) cho T38. Chạy: `pest --group=race`.
 * Dữ liệu commit thật nên dọn trong finally. Thư được đếm qua bảng `jobs` (queue database trong tiến trình con).
 */
function vvMrWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/manual_order_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    ]);
    $p->setTimeout(180);

    return $p;
}

/** @return array<string, mixed> */
function vvMrOnce(array $args): array
{
    $p = vvMrWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvMrParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvMrWorker($a), $sets);
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

function vvMrClean(array $s, array $extraCourses = []): void
{
    vvMrOnce(['cleanup', implode(',', $s['users']), implode(',', [$s['course'], ...$extraCourses]), $s['coupon'] ?? '-', $s['creator'], $s['jobs']]);
}

test('race k1: 6 tiến trình POST /checkout manual cùng giỏ -> đúng 1 đơn, 1 tạo mới + còn lại dùng lại, đúng 2 thư (1 HS + 1 quản trị), không lỗi', function () {
    $s = vvMrOnce(['setup', 1, 0, 0]);

    try {
        $t = microtime(true) + 3.0;
        $res = vvMrParallel(array_fill(0, 6, ['checkout', $s['users'][0], 100000, $t, 0]));

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        expect(collect($res)->where('result', 'ok')->count())->toBe(6)
            ->and(collect($res)->where('reused', false)->count())->toBe(1)
            ->and(collect($res)->where('reused', true)->count())->toBe(5)
            ->and(collect($res)->pluck('order')->unique()->count())->toBe(1);

        $state = vvMrOnce(['state', $s['users'][0], '-', $s['jobs']]);
        expect($state['manual'])->toBe(1)->and($state['pending_manual'])->toBe(1)->and($state['attempts'])->toBe(0)->and($state['jobs'])->toBe(2);
    } finally {
        vvMrClean($s);
    }
})->group('race');

test('race k2: 5 HS cùng mã còn 1 lượt đặt manual song song -> đúng 1 đơn giữ mã, còn lại CHECKOUT_CHANGED; sau +31 phút vẫn giữ chỗ', function () {
    $s = vvMrOnce(['setup', 5, 1, 1]);

    try {
        $t = microtime(true) + 3.0;
        $res = vvMrParallel(array_map(fn ($u) => ['checkout', $u, 90000, $t, 0], $s['users']));

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([]);
        expect(collect($res)->where('result', 'ok'))->toHaveCount(1)
            ->and(collect($res)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['CHECKOUT_CHANGED']);

        $state = vvMrOnce(['state', implode(',', $s['users']), $s['coupon'], $s['jobs']]);
        expect($state['with_coupon'])->toBe(1)->and($state['pending_manual'])->toBe(1);

        Carbon::setTestNow(now()->addMinutes(31));
        try {
            expect(app(CouponCapacity::class)->hasRoom(Coupon::findOrFail($s['coupon'])))->toBeFalse();
        } finally {
            Carbon::setTestNow();
        }
    } finally {
        vvMrClean($s);
    }
})->group('race');

test('race k3: HS tự huỷ ↔ orders:expire-manual cùng đơn -> đúng 1 dòng log chuyển cancelled, tối đa 1 thư', function () {
    foreach (range(1, 4) as $round) {
        $s = vvMrOnce(['setup', 1, 0, 0]);

        try {
            $order = vvMrOnce(['expired_order', $s['users'][0], $s['course']]);
            $t = microtime(true) + 3.0;
            $res = vvMrParallel([['cancel_student', $s['users'][0], $order['order'], $t], ['expire', $t]]);

            expect(collect($res)->where('result', 'error')->values()->all())->toBe([], json_encode($res));
            // student: ok hoặc 409 ALREADY_PROCESSED; expire: ok (cancelled 0|1)
            expect($res[0]['result'] === 'ok' || ($res[0]['result'] === 'domain' && $res[0]['code'] === 'ALREADY_PROCESSED'))->toBeTrue(json_encode($res));

            $state = vvMrOnce(['state', $s['users'][0], '-', $s['jobs']]);
            expect($state['cancel_logs'])->toBe(1)->and($state['jobs'])->toBeLessThanOrEqual(1)->and($state['pending'])->toBe(0);
        } finally {
            vvMrClean($s);
        }
    }
})->group('race');

test('race k4: 6 request nội dung khác nhau + replace_pending song song (đã có 3 đơn hôm nay, hạn mức 5) -> ≤ 2 đơn mới, ≤ 1 đơn pending, không lỗi hệ thống', function () {
    $s = vvMrOnce(['setup', 1, 0, 0]);
    $extra = vvMrOnce(['setup_courses', 6])['courses'];

    try {
        vvMrOnce(['seed_today', $s['users'][0], 3]);
        $before = vvMrOnce(['state', $s['users'][0], '-', $s['jobs']])['manual'];
        $t = microtime(true) + 3.0;
        $res = vvMrParallel(array_map(fn ($i, $c) => ['checkout', $s['users'][0], ($i + 1) * 10000, $t, 1, $c], array_keys($extra), $extra));

        expect(collect($res)->where('result', 'error')->values()->all())->toBe([], json_encode($res));
        $allowed = ['CHECKOUT_CHANGED', 'MANUAL_ORDER_LIMIT'];
        expect(collect($res)->where('result', 'domain')->pluck('code')->diff($allowed)->values()->all())->toBe([], json_encode($res));

        $state = vvMrOnce(['state', $s['users'][0], '-', $s['jobs']]);
        expect($state['manual'] - $before)->toBeLessThanOrEqual(2)->and($state['pending_manual'])->toBeLessThanOrEqual(1)->and($state['manual'])->toBeLessThanOrEqual(5);
    } finally {
        vvMrClean($s, $extra);
    }
})->group('race');

test('race k5: xác nhận xoá tài khoản ↔ checkout manual -> không bao giờ có tài khoản đã ẩn danh kèm đơn manual pending', function () {
    foreach (range(1, 4) as $round) {
        $s = vvMrOnce(['setup', 1, 0, 0]);

        try {
            $t = microtime(true) + 3.0;
            [$delete, $checkout] = vvMrParallel([['delete', $s['users'][0], $t], ['checkout', $s['users'][0], 100000, $t, 0]]);

            expect($delete['result'] === 'ok' || ($delete['result'] === 'domain' && $delete['code'] === 'ACCOUNT_HAS_PENDING_PAYMENT'))->toBeTrue(json_encode([$delete, $checkout]));
            expect($checkout['result'] === 'ok' || ($checkout['result'] === 'domain' && $checkout['code'] === 'SESSION_REVOKED'))->toBeTrue(json_encode([$delete, $checkout]));

            $state = vvMrOnce(['state', $s['users'][0], '-', $s['jobs']]);
            if ($state['anonymized'] === 1) {
                expect($state['pending_manual'])->toBe(0, json_encode([$delete, $checkout, $state]));
            }
            expect($state['anonymized'] === 1)->toBe($delete['result'] === 'ok');
        } finally {
            vvMrClean($s);
        }
    }
})->group('race');
