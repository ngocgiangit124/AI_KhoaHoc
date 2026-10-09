<?php

/** QA BE-backlog-1: dựng/dọn dữ liệu cho test worker queue THẬT (ParentNoticeMail). Chỉ chạy trên DB `*_testing*`. In 1 dòng JSON.
 *  enqueue <email> | state <jobId_unused> <email> | release | cleanup <userId> */

use App\Models\User;
use App\Services\Privacy\ParentNotifier;
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

$out = match ($mode) {
    'enqueue' => (function () use ($argv) {
        config(['features.parent_notices' => true, 'privacy.parent_notice_global_hourly_cap' => 500]);
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        $u = User::factory()->student()->verified()->create(['parent_email' => $argv[2]]);
        app(ParentNotifier::class)->accountCreated($u->fresh());

        return ['user' => $u->id, 'jobs' => DB::table('jobs')->count()];
    })(),
    // Đẩy hạn thử lại về "bây giờ" để vòng sau chạy ngay, trả số lần thử đã ghi nhận.
    'release' => (function () {
        DB::table('jobs')->update(['available_at' => time() - 1]);
        $j = DB::table('jobs')->first();

        return ['jobs' => DB::table('jobs')->count(), 'attempts' => $j->attempts ?? null, 'failed' => DB::table('failed_jobs')->count()];
    })(),
    'state' => (function () {
        $f = DB::table('failed_jobs')->first();

        return ['jobs' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count(), 'exception' => $f->exception ?? null, 'payload' => $f->payload ?? null];
    })(),
    'cleanup' => (function () use ($argv) {
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('users')->where('id', (int) $argv[2])->delete();

        return ['ok' => true];
    })(),
};

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
