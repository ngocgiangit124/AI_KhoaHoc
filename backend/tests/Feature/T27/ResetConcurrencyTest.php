<?php

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * AC7 (US-015): N tiến trình PHP thật (MySQL thật) cùng đặt lại mật khẩu bằng CÙNG 1 mã OTP.
 * Bất biến: đúng 1 tiến trình thành công; mã chỉ bị tiêu thụ 1 lần; mật khẩu cuối là của người thắng;
 * đúng 1 audit `account.password_reset`; đúng 1 tombstone `password_changed` cho phiên đang sống.
 * RefreshDatabase bọc test trong transaction nên test COMMIT dữ liệu của riêng nó rồi tự dọn (chỉ xoá
 * đúng user của test, không đụng dữ liệu khác trong DB test dùng chung).
 */

function vvRaceAssertTestingDatabase(): void
{
    expect(DB::connection()->getDatabaseName())->toStartWith('vitaminvui_testing');
}

beforeEach(function () {
    vvRaceAssertTestingDatabase();
    $this->raceDir = sys_get_temp_dir().'/vv-reset-'.Str::random(8);
    mkdir($this->raceDir.'/sessions', 0777, true);
    mkdir($this->raceDir.'/cache', 0777, true);
    $this->raceUserId = null;
});

afterEach(function () {
    vvRaceAssertTestingDatabase();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    if ($this->raceUserId !== null) {
        // Không xoá audit_logs: trigger L2 chặn DELETE dòng mới (DB test riêng, không ảnh hưởng assert).
        DB::table('otp_codes')->where('user_id', $this->raceUserId)->delete();
        DB::table('users')->where('id', $this->raceUserId)->delete();
    }

    exec('rm -rf '.escapeshellarg($this->raceDir));
});

/** @return array<string, int> */
function vvResetInParallel(string $login, string $code, int $n, string $dir): array
{
    $startAt = microtime(true) + 3.0;
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_HOST' => (string) config('database.connections.mysql.host'),
        'DB_USERNAME' => (string) config('database.connections.mysql.username'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_LIMITER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ]);

    $procs = [];

    foreach (range(1, $n) as $i) {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/worker-reset.php', $login, $code, 'mat-khau-moi-'.$i, (string) $startAt, $dir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        $procs[$i] = [$proc, $pipes];
    }

    $results = [];

    foreach ($procs as $i => [$proc, $pipes]) {
        $results[$i] = trim((string) stream_get_contents($pipes[1])).trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    return $results;
}

test('AC7 8 tien trinh dat lai mat khau song song cung 1 ma -> dung 1 thanh cong, ma tieu thu 1 lan, 1 audit, 1 tombstone', function () {
    $email = 'race-'.Str::random(6).'@example.com';
    $sessionId = Str::random(40);
    $user = User::factory()->create([
        'email' => $email,
        'password' => Hash::make('mat-khau-cu-1'),
        'email_verified_at' => now(),
        'current_session_id' => $sessionId,
    ]);
    $this->raceUserId = $user->id;

    OtpCode::query()->create([
        'user_id' => $user->id, 'purpose' => 'reset_password', 'channel' => 'email', 'destination' => $email,
        'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
    ]);

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $results = vvResetInParallel($email, '123456', 8, $this->raceDir);

    $ok = array_keys(array_filter($results, fn (string $r) => $r === 'ok'));
    expect($ok)->toHaveCount(1, 'ket qua: '.json_encode($results));

    // Request thua nhận lỗi field `code` (mã sai/đã dùng/hết hạn), không phải 500/exception khác.
    $losers = array_values(array_filter($results, fn (string $r) => $r !== 'ok'));
    expect($losers)->toHaveCount(7);
    foreach ($losers as $r) {
        // WRONG = thua ở UPDATE consume; EXPIRED = đọc sau khi mã đã tiêu thụ; TOO_MANY_ATTEMPTS (DomainException 429)
        // = quá 5 request đồng thời cùng tăng `attempts` trước khi so (S9). Cả 3 đều là lỗi hợp lệ, không phải 500.
        expect(str_starts_with($r, 'fail:') || str_contains($r, 'DomainException'))->toBeTrue($r);
        expect($r)->not->toContain('SQLSTATE');
    }

    $fresh = $user->fresh();
    expect(Hash::check('mat-khau-moi-'.$ok[0], $fresh->password))->toBeTrue()
        ->and(Hash::check('mat-khau-cu-1', $fresh->password))->toBeFalse();

    expect(OtpCode::where('user_id', $user->id)->whereNotNull('consumed_at')->count())->toBe(1)
        ->and(AuditLog::where('action', 'account.password_reset')->where('subject_id', $user->id)->count())->toBe(1);

    // Phiên cũ bị huỷ đúng 1 lần: tombstone password_changed (file trong store cache của tiến trình con).
    $tombstones = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->raceDir.'/cache', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_contains((string) file_get_contents($f->getPathname()), 'password_changed')) {
            $tombstones[] = $f->getPathname();
        }
    }
    expect($tombstones)->toHaveCount(1);
});

test('AC7 + AC3 5 tien trinh song song cung 1 ma SAI -> khong ai doi duoc mat khau, tong so luot khong vuot tran', function () {
    $email = 'race-'.Str::random(6).'@example.com';
    $user = User::factory()->create(['email' => $email, 'password' => Hash::make('mat-khau-cu-1'), 'email_verified_at' => now()]);
    $this->raceUserId = $user->id;

    OtpCode::query()->create([
        'user_id' => $user->id, 'purpose' => 'reset_password', 'channel' => 'email', 'destination' => $email,
        'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
    ]);

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $results = vvResetInParallel($email, '000000', 5, $this->raceDir);

    expect(array_filter($results, fn (string $r) => $r === 'ok'))->toBeEmpty('ket qua: '.json_encode($results));
    expect(Hash::check('mat-khau-cu-1', $user->fresh()->password))->toBeTrue()
        ->and((int) OtpCode::where('user_id', $user->id)->value('attempts'))->toBe(5);
});
