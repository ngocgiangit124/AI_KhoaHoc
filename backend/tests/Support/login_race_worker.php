<?php

/** Tiến trình con cho race test đăng nhập (QA minor-fixes-2 R5). Chỉ chạy trên DB `*_testing`. Xuất 1 dòng JSON. */

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\Staff\StaffAuthService;
use Illuminate\Contracts\Console\Kernel;
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
    'cleanup' => (function () use ($args, $staff) {
        [$id, $ip] = $args;
        $p = $staff ? 'staff-login-fail' : 'login-fail';
        RateLimiter::clear($p.':u:'.$id);
        RateLimiter::clear($p.'-ip:'.$ip);
        DB::table('users')->where('id', $id)->delete();

        return ['ok' => true];
    })(),
    'login' => (function () use ($args, $staff) {
        [$email, $ip, $startAt] = $args;
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
        try {
            $staff
                ? app(StaffAuthService::class)->login($email, 'sai-mat-khau', $request)
                : app(LoginService::class)->attempt($email, 'sai-mat-khau', $request);
            $result = 'ok';
        } catch (ThrottleRequestsException) {
            $result = 'throttled';
        } catch (ValidationException) {
            $result = 'wrong';
        } catch (Throwable $e) {
            $result = 'error:'.$e::class.':'.substr($e->getMessage(), 0, 150);
        }

        return ['result' => $result, 'checks' => $checks];
    })(),
};

echo json_encode($out);
