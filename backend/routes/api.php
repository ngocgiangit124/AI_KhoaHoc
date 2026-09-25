<?php

use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Host api (học sinh + webhook) — ADR-004 §2.1, api-contract §1.1
|--------------------------------------------------------------------------
|
| File này được framework tự bọc middleware group 'api' + prefix 'api'
| (withRouting(api: ...)); ở đây chỉ cần bọc thêm Route::domain() + prefix
| 'v1' để có đường dẫn cuối cùng /api/v1/....
*/

Route::domain(config('app.api_host'))->prefix('v1')->group(function (): void {
    Route::get('/csrf-token', CsrfController::class);
    Route::get('/config/public', [PublicConfigController::class, 'show']);
    Route::get('/health', HealthController::class);

    // Auth (T03/T04/T05/T27), Catalog (T10), Cart/Checkout (T16/T18),
    // Learn (T13), Webhooks (T19) — thêm dần ở các task sau.
});
