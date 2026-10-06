<?php

/** Tiến trình con cho race test T33 (1 kết nối MySQL/tiến trình). Chỉ chạy trên DB `*_testing`. */

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Staff\StaffAccountService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

$mode = $argv[1];
$args = array_slice($argv, 2);
$wait = function (string $t): void {
    while (microtime(true) < (float) $t) {
        usleep(200);
    }
};
$run = function (callable $fn): array {
    try {
        $fn();

        return ['result' => 'ok'];
    } catch (DomainException $e) {
        return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)];
    }
};

$out = match ($mode) {
    'setup' => (function () use ($args) {
        $ids = [];
        foreach (range(1, (int) $args[0]) as $i) {
            $ids[] = User::factory()->create(['email' => 'race-staff-'.uniqid().'@example.test', 'role' => UserRole::Admin, 'status' => UserStatus::Active])->id;
        }
        $others = User::query()->where('role', UserRole::Admin->value)->where('status', UserStatus::Active->value)->whereNotIn('id', $ids)->count();

        return ['ids' => $ids, 'others' => $others];
    })(),
    'lock' => (function () use ($args, $wait, $run) {
        [$actor, $target, $t] = $args;
        $wait($t);

        return $run(fn () => app(StaffAccountService::class)->lock(User::findOrFail($target), User::findOrFail($actor)));
    })(),
    'demote' => (function () use ($args, $wait, $run) {
        [$actor, $target, $t] = $args;
        $wait($t);

        return $run(fn () => app(StaffAccountService::class)->changeRole(User::findOrFail($target), UserRole::Teacher, User::findOrFail($actor)));
    })(),
    'state' => (function () use ($args) {
        return ['active' => User::query()->whereIn('id', $args)->where('role', UserRole::Admin->value)->where('status', UserStatus::Active->value)->count()];
    })(),
    // Không xoá audit_logs: trigger L2 chặn DELETE dòng mới và DB test riêng nên dòng audit không ảnh hưởng assert.
    'cleanup' => (function () use ($args) {
        DB::table('users')->whereIn('id', $args)->delete();

        return ['ok' => true];
    })(),
    default => ['error' => 'mode?'],
};

echo json_encode($out);
