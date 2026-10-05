<?php

/*
 * Tiến trình con của BindConcurrencyTest (T05-5): boot app riêng, dựng Request có session id riêng,
 * chờ mốc thời gian chung rồi gọi StudentSessionService::bind(). Session + cache dùng thư mục file chung
 * để tiến trình cha kiểm tra được kết quả (session đã bị huỷ chưa, tombstone nào được ghi).
 * Chạy: php worker-bind.php <user_id> <session_id> <device_id|-> <start_microtime> <dir>
 * In đúng 1 dòng: ok | error:<class>:<msg>
 */

use App\Models\User;
use App\Services\Auth\StudentSessionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

[, $userId, $sessionId, $deviceId, $startAt, $dir] = $argv;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver' => 'file', 'session.files' => $dir.'/sessions', 'cache.default' => 'file', 'cache.stores.file.path' => $dir.'/cache']);

$user = User::query()->findOrFail((int) $userId);

$request = Request::create('http://api.localhost/api/v1/auth/login', 'POST', $deviceId === '-' ? [] : ['device_id' => $deviceId]);
/** @var Store $store */
$store = Session::driver();
$store->setId($sessionId);
$store->put('probe', 'x'); // tạo payload để biết "còn/mất" trong store
$store->save();
$store->start();
$request->setLaravelSession($store);
Auth::guard('web')->setRequest($request);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    app(StudentSessionService::class)->bind($user, $request);
    echo 'ok';
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
