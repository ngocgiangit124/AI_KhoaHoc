<?php

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * S9 — verify song song. RefreshDatabase bọc test trong transaction nên tiến trình con không thấy
 * dữ liệu; test này COMMIT dữ liệu của chính nó rồi tự dọn (afterEach).
 */

/** R9: chỉ được ghi/xoá thật khi đang ở đúng DB test. */
function vvAssertTestingDatabase(): void
{
    expect(DB::connection()->getDatabaseName())->toStartWith('vitaminvui_testing');
}

function vvWipeCommittedData(): void
{
    vvAssertTestingDatabase();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    DB::table('otp_codes')->delete();
    DB::table('consents')->delete();
    DB::table('users')->delete();
}

beforeEach(function () {
    vvAssertTestingDatabase();
    // Dọn cả trước test: lần chạy trước bị ngắt giữa chừng không được để lại dữ liệu đã commit.
    $level = DB::transactionLevel();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    vvWipeCommittedData();
    for ($i = 0; $i < $level; $i++) {
        DB::beginTransaction();
    }
});

afterEach(function () {
    vvWipeCommittedData();
});

/**
 * Bắn $count tiến trình PHP riêng, mỗi tiến trình gọi OtpService::verifyAccount($code) cùng lúc.
 *
 * @param  list<string>  $codes  mã cho từng tiến trình (độ dài = $count)
 * @return array<string, int> thống kê kết quả
 */
function vvVerifyInParallel(User $user, array $codes): array
{
    $startAt = microtime(true) + 3.0;
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_HOST' => (string) config('database.connections.mysql.host'),
        'DB_USERNAME' => (string) config('database.connections.mysql.username'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array',
        'CACHE_LIMITER' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ]);

    $procs = [];

    foreach ($codes as $i => $code) {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/worker-verify.php', (string) $user->id, $code, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        $procs[$i] = [$proc, $pipes];
    }

    $results = [];

    foreach ($procs as [$proc, $pipes]) {
        $results[] = trim((string) stream_get_contents($pipes[1])).trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    return array_count_values($results);
}

function vvCommittedOtpUser(string $code): User
{
    $user = User::factory()->create();
    OtpCode::factory()->withCode($code)->create([
        'user_id' => $user->id,
        'destination' => $user->email,
        'purpose' => OtpPurpose::VerifyAccount,
    ]);

    // Đưa dữ liệu ra khỏi transaction của RefreshDatabase để tiến trình con nhìn thấy.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    return $user;
}

test('S9 20 verify song song voi ma SAI -> toi da 5 lan duoc so, 15 con lai bi chan', function () {
    $user = vvCommittedOtpUser('123456');

    $stats = vvVerifyInParallel($user, array_fill(0, 20, '000000'));

    expect($stats)->not->toHaveKey('ok')
        ->and($stats['wrong'] ?? 0)->toBe(5)
        ->and($stats['locked'] ?? 0)->toBe(15)
        ->and(array_sum($stats))->toBe(20);

    $otp = OtpCode::firstOrFail();
    expect($otp->attempts)->toBe(5)->and($otp->consumed_at)->toBeNull();
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('S9 20 verify song song voi ma DUNG -> dung 1 request consume thanh cong', function () {
    $user = vvCommittedOtpUser('123456');

    $stats = vvVerifyInParallel($user, array_fill(0, 20, '123456'));

    // Chỉ 5 request đầu được tăng attempts; trong đó đúng 1 consume thành công (UPDATE có điều kiện).
    expect($stats['ok'] ?? 0)->toBe(1)
        ->and(array_sum($stats))->toBe(20);
    expect($stats)->not->toHaveKeys(['error']);

    $otp = OtpCode::firstOrFail();
    expect($otp->consumed_at)->not->toBeNull()->and($otp->attempts)->toBeLessThanOrEqual(5);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('S9 ket hop: 19 ma sai + 1 ma dung song song -> khong qua 5 lan so, tong ket nhat quan', function () {
    $user = vvCommittedOtpUser('123456');
    $codes = array_fill(0, 19, '000000');
    $codes[] = '123456';
    shuffle($codes);

    $stats = vvVerifyInParallel($user, $codes);

    $compared = ($stats['ok'] ?? 0) + ($stats['wrong'] ?? 0);
    expect($compared)->toBeLessThanOrEqual(5)->and(array_sum($stats))->toBe(20);
    expect(OtpCode::firstOrFail()->attempts)->toBeLessThanOrEqual(5);
});
