<?php

use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

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
    // M3 (review bảo mật T01/T02) — endpoint công khai, có thể cache (CDN/Nginx
    // micro-cache): KHÔNG được khởi tạo session/Set-Cookie dù request có Origin
    // thuộc SANCTUM_STATEFUL_DOMAINS (ADR-004 §2.5 — "không đọc cookie"). Tắt
    // hẳn middleware Sanctum đẩy StartSession/EncryptCookies vào pipeline, thay
    // vì chỉ dựa vào việc controller không gọi session().
    Route::middleware(['throttle:catalog'])
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->group(function (): void {
            Route::get('/config/public', [PublicConfigController::class, 'show']);
            Route::get('/health', HealthController::class);
        });

    // csrf-token CẦN session (mục đích chính là phát hành token CSRF) nên giữ
    // nguyên EnsureFrontendRequestsAreStateful; limiter `csrf` riêng (M3) chống
    // client ngoài trình duyệt tạo phiên Redis không giới hạn.
    Route::get('/csrf-token', CsrfController::class)
        ->middleware('throttle:csrf')
        ->name('api.csrf-token');

    // Đăng ký/đăng nhập (T03). Cần session (EnsureFrontendRequestsAreStateful giữ nguyên)
    // nhưng chưa đăng nhập: `guest`. Throttle 2 lớp ở AppServiceProvider (S10).
    Route::middleware(['guest:web'])->group(function (): void {
        Route::post('/auth/register', RegisterController::class)
            ->middleware(['throttle:register', 'no_store'])
            ->name('api.auth.register');

        Route::post('/auth/login', [LoginController::class, 'store'])
            ->middleware(['throttle:login', 'no_store'])
            ->name('api.auth.login');
    });

    // Ngoại lệ duy nhất của nhóm student (api-contract §1.3): logout chỉ cần auth:sanctum.
    Route::post('/auth/logout', [LoginController::class, 'destroy'])
        ->middleware('auth:sanctum')
        ->name('api.auth.logout');

    // Nhóm `student` (api-contract §1.3): MỌI route khác cần đăng nhập trên host api
    // phải nằm trong nhóm này (test kiến trúc RouteMiddlewareGroupsTest bắt buộc).
    // T04 (OTP/me/contact), T05, T10+ thêm route vào đây.
    Route::middleware([
        'auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh',
    ])->group(function (): void {
        //
    });

    // Auth (T04/T05/T27), Catalog (T10), Cart/Checkout (T16/T18),
    // Learn (T13), Webhooks (T19) — thêm dần ở các task sau.
});
