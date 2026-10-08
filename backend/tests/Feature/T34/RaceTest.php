<?php

use Symfony\Component\Process\Process;

/**
 * Race thật nhiều tiến trình PHP (mỗi tiến trình một kết nối MySQL; dữ liệu commit thật, dọn trong finally).
 * Chạy riêng: `pest --group=race`. Không xoá audit_logs (trigger bất biến): đếm theo subject_id/actor_id.
 */
function vvT34Worker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/t34_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'CACHE_LIMITER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'BCRYPT_ROUNDS' => '4',
    ]);
    $p->setTimeout(180);

    return $p;
}

/** @return array<string, mixed> */
function vvT34RaceOnce(array $args): array
{
    $p = vvT34Worker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  list<list<string|int|float>>  $sets
 * @return list<array<string, mixed>>
 */
function vvT34RaceParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvT34Worker($a), $sets);
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

function vvT34StartAt(): string
{
    return (string) (microtime(true) + 3.0);
}

test('race: xuat du lieu song song khi da dung 1 lan -> dung 1 thanh cong, khong vuot tran 2/ngay', function () {
    $u = vvT34RaceOnce(['setup_user', 1]);

    try {
        $t = vvT34StartAt();
        $res = vvT34RaceParallel(array_map(fn () => ['export', $u['id'], $t], range(1, 4)));

        $ok = array_values(array_filter($res, fn ($r) => $r['result'] === 'ok'));
        $limited = array_values(array_filter($res, fn ($r) => $r['result'] === 'domain' && $r['code'] === 'DATA_EXPORT_LIMIT'));
        expect($ok)->toHaveCount(1, json_encode($res))
            ->and($limited)->toHaveCount(3, json_encode($res));

        expect(vvT34RaceOnce(['state', $u['id']])['audit_export'])->toBe(2);
    } finally {
        vvT34RaceOnce(['cleanup', $u['id']]);
    }
})->group('race');

test('race: 6 request xuat cung luc tu 0 lan -> dung 2 thanh cong, tong audit = 2', function () {
    $u = vvT34RaceOnce(['setup_user', 0]);

    try {
        $t = vvT34StartAt();
        $res = vvT34RaceParallel(array_map(fn () => ['export', $u['id'], $t], range(1, 6)));

        expect(array_filter($res, fn ($r) => $r['result'] === 'ok'))->toHaveCount(2, json_encode($res));
        foreach ($res as $r) {
            expect($r['result'] === 'ok' || ($r['result'] === 'domain' && $r['code'] === 'DATA_EXPORT_LIMIT'))->toBeTrue(json_encode($r));
        }
        expect(vvT34RaceOnce(['state', $u['id']])['audit_export'])->toBe(2);
    } finally {
        vvT34RaceOnce(['cleanup', $u['id']]);
    }
})->group('race');

test('race: 4 request xac nhan xoa cung ma -> an danh dung 1 lan, 1 audit, consents thu hoi, otp xoa', function () {
    $u = vvT34RaceOnce(['setup_delete']);

    try {
        $t = vvT34StartAt();
        $res = vvT34RaceParallel(array_map(fn () => ['delete', $u['id'], '123456', $t], range(1, 4)));

        expect(array_filter($res, fn ($r) => $r['result'] === 'ok'))->toHaveCount(1, json_encode($res));
        foreach ($res as $r) {
            // Thua: mã đã tiêu thụ / email đã NULL → OTP_EXPIRED|OTP_INVALID (422) hoặc hết lượt (429). Không bao giờ 500/SQL.
            expect($r['result'] === 'ok' || $r['result'] === 'validation' || ($r['result'] === 'domain' && $r['code'] === 'TOO_MANY_ATTEMPTS'))->toBeTrue(json_encode($r));
        }

        $state = vvT34RaceOnce(['state', $u['id']]);
        expect($state['anonymized'])->toBeTrue()->and($state['email'])->toBeNull()
            ->and($state['audit_anonymized'])->toBe(1)
            ->and($state['otp_rows'])->toBe(0)
            ->and($state['consents_live'])->toBe(0)->and($state['consents_with_ip'])->toBe(0)
            ->and($state['session'])->toBe('logged_out');
    } finally {
        vvT34RaceOnce(['cleanup', $u['id']]);
    }
})->group('race');

test('race: xoa tai khoan song song voi dang nhap (bind phien) -> phien cuoi luon logged_out, khong 500', function () {
    foreach (range(1, 4) as $round) {
        $u = vvT34RaceOnce(['setup_delete']);

        try {
            $t = vvT34StartAt();
            $res = vvT34RaceParallel([['delete', $u['id'], '123456', $t], ['bind', $u['id'], $t], ['bind', $u['id'], $t]]);

            expect($res[0]['result'])->toBe('ok', json_encode($res));
            foreach ([$res[1], $res[2]] as $r) {
                expect($r['result'] === 'ok' || $r['result'] === 'validation')->toBeTrue(json_encode($r));
            }

            $state = vvT34RaceOnce(['state', $u['id']]);
            expect($state['anonymized'])->toBeTrue()->and($state['session'])->toBe('logged_out');
        } finally {
            vvT34RaceOnce(['cleanup', $u['id']]);
        }
    }
})->group('race');

test('race: xoa tai khoan song song voi checkout -> khong loi he thong; da an danh thi khong con don pending khong link song', function () {
    foreach (range(1, 4) as $round) {
        $u = vvT34RaceOnce(['setup_checkout']);

        try {
            $t = vvT34StartAt();
            $res = vvT34RaceParallel([['delete', $u['id'], '123456', $t], ['checkout', $u['id'], $t]]);
            [$delete, $checkout] = $res;

            // Xoá: thành công, hoặc 409 vì checkout đã kịp tạo link thanh toán sống.
            expect($delete['result'] === 'ok' || ($delete['result'] === 'domain' && $delete['code'] === 'ACCOUNT_HAS_PENDING_PAYMENT'))->toBeTrue(json_encode($res));
            // Checkout: thành công, hoặc bị từ chối vì tài khoản đã xoá. Không bao giờ deadlock/SQL.
            expect($checkout['result'] === 'ok' || ($checkout['result'] === 'domain' && $checkout['code'] === 'SESSION_REVOKED'))->toBeTrue(json_encode($res));

            $state = vvT34RaceOnce(['state', $u['id']]);
            expect($state['anonymized'] === ($delete['result'] === 'ok'))->toBeTrue(json_encode([$res, $state]));

            if ($state['anonymized']) {
                // Mọi đơn còn pending phải có giao dịch cổng đang sống/đang tạo (pha B cố ý giữ, job huỷ 12h dọn); còn lại đã huỷ.
                foreach ($state['orders'] as $o) {
                    if ($o['status'] === 'pending') {
                        expect(array_intersect($o['attempts'], ['created', 'pending']))->not->toBe([], json_encode($state));
                    } else {
                        expect($o['status'])->toBe('cancelled');
                    }
                }
            }
        } finally {
            vvT34RaceOnce(['cleanup', $u['id']]);
            vvT34RaceOnce(['cleanup_course', $u['course'], $u['creator']]);
        }
    }
})->group('race');
