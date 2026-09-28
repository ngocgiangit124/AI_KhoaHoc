<?php

use App\Http\Controllers\Api\V1\Auth\ContactController;
use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
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

    // T03 (US-001, api-contract §2.2) — đăng ký/đăng nhập học sinh. OTP (T04),
    // một phiên/tombstone (T05), quên mật khẩu (T27) thêm ở các task sau.
    // L1 (review docs/security/review-T03-FW1.md) — `stateful` đứng đầu, chạy
    // trước guest/throttle (với logout thì KHÔNG trước được auth:sanctum: Laravel
    // luôn xếp middleware xác thực lên trước, nên logout không Origin trả 401
    // thay vì 400 — không có tác dụng phụ). Request thiếu Origin/Referer
    // hợp lệ không có session, trước đây chạy hết Service (kể cả ghi DB ở
    // register) rồi mới vỡ 500 ở session()->regenerate()/invalidate().
    Route::post('/auth/register', RegisterController::class)
        ->middleware(['stateful', 'guest', 'throttle:register'])
        ->name('api.auth.register');

    Route::post('/auth/login', [LoginController::class, 'store'])
        ->middleware(['stateful', 'guest', 'throttle:login'])
        ->name('api.auth.login');

    // Ngoại lệ duy nhất của nhóm `student` (api-contract §1.3): chỉ auth:sanctum
    // (+ `stateful` — L1, logout cũng cần session để invalidate()/regenerateToken()).
    Route::post('/auth/logout', [LoginController::class, 'destroy'])
        ->middleware(['stateful', 'auth:sanctum'])
        ->name('api.auth.logout');

    Route::get('/auth/me', MeController::class)
        ->middleware(['auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh'])
        ->name('api.auth.me');

    // T04 (US-001 AC8/AC9, api-contract §2.2) — OTP xác thực tài khoản. Nhóm
    // `student` chuẩn (api-contract §1.3): KHÔNG có `account.verified` (chính
    // các route này là luồng để TRỞ THÀNH đã xác thực).
    Route::middleware(['auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh'])
        ->group(function (): void {
            Route::post('/auth/otp/send', [OtpController::class, 'send'])
                ->middleware('throttle:otp-send')
                ->name('api.auth.otp.send');

            Route::post('/auth/otp/verify', [OtpController::class, 'verify'])
                ->middleware('throttle:otp-verify')
                ->name('api.auth.otp.verify');

            Route::put('/auth/contact', [ContactController::class, 'update'])
                ->name('api.auth.contact.update');
        });

    // Catalog (T10), Cart/Checkout (T16/T18), Learn (T13), Webhooks (T19) —
    // thêm dần ở các task sau.
});
