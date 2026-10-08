<?php

/** Tiến trình con cho race test T34 (mỗi tiến trình một kết nối MySQL). Chỉ chạy trên DB `*_testing*`. In 1 dòng JSON. */

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Exceptions\OtpValidationException;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Course;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\StudentSessionService;
use App\Services\Orders\CheckoutService;
use App\Services\Privacy\AccountAnonymizer;
use App\Services\Privacy\DataExportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

config(['payments.enabled_gateways' => ['fake'], 'features.paid_checkout' => true]);

$mode = $argv[1];
$args = array_slice($argv, 2);
$wait = function (string $startAt): void {
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
};
$run = function (callable $fn): array {
    try {
        return ['result' => 'ok'] + $fn();
    } catch (DomainException $e) {
        return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
    } catch (ValidationException $e) {
        return ['result' => 'validation', 'keys' => array_keys($e->errors()), 'code' => $e instanceof OtpValidationException ? $e->errorCode : 'VALIDATION_ERROR'];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 300)];
    }
};

$out = match ($mode) {
    // setup_user <preExports>: HS đã xác thực email, mật khẩu `password`, đã có sẵn N lượt xuất hôm nay.
    'setup_user' => (function () use ($args) {
        $u = User::factory()->student()->verified()->create(['email' => 'race34-'.uniqid('', true).'@example.test']);
        foreach (range(1, (int) ($args[0] ?? 0)) as $i) {
            if ((int) $args[0] === 0) {
                break;
            }
            AuditLog::create(['actor_id' => $u->id, 'action' => 'privacy.data_export', 'subject_type' => $u->getMorphClass(), 'subject_id' => $u->id, 'changes' => ['format_version' => 1, 'bytes' => 1]]);
        }

        return ['id' => $u->id, 'email' => $u->email];
    })(),
    // setup_delete: HS có mã OTP delete_account hợp lệ (mã 123456).
    'setup_delete' => (function () {
        $u = User::factory()->student()->verified()->create(['email' => 'race34d-'.uniqid('', true).'@example.test', 'parent_email' => 'ph@example.test']);
        OtpCode::query()->create([
            'user_id' => $u->id, 'purpose' => OtpPurpose::DeleteAccount, 'channel' => 'email', 'destination' => $u->email,
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
        ]);
        DB::table('consents')->insert(['user_id' => $u->id, 'type' => 'terms', 'policy_version' => 'x', 'granted_by' => 'self', 'channel' => 'web_form', 'granted_at' => now(), 'ip' => '1.2.3.4']);

        return ['id' => $u->id];
    })(),
    // setup_checkout: HS đã xác thực có giỏ 1 khóa 100k + OTP xoá tài khoản.
    'setup_checkout' => (function () {
        $u = User::factory()->student()->verified()->create(['email' => 'race34c-'.uniqid('', true).'@example.test']);
        $course = Course::factory()->published()->paid(100000)->create();
        Cart::factory()->state(['user_id' => $u->id])->withCourses([$course])->create();
        OtpCode::query()->create([
            'user_id' => $u->id, 'purpose' => OtpPurpose::DeleteAccount, 'channel' => 'email', 'destination' => $u->email,
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
        ]);

        return ['id' => $u->id, 'course' => $course->id, 'creator' => $course->created_by];
    })(),
    // export <user> <startAt>
    'export' => (function () use ($args, $wait, $run) {
        [$id, $startAt] = $args;
        $user = User::findOrFail($id);
        Auth::setUser($user); // như request thật: AuditLogger lấy actor từ người đăng nhập (nguồn đếm hạn mức)
        $wait($startAt);

        return $run(function () use ($user) {
            $f = app(DataExportService::class)->export($user, 'password');

            return ['bytes' => $f['bytes']];
        });
    })(),
    // delete <user> <code> <startAt>
    'delete' => (function () use ($args, $wait, $run) {
        [$id, $code, $startAt] = $args;
        $user = User::findOrFail($id);
        Auth::setUser($user);
        $wait($startAt);

        return $run(function () use ($user, $code) {
            app(AccountAnonymizer::class)->confirm($user, $code);

            return [];
        });
    })(),
    // bind <user> <startAt>: đăng nhập đua (chỉ bước bind phiên).
    'bind' => (function () use ($args, $wait, $run) {
        [$id, $startAt] = $args;
        $user = User::findOrFail($id);
        $request = Request::create('/');
        $store = app('session')->driver('array');
        $store->start();
        $request->setLaravelSession($store);
        $wait($startAt);

        return $run(function () use ($user, $request) {
            app(StudentSessionService::class)->bind($user, $request);

            return [];
        });
    })(),
    // checkout <user> <startAt>
    'checkout' => (function () use ($args, $wait, $run) {
        [$id, $startAt] = $args;
        $user = User::findOrFail($id);
        $wait($startAt);

        return $run(function () use ($user) {
            $r = app(CheckoutService::class)->checkout($user, 100000, 'fake');

            return ['order' => $r->order->code];
        });
    })(),
    'state' => (function () use ($args) {
        $id = (int) $args[0];
        $u = DB::table('users')->where('id', $id)->first();
        $orders = DB::table('orders')->where('user_id', $id)->get();

        return [
            'anonymized' => $u->anonymized_at !== null,
            'email' => $u->email,
            'session' => $u->current_session_id,
            'audit_anonymized' => AuditLog::query()->where('action', 'privacy.account_anonymized')->where('subject_id', $id)->count(),
            'audit_export' => AuditLog::query()->where('action', 'privacy.data_export')->where('actor_id', $id)->count(),
            'otp_rows' => DB::table('otp_codes')->where('user_id', $id)->count(),
            'consents_live' => DB::table('consents')->where('user_id', $id)->whereNull('revoked_at')->count(),
            'consents_with_ip' => DB::table('consents')->where('user_id', $id)->whereNotNull('ip')->count(),
            'orders' => $orders->map(fn ($o) => [
                'status' => $o->status,
                'attempts' => DB::table('payment_attempts')->where('order_id', $o->id)->pluck('status')->all(),
            ])->all(),
        ];
    })(),
    // Không xoá audit_logs: trigger bất biến; DB test riêng, test đếm theo subject_id/actor_id.
    'cleanup' => (function () use ($args) {
        $ids = array_map('intval', $args);
        $orderIds = DB::table('orders')->whereIn('user_id', $ids)->pluck('id')->all();
        DB::table('payment_attempts')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_status_logs')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('user_id', $ids)->delete();
        $cartIds = DB::table('carts')->whereIn('user_id', $ids)->pluck('id')->all();
        DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
        DB::table('carts')->whereIn('user_id', $ids)->delete();
        DB::table('otp_codes')->whereIn('user_id', $ids)->delete();
        DB::table('consents')->whereIn('user_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();

        return ['ok' => true];
    })(),
    // cleanup_course <course> <creator>: chạy SAU cleanup của HS (đơn/dòng giỏ trỏ tới khóa đã bị xoá).
    'cleanup_course' => (function () use ($args) {
        DB::table('courses')->where('id', (int) $args[0])->delete();
        DB::table('users')->where('id', (int) $args[1])->delete();

        return ['ok' => true];
    })(),
    default => ['error' => 'mode?'],
};

echo json_encode($out);
