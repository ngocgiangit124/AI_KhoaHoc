<?php

/** Tiến trình con cho race test T15 (QA). Chỉ chạy trên DB `*_testing`. In 1 dòng JSON. */

use App\Models\User;
use App\Services\Coupons\CouponService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

$mode = $argv[1];
$args = array_slice($argv, 2);

$out = match ($mode) {
    'setup' => ['admin' => User::factory()->admin()->create()->id],
    // Không xoá audit_logs: trigger L2 chặn DELETE dòng mới và DB test riêng nên dòng audit không ảnh hưởng assert.
    'cleanup' => (function () use ($args) {
        DB::table('coupons')->where('code', $args[1])->delete();
        DB::table('users')->where('id', $args[0])->delete();

        return ['ok' => true];
    })(),
    'count' => ['rows' => DB::table('coupons')->where('code', $args[0])->count()],
    'create' => (function () use ($args) {
        [$adminId, $code, $startAt] = $args;
        $admin = User::findOrFail($adminId);
        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        try {
            app(CouponService::class)->create($admin, [
                'code' => $code, 'name' => 'Race', 'discount_type' => 'percent', 'discount_value' => 10,
                'max_uses' => null, 'valid_from' => null, 'valid_until' => null, 'course_ids' => [], 'subject_ids' => [],
            ]);

            return ['result' => 'ok'];
        } catch (ValidationException $e) {
            return ['result' => 'validation', 'fields' => array_keys($e->errors())];
        } catch (Throwable $e) {
            return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)];
        }
    })(),
};

echo json_encode($out);
