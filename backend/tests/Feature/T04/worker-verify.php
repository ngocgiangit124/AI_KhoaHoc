<?php

/*
 * Tiến trình con của OtpConcurrencyTest: boot app Laravel riêng, chờ mốc thời gian rồi gọi
 * OtpService::verifyAccount. In ra đúng 1 dòng kết quả: ok | wrong | expired | locked | error:<class>.
 * Chạy: php worker-verify.php <user_id> <code> <start_microtime>
 */

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

[, $userId, $code, $startAt] = $argv;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->findOrFail((int) $userId);
$service = $app->make(OtpService::class);

// Chờ đến mốc chung để mọi tiến trình bắn gần như cùng lúc.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $service->verifyAccount($user, $code);
    echo 'ok';
} catch (ValidationException $e) {
    $msg = $e->errors()['code'][0] ?? '';
    echo $msg === OtpService::MESSAGE_WRONG ? 'wrong' : 'expired';
} catch (DomainException $e) {
    echo $e->status() === 429 ? 'locked' : 'error:'.$e->code();
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
