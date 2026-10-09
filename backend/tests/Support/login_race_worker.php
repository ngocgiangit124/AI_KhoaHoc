<?php

/** Tiến trình con cho race test đăng nhập (QA minor-fixes-2 R5). Chỉ chạy trên DB `*_testing`. Xuất 1 dòng JSON. */

use App\Exceptions\LoginChallengeException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginService;
use App\Services\Auth\Staff\StaffAuthService;
use App\Support\AtomicCounter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

$staff = str_starts_with($mode, 'staff-');
$mode = $staff ? substr($mode, 6) : $mode;

$out = match ($mode) {
    'setup' => (function () {
        global $staff;
        $user = ($staff ? User::factory()->teacher() : User::factory()->student())->create([
            'email' => 'race-login-'.uniqid().'@example.test',
            'password' => Hash::make('dung-mat-khau-1'),
        ]);

        return ['id' => $user->id, 'email' => $user->email];
    })(),
    'preset' => (function () use ($args, $staff) {
        // [id, ip, tài khoản, IP]: đặt sẵn bộ đếm lượt sai (tài khoản theo user id; null = bỏ qua).
        [$id, $ip, $account, $ipCount] = $args;
        $p = $staff ? 'staff-login-fail' : 'login-fail';
        if ((int) $account > 0) {
            AtomicCounter::add($p.':u:'.$id, (int) $account, 3600);
        }
        if ((int) $ipCount > 0) {
            AtomicCounter::add($p.'-ip:'.$ip, (int) $ipCount, 3600);
        }

        return ['ok' => true];
    })(),
    'count' => (function () use ($args, $staff) {
        [$id, $ip] = $args;
        $p = $staff ? 'staff-login-fail' : 'login-fail';

        return ['account' => AtomicCounter::attempts($p.':u:'.$id), 'ip' => AtomicCounter::attempts($p.'-ip:'.$ip)];
    })(),
    'cleanup' => (function () use ($args, $staff) {
        [$id, $ip] = $args;
        $p = $staff ? 'staff-login-fail' : 'login-fail';
        RateLimiter::clear($p.':u:'.$id);
        RateLimiter::clear($p.'-ip:'.$ip);
        RateLimiter::clear(($staff ? 'staff-login-captcha-reject' : 'login-captcha-reject').':'.$ip);
        DB::table('users')->where('id', $id)->delete();

        return ['ok' => true];
    })(),
    'login' => (function () use ($args, $staff) {
        [$email, $ip, $startAt] = $args;
        // Tham số 4: 'ok' = gửi captcha (fake); tham số 5: 'good' = mật khẩu ĐÚNG (người thật), mặc định mật khẩu sai.
        $captcha = ($args[3] ?? '') === 'ok' ? 'ok' : null;
        $password = ($args[4] ?? '') === 'good' ? 'dung-mat-khau-1' : 'sai-mat-khau';
        // R4: không ghi audit_logs (bảng bất biến ở tầng DB nên test không dọn được) — worker dùng bộ ghi rỗng.
        app()->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function log(string $action, ?Model $subject = null, array $changes = []): AuditLog
            {
                return new AuditLog;
            }
        });

        while (microtime(true) < (float) $startAt) {
            usleep(200);
        }
        $checks = 0;
        Hash::swap(new class(Hash::getFacadeRoot(), $checks) extends HashManager
        {
            public function __construct(private $inner, public int &$count)
            {
                parent::__construct(app());
            }

            public function check($value, $hashedValue, array $options = [])
            {
                $this->count++;

                return $this->inner->check($value, $hashedValue, $options);
            }
        });
        $request = Request::create('/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]);
        $request->setLaravelSession(app('session')->driver());
        try {
            $staff
                ? app(StaffAuthService::class)->login($email, $password, $request, $captcha)
                : app(LoginService::class)->attempt($email, $password, $request, $captcha);
            $result = 'ok';
        } catch (ThrottleRequestsException) {
            $result = 'throttled';
        } catch (LoginChallengeException $e) {
            $result = $e->errorCode === 'VALIDATION_ERROR' ? 'wrong' : 'captcha';
        } catch (ValidationException) {
            $result = 'wrong';
        } catch (Throwable $e) {
            $result = 'error:'.$e::class.':'.substr($e->getMessage(), 0, 150);
        }

        return ['result' => $result, 'checks' => $checks];
    })(),
};

echo json_encode($out);
