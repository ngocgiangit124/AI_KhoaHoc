<?php

use Symfony\Component\Process\Process;

/**
 * Race thật nhiều tiến trình (mỗi tiến trình một kết nối MySQL) cho T39. Chạy: `pest --group=race`.
 * Dữ liệu commit thật nên dọn trong finally (audit_logs bất biến nên các dòng audit ở lại, test đếm theo subject_id).
 */
function vvT39Worker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/t39_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    ]);
    $p->setTimeout(180);

    return $p;
}

/** @return array<string, mixed> */
function vvT39Once(array $args): array
{
    $p = vvT39Worker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvT39Parallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvT39Worker($a), $sets);
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

function vvT39State(array $s): array
{
    return vvT39Once(['state', $s['order'], $s['course'], $s['coupon'] ?? '-', $s['student'], $s['jobs']]);
}

function vvT39Clean(array $s): void
{
    vvT39Once(['cleanup', $s['student'], implode(',', $s['staff']), $s['order'], $s['course'].','.$s['extra_course'], $s['coupon'] ?? '-', $s['creator'], $s['jobs']]);
}

/** @param  list<array<string, mixed>>  $res */
function vvT39NoErrors(array $res): void
{
    expect(collect($res)->whereNotIn('result', ['ok', 'domain'])->values()->all())->toBe([], json_encode($res));
}

/** Đơn `paid` nhất quán: đúng 1 quyền học, 1 lượt mã, 1 thư (HS + phụ huynh), 1 audit duyệt, không có log huỷ. */
function vvT39ExpectPaidOnce(array $st, bool $coupon = true): void
{
    expect($st['status'])->toBe('paid')
        ->and($st['enroll_active'])->toBe(1)->and($st['enroll_total'])->toBe(1)->and($st['enroll_count'])->toBe(1)
        ->and($st['usages'])->toBe($coupon ? 1 : 0)->and($st['used_count'])->toBe($coupon ? 1 : 0)
        ->and($st['paid_logs'])->toBe(1)->and($st['audit_approve'])->toBe(1)
        ->and($st['mail_paid'])->toBe(1)->and($st['mail_parent'])->toBe(1)
        ->and($st['confirmed_by'])->not->toBeNull();
}

/** Đơn chưa thanh toán nhất quán: không quyền học, không lượt mã, không thư duyệt, không audit duyệt. */
function vvT39ExpectNotPaid(array $st): void
{
    expect($st['status'])->not->toBe('paid')
        ->and($st['enroll_total'])->toBe(0)->and($st['enroll_count'])->toBe(0)->and($st['usages'])->toBe(0)->and($st['used_count'])->toBe(0)
        ->and($st['paid_logs'])->toBe(0)->and($st['audit_approve'])->toBe(0)
        ->and($st['mail_paid'])->toBe(0)->and($st['mail_parent'])->toBe(0);
}

test('race h1: 2-4 admin cung duyet mot don -> dung 1 thanh cong, con lai 409 ALREADY_PROCESSED; enrollment, luot ma, thu, audit deu dung 1', function (int $workers) {
    $s = vvT39Once(['setup', 'pending', 1]);

    try {
        $t = microtime(true) + 3.0;
        $res = vvT39Parallel(array_map(fn ($i) => ['approve', $s['order'], $s['staff'][$i % 2], 0, $t], range(0, $workers - 1)));

        vvT39NoErrors($res);
        expect(collect($res)->where('result', 'ok'))->toHaveCount(1)
            ->and(collect($res)->where('result', 'domain')->pluck('code')->unique()->all())->toBe(['ALREADY_PROCESSED'])
            ->and(collect($res)->where('result', 'domain')->pluck('status')->unique()->all())->toBe([409]);
        vvT39ExpectPaidOnce(vvT39State($s));
    } finally {
        vvT39Clean($s);
    }
})->with([2, 4])->group('race');

test('race h2: duyet <-> hoc sinh tu huy -> dung 1 ben thang, ben kia 409; khong bao gio paid ma log cuoi la cancelled', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT39Once(['setup', 'pending', 1]);

        try {
            $t = microtime(true) + 3.0;
            $res = vvT39Parallel([['approve', $s['order'], $s['staff'][0], 0, $t], ['cancel_student', $s['student'], $s['order'], $t]]);

            vvT39NoErrors($res);
            expect(collect($res)->where('result', 'ok'))->toHaveCount(1, json_encode($res));
            $loser = collect($res)->firstWhere('result', 'domain');
            expect($loser['status'])->toBe(409)->and($loser['code'])->toBeIn(['ORDER_STATUS_CHANGED', 'ALREADY_PROCESSED']);

            $st = vvT39State($s);
            if ($res[0]['result'] === 'ok') {
                vvT39ExpectPaidOnce($st);
                expect($st['cancel_logs'])->toBe(0);
            } else {
                vvT39ExpectNotPaid($st);
                expect($st['status'])->toBe('cancelled')->and($st['reason'])->toBe('user_cancelled')->and($st['cancel_logs'])->toBe(1);
            }
        } finally {
            vvT39Clean($s);
        }
    }
})->group('race');

test('race h3: duyet <-> orders:expire-manual (don qua han) -> dung 1 ben thang, trang thai va thu nhat quan', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT39Once(['setup', 'expired', 1]);

        try {
            $t = microtime(true) + 3.0;
            $res = vvT39Parallel([['approve', $s['order'], $s['staff'][0], 0, $t], ['expire', $t]]);

            vvT39NoErrors($res);
            expect($res[1]['result'])->toBe('ok');
            $st = vvT39State($s);

            if ($res[0]['result'] === 'ok') {
                vvT39ExpectPaidOnce($st);
                expect($st['cancel_logs'])->toBe(0)->and($st['mail_cancelled'])->toBe(0);
            } else {
                expect($res[0]['code'])->toBe('ORDER_STATUS_CHANGED');
                vvT39ExpectNotPaid($st);
                expect($st['status'])->toBe('cancelled')->and($st['reason'])->toBe('expired')->and($st['cancel_logs'])->toBe(1)->and($st['mail_cancelled'])->toBe(1);
            }
        } finally {
            vvT39Clean($s);
        }
    }
})->group('race');

test('race h4: duyet <-> admin huy -> dung 1 ben thang, ben kia 409; thu/audit nhat quan', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT39Once(['setup', 'pending', 1]);

        try {
            $t = microtime(true) + 3.0;
            $res = vvT39Parallel([['approve', $s['order'], $s['staff'][0], 0, $t], ['cancel_staff', $s['order'], $s['staff'][1], $t]]);

            vvT39NoErrors($res);
            expect(collect($res)->where('result', 'ok'))->toHaveCount(1, json_encode($res));
            expect(collect($res)->firstWhere('result', 'domain')['code'])->toBe('ORDER_STATUS_CHANGED');
            $st = vvT39State($s);

            if ($res[0]['result'] === 'ok') {
                vvT39ExpectPaidOnce($st);
                expect($st['cancel_logs'])->toBe(0)->and($st['audit_cancel'])->toBe(0)->and($st['mail_cancelled'])->toBe(0);
            } else {
                vvT39ExpectNotPaid($st);
                expect($st['status'])->toBe('cancelled')->and($st['reason'])->toBe('admin_cancelled')->and($st['cancel_logs'])->toBe(1)
                    ->and($st['audit_cancel'])->toBe(1)->and($st['mail_cancelled'])->toBe(1);
            }
        } finally {
            vvT39Clean($s);
        }
    }
})->group('race');

test('race h5: duyet <-> checkout replace_pending (thay dung don do) -> hoac don paid va checkout bi CHECKOUT_CHANGED / tao don moi khong chua khoa da mua, hoac don superseded va duyet nhan 409', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT39Once(['setup', 'pending', 0]);

        try {
            $t = microtime(true) + 3.0;
            $res = vvT39Parallel([['approve', $s['order'], $s['staff'][0], 0, $t], ['checkout_replace', $s['student'], $s['extra_course'], 200000, $t]]);

            vvT39NoErrors($res);
            $st = vvT39State($s);

            if ($res[0]['result'] === 'ok') {
                vvT39ExpectPaidOnce($st, false);
                // checkout hoặc bị từ chối vì giỏ đã đổi, hoặc tạo đơn mới KHÔNG chứa khóa vừa mua
                if ($res[1]['result'] === 'ok') {
                    expect($st['new_order_has_course'])->toBe(0);
                } else {
                    expect($res[1]['code'])->toBeIn(['CHECKOUT_CHANGED', 'CART_EMPTY', 'PENDING_ORDER_EXISTS']);
                }
            } else {
                expect($res[0]['code'])->toBe('ORDER_STATUS_CHANGED')->and($res[1]['result'])->toBe('ok');
                vvT39ExpectNotPaid($st);
                expect($st['status'])->toBe('cancelled')->and($st['reason'])->toBe('superseded')->and($st['cancel_logs'])->toBe(1);
            }
        } finally {
            vvT39Clean($s);
        }
    }
})->group('race');

test('race h6: duyet muon <-> xoa khoa trong don -> hoac duyet xong va xoa nhan 409 COURSE_HAS_ENROLLMENTS, hoac xoa xong va duyet nhan 409 COURSE_UNAVAILABLE (khong deadlock treo)', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT39Once(['setup', 'cancelled', 0]);

        try {
            $t = microtime(true) + 3.0;
            $res = vvT39Parallel([['approve', $s['order'], $s['staff'][0], 1, $t], ['delete_course', $s['course'], $t]]);

            vvT39NoErrors($res);
            $st = vvT39State($s);

            if ($res[0]['result'] === 'ok') {
                expect($res[1]['result'])->toBe('domain')->and($res[1]['code'])->toBe('COURSE_HAS_ENROLLMENTS');
                vvT39ExpectPaidOnce($st, false);
                expect($st['course_deleted'])->toBe(0);
            } else {
                expect($res[0]['code'])->toBe('COURSE_UNAVAILABLE')->and($res[1]['result'])->toBe('ok');
                vvT39ExpectNotPaid($st);
                expect($st['status'])->toBe('cancelled')->and($st['course_deleted'])->toBe(1);
            }
        } finally {
            vvT39Clean($s);
        }
    }
})->group('race');

test('race h7: hoan tien <-> duyet cung don -> khong deadlock; hoac paid (hoan tien 409), hoac refunded (duyet roi hoan) voi quyen hoc thu hoi dung 1 lan', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT39Once(['setup', 'pending', 1]);

        try {
            $t = microtime(true) + 3.0;
            $res = vvT39Parallel([['approve', $s['order'], $s['staff'][0], 0, $t], ['refund', $s['order'], $s['staff'][1], $t]]);

            vvT39NoErrors($res);
            expect($res[0]['result'])->toBe('ok'); // duyệt luôn thành công (hoàn tiền chỉ chạy được sau khi đã paid)
            $st = vvT39State($s);

            if ($res[1]['result'] === 'ok') {
                expect($st['status'])->toBe('refunded')->and($st['refund_logs'])->toBe(1)->and($st['enroll_active'])->toBe(0)->and($st['enroll_count'])->toBe(0)
                    ->and($st['paid_logs'])->toBe(1)->and($st['audit_approve'])->toBe(1)->and($st['mail_paid'])->toBe(1);
            } else {
                expect($res[1]['code'])->toBe('ORDER_STATUS_CHANGED');
                vvT39ExpectPaidOnce($st);
                expect($st['refund_logs'])->toBe(0);
            }
        } finally {
            vvT39Clean($s);
        }
    }
})->group('race');
