<?php

use App\Models\User;
use App\Services\Auth\StudentSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * T05-5: lockForUpdate của StudentSessionService::bind() với N tiến trình PHP thật đăng nhập cùng lúc.
 * Bất biến: (1) mọi bind đều thành công; (2) current_session_id là đúng 1 trong N id;
 * (3) trong store chỉ còn đúng phiên hiện hành (N-1 phiên kia bị destroy); (4) có đúng N-1 tombstone
 * (chuỗi old -> new liền mạch, không có "lost update" làm sót một phiên còn sống).
 * RefreshDatabase bọc test trong transaction nên COMMIT dữ liệu của chính test rồi tự dọn.
 */

function vvBindAssertTestingDatabase(): void
{
    expect(DB::connection()->getDatabaseName())->toBe('vitaminvui_testing');
}

function vvBindWipe(): void
{
    vvBindAssertTestingDatabase();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    DB::table('audit_logs')->delete();
    DB::table('consents')->delete();
    DB::table('users')->delete();
}

beforeEach(function () {
    vvBindAssertTestingDatabase();
    $level = DB::transactionLevel();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    vvBindWipe();
    for ($i = 0; $i < $level; $i++) {
        DB::beginTransaction();
    }

    $this->bindDir = sys_get_temp_dir().'/vv-bind-'.Str::random(8);
    mkdir($this->bindDir.'/sessions', 0777, true);
    mkdir($this->bindDir.'/cache', 0777, true);
});

afterEach(function () {
    vvBindWipe();
    exec('rm -rf '.escapeshellarg($this->bindDir));
});

/**
 * @param  list<array{session: string, device: string}>  $logins
 * @return array<string, int>
 */
function vvBindInParallel(User $user, array $logins, string $dir): array
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

    foreach ($logins as $i => $login) {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/worker-bind.php', (string) $user->id, $login['session'], $login['device'], (string) $startAt, $dir],
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

function vvCommittedStudent(): User
{
    $user = User::factory()->create();

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    return $user;
}

test('T05-5 20 tien trinh dang nhap song song -> dung 1 phien hien hanh, 19 phien con lai bi huy + co tombstone', function () {
    $user = vvCommittedStudent();
    $logins = [];

    foreach (range(1, 20) as $_) {
        $logins[] = ['session' => Str::random(40), 'device' => (string) Str::uuid()];
    }

    $stats = vvBindInParallel($user, $logins, $this->bindDir);

    expect($stats)->toBe(['ok' => 20]);

    $ids = array_column($logins, 'session');
    $current = $user->fresh()->current_session_id;
    expect($ids)->toContain($current);

    $alive = array_values(array_filter($ids, fn (string $id) => is_file($this->bindDir.'/sessions/'.$id)));
    expect($alive)->toBe([$current]);

    // Mỗi tombstone là 1 file trong store cache (khoá băm nên đếm file; nội dung có reason=replaced).
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->bindDir.'/cache', FilesystemIterator::SKIP_DOTS)) as $f) {
        $files[] = $f->getPathname();
    }
    $tombstones = array_values(array_filter($files, fn (string $f) => str_contains((string) file_get_contents($f), 'replaced')));
    expect($tombstones)->toHaveCount(19);

    // device_id của người thắng phải khớp đúng id phiên hiện hành (không lẫn cặp session/device).
    $winner = collect($logins)->firstWhere('session', $current);
    expect($user->fresh()->current_device_id)->toBe($winner['device']);
});

test('T05-5 double submit song song cung 1 device_id -> 1 phien hien hanh, khong vong lap', function () {
    $user = vvCommittedStudent();
    $device = (string) Str::uuid();
    $logins = array_map(fn () => ['session' => Str::random(40), 'device' => $device], range(1, 6));

    expect(vvBindInParallel($user, $logins, $this->bindDir))->toBe(['ok' => 6]);

    $ids = array_column($logins, 'session');
    $alive = array_values(array_filter($ids, fn (string $id) => is_file($this->bindDir.'/sessions/'.$id)));
    expect($alive)->toHaveCount(1)->and($alive[0])->toBe($user->fresh()->current_session_id)
        ->and($user->fresh()->current_device_id)->toBe($device);
});
