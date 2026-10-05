<?php

use App\Http\Controllers\Api\V1\Auth\ContactController;
use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Cart\CartCouponController;
use App\Http\Controllers\Api\V1\Cart\CartItemController;
use App\Http\Controllers\Api\V1\Catalog\CourseController as CatalogCourseController;
use App\Http\Controllers\Api\V1\Catalog\SubjectController as CatalogSubjectController;
use App\Http\Controllers\Api\V1\Enrollment\FreeEnrollmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Learn\LearnCourseController;
use App\Http\Controllers\Api\V1\Learn\LessonController as LearnLessonController;
use App\Http\Controllers\Api\V1\Learn\PlaybackController;
use App\Http\Controllers\Api\V1\Learn\ProgressController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use App\Http\Controllers\Api\V1\Webhook\VideoWebhookController;
use App\Http\Middleware\VaryOnOrigin;
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

    // T10 — danh mục công khai (US-002/003): cache được (`public, max-age=60` + ETag), không session/cookie
    // (S16). Dữ liệu theo người xem nằm ở /courses/{slug}/viewer-state (nhóm student bên dưới).
    Route::middleware(['throttle:catalog', VaryOnOrigin::class, 'cache.headers:public;max_age=60;etag'])
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->group(function (): void {
            Route::get('/subjects', [CatalogSubjectController::class, 'index'])->name('api.catalog.subjects');
            Route::get('/courses', [CatalogCourseController::class, 'index'])->name('api.catalog.courses');
            Route::get('/courses/{slug}', [CatalogCourseController::class, 'show'])
                ->where('slug', '[a-z0-9-]+')
                ->name('api.catalog.courses.show');
        });

    // T13 — playback bài preview công khai (US-003/006): không đăng nhập, không session/cookie, throttle theo IP.
    // Chỉ bài is_preview chưa xoá của khóa published; mọi lý do khác 404. Link không ràng IP.
    Route::get('/preview/lessons/{lesson}/playback', [PlaybackController::class, 'preview'])
        ->whereNumber('lesson')
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->middleware(['throttle:playback', VaryOnOrigin::class])
        ->name('api.preview.playback');

    // csrf-token CẦN session (mục đích chính là phát hành token CSRF) nên giữ
    // nguyên EnsureFrontendRequestsAreStateful; limiter `csrf` riêng (M3) chống
    // client ngoài trình duyệt tạo phiên Redis không giới hạn.
    Route::get('/csrf-token', CsrfController::class)
        ->middleware('throttle:csrf')
        ->name('api.csrf-token');

    // Đăng ký/đăng nhập (T03). Cần session (EnsureFrontendRequestsAreStateful giữ nguyên).
    // Throttle 2 lớp ở AppServiceProvider (S10).
    // - register: `guest.student` — đã đăng nhập hợp lệ thì 403 FORBIDDEN; phiên cũ đã bị thay thế /
    //   đăng xuất / bị khoá thì coi như khách (không trả FORBIDDEN gây lẫn lý do mất phiên).
    // - login: KHÔNG có `guest` (ADR-003, T05): đăng nhập lại khi cookie cũ còn sống (cùng thiết bị bấm 2 lần,
    //   phiên đã bị thay thế, tài khoản vừa bị khoá) đi qua LoginService như bình thường -> bind phiên mới,
    //   hoặc ACCOUNT_LOCKED đúng mã lỗi.
    Route::post('/auth/register', RegisterController::class)
        ->middleware(['guest.student', 'throttle:register', 'no_store'])
        ->name('api.auth.register');

    Route::post('/auth/login', [LoginController::class, 'store'])
        ->middleware(['throttle:login', 'no_store'])
        ->name('api.auth.login');

    // Ngoại lệ duy nhất của nhóm student (api-contract §1.3): logout chỉ cần auth:sanctum.
    Route::post('/auth/logout', [LoginController::class, 'destroy'])
        ->middleware('auth:sanctum')
        ->name('api.auth.logout');

    // Quên/đặt lại mật khẩu (T27, US-015). `guest.student`: phiên cũ đã bị thay thế/huỷ coi như khách.
    Route::post('/auth/password/forgot', [PasswordResetController::class, 'request'])
        ->middleware(['guest.student', 'throttle:password-reset', 'no_store'])
        ->name('api.auth.password.forgot');

    Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware(['guest.student', 'throttle:otp-verify', 'no_store'])
        ->name('api.auth.password.reset');

    // Nhóm `student` (api-contract §1.3): MỌI route khác cần đăng nhập trên host api
    // phải nằm trong nhóm này (test kiến trúc RouteMiddlewareGroupsTest bắt buộc).
    // T05, T10+ thêm route vào đây.
    Route::middleware([
        'auth:sanctum', 'account.active', 'student.single_session', 'no_store', 'role:hoc_sinh',
    ])->group(function (): void {
        Route::get('/auth/me', MeController::class)->name('api.auth.me');

        // T10 — trạng thái nút hành động của người xem (không cache chung được, S16).
        Route::get('/courses/{slug}/viewer-state', [CatalogCourseController::class, 'viewerState'])
            ->where('slug', '[a-z0-9-]+')
            ->name('api.catalog.courses.viewer-state');

        // T04 — OTP (S9): trần gửi/verify do OtpService + throttle (contract §1.6).
        Route::post('/auth/otp/send', [OtpController::class, 'send'])
            ->middleware('throttle:otp-send')
            ->name('api.auth.otp.send');

        Route::post('/auth/otp/verify', [OtpController::class, 'verify'])
            ->middleware('throttle:otp-verify')
            ->name('api.auth.otp.verify');

        Route::put('/auth/contact', [ContactController::class, 'update'])
            ->middleware('throttle:contact')
            ->name('api.auth.contact');

        // T27 — đổi mật khẩu: huỷ phiên khác, bind lại phiên hiện tại (ADR-003).
        Route::put('/auth/password', [PasswordController::class, 'update'])
            ->middleware(['throttle:password-change', 'no_store'])
            ->name('api.auth.password');

        // T14 — xin học khóa miễn phí (US-012). `account.verified` chỉ gắn ở route này (US-001 AC9: chặn
        // đăng ký miễn phí/checkout, KHÔNG chặn xem/học). `parent.consent` chưa có (US-017 chờ pháp chế).
        Route::post('/courses/{course}/free-enrollments', [FreeEnrollmentController::class, 'store'])
            ->middleware('account.verified')
            ->name('api.courses.free-enrollments.store');

        // T16 — giỏ hàng (US-004). Không cần `account.verified` (xem/sửa giỏ không bị chặn; chỉ checkout T18 chặn).
        // PUT /cart/coupon: `throttle:coupon` (10/phút + 60/giờ/IP) và trần 30 lần SAI/ngày/HS do CartService đếm.
        Route::get('/cart', [CartController::class, 'show'])->name('api.cart.show');
        Route::post('/cart/items', [CartItemController::class, 'store'])->name('api.cart.items.store');
        Route::delete('/cart/items/{course}', [CartItemController::class, 'destroy'])
            ->whereNumber('course')
            ->name('api.cart.items.destroy');
        Route::put('/cart/coupon', [CartCouponController::class, 'update'])
            ->middleware('throttle:coupon')
            ->name('api.cart.coupon.update');
        Route::delete('/cart/coupon', [CartCouponController::class, 'destroy'])->name('api.cart.coupon.destroy');

        // T13 — học & tiến độ (US-006). Quyền kiểm trong LessonAccessService (mã COURSE_NOT_OWNED, khóa nháp 404).
        // `account.verified` KHÔNG gắn: chưa xác thực OTP vẫn học được (US-001 AC9). Heartbeat: 6/phút/user/bài.
        Route::get('/learn/courses/{course}', [LearnCourseController::class, 'show'])
            ->whereNumber('course')
            ->name('api.learn.courses.show');
        Route::get('/learn/lessons/{lesson}', [LearnLessonController::class, 'show'])
            ->whereNumber('lesson')
            ->name('api.learn.lessons.show');
        Route::get('/learn/lessons/{lesson}/playback', [PlaybackController::class, 'show'])
            ->whereNumber('lesson')
            ->middleware('throttle:playback')
            ->name('api.learn.lessons.playback');
        Route::post('/learn/lessons/{lesson}/heartbeat', [ProgressController::class, 'heartbeat'])
            ->whereNumber('lesson')
            ->middleware('throttle:heartbeat')
            ->name('api.learn.lessons.heartbeat');
    });

    // T11 — webhook video (ADR-002 §2): payload không được tin, service gọi lại getVideo(). Tên provider chỉ
    // nhận allowlist (fake chỉ ở local/testing); provider chưa bật/chưa cấu hình → 404.
    Route::post('/webhooks/video/{provider}', VideoWebhookController::class)
        ->whereIn('provider', app()->environment('local', 'testing') ? ['internal', 'bunny', 'fake'] : ['internal', 'bunny'])
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->middleware('throttle:webhook')
        ->name('api.webhooks.video');

    // Auth (T04/T05/T27), Catalog (T10), Cart/Checkout (T16/T18),
    // Learn (T13), Webhooks (T19) — thêm dần ở các task sau.
});
