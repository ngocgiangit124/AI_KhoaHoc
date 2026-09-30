<?php

use App\Http\Controllers\Api\V1\Auth\ContactController;
use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Catalog\CourseController;
use App\Http\Controllers\Api\V1\Catalog\SubjectController;
use App\Http\Controllers\Api\V1\Enrollment\FreeEnrollmentController;
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

            // T10 (US-002, US-003, api-contract §2.1) — danh mục công khai:
            // không đọc/ghi cookie (M3), cache được ở CDN/Nginx (S16).
            Route::get('/subjects', [SubjectController::class, 'index'])
                ->name('api.subjects.index');
            Route::get('/courses', [CourseController::class, 'index'])
                ->name('api.courses.index');
            Route::get('/courses/{course:slug}', [CourseController::class, 'show'])
                ->name('api.courses.show');
        });

    // /viewer-state CẦN session (nhóm `student` — api-contract §2.1): tách
    // khỏi `show` để `show` cache công khai được (S16).
    Route::get('/courses/{course:slug}/viewer-state', [CourseController::class, 'viewerState'])
        ->middleware(['auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh'])
        ->name('api.courses.viewer-state');

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

            // T04 review R1 [BLOCKER] — TRƯỚC ĐÂY route này không có throttle
            // nào, và `OtpService::send()` (gọi qua `ContactService`) không tự
            // giới hạn gì, nên một tài khoản có thể đổi email liên tục để gửi
            // OTP thật không giới hạn tới bất kỳ hộp thư nào (email bombing).
            // Gắn CHUNG limiter `otp-send` (khớp định danh user với
            // `/auth/otp/send`) làm lớp phòng thủ 1 (chặn sớm ở HTTP, trước cả
            // FormRequest/Controller). Lớp phòng thủ 2 (độc lập, không thể bị
            // quên khi thêm route mới) nằm NGAY TRONG `OtpService::send()`
            // (xem `assertUnderSendLimits()`).
            Route::put('/auth/contact', [ContactController::class, 'update'])
                ->middleware('throttle:otp-send')
                ->name('api.auth.contact.update');
        });

    // T14 (US-012, api-contract §2.4) — đăng ký khóa học miễn phí. Nhóm
    // `student` chuẩn + `account.verified` (T04, api-contract §1.7
    // `ACCOUNT_NOT_VERIFIED`) + `parent.consent` (bản tạm, tasks.md T18) —
    // BR7 (US-012): phải đã xác thực OTP + không đang bị chặn vì thiếu xác
    // nhận phụ huynh.
    Route::post('/courses/{course}/free-enrollments', [FreeEnrollmentController::class, 'store'])
        ->middleware([
            'auth:sanctum',
            'account.active',
            'student.single_session',
            'no_store',
            'role:hoc_sinh',
            'account.verified',
            'parent.consent',
            'throttle:free-enroll',
        ])
        ->name('api.courses.free-enrollments.store');

    // Cart/Checkout (T16/T18), Learn (T13), Webhooks (T19) — thêm dần ở các
    // task sau.
});
