<?php

/*
 * Tiến trình con của ResetConcurrencyTest (AC7): boot app riêng, chờ mốc chung rồi gọi PasswordService::reset().
 * Chạy: php worker-reset.php <login> <code> <new_password> <start_microtime> <dir>
 * In đúng 1 dòng: ok | fail:<code field>:<message> | error:<class>:<msg>
 */

use App\Services\Auth\PasswordService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

[, $login, $code, $password, $startAt, $dir] = $argv;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver' => 'file', 'session.files' => $dir.'/sessions', 'cache.default' => 'file', 'cache.stores.file.path' => $dir.'/cache']);

$service = app(PasswordService::class);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $service->reset($login, $code, $password);
    echo 'ok';
} catch (ValidationException $e) {
    echo 'fail:'.($e->errors()['code'][0] ?? 'other');
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
