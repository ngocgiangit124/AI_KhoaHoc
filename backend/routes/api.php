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
use App\Http\Controllers\Api\V1\Catalog\HomeTeacherController;
use App\Http\Controllers\Api\V1\Catalog\SubjectController as CatalogSubjectController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Enrollment\FreeEnrollmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Learn\LearnCourseController;
use App\Http\Controllers\Api\V1\Learn\LessonController as LearnLessonController;
use App\Http\Controllers\Api\V1\Learn\MyCourseController;
use App\Http\Controllers\Api\V1\Learn\PlaybackController;
use App\Http\Controllers\Api\V1\Learn\ProgressController;
use App\Http\Controllers\Api\V1\Order\MyOrderController;
use App\Http\Controllers\Api\V1\Order\OrderCancelController;
use App\Http\Controllers\Api\V1\Privacy\AccountDeletionController;
use App\Http\Controllers\Api\V1\Privacy\ConsentController;
use App\Http\Controllers\Api\V1\Privacy\DataExportController;
use App\Http\Controllers\Api\V1\Privacy\ParentContactController;
use App\Http\Controllers\Api\V1\Privacy\ParentNoticeUnsubscribeController;
use App\Http\Controllers\Api\V1\PublicConfigController;
use App\Http\Controllers\Api\V1\Quiz\AnswerController;
use App\Http\Controllers\Api\V1\Quiz\AttemptController;
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
            // US-020 (T36): khu vực giáo viên trang chủ. Laravel không cache; ảnh/bio chỉ qua `PublicTeacher`.
            Route::get('/home/teachers', [HomeTeacherController::class, 'index'])->name('api.catalog.home-teachers');
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

    // T29 (ADR-006) — phụ huynh huỷ nhận thông báo. Công khai, không session/cookie/CSRF (như webhook): token HMAC trong
    // thư là bằng chứng duy nhất. Luôn trả cùng một body (không lộ gì). Throttle theo IP.
    Route::post('/parent-notices/unsubscribe', ParentNoticeUnsubscribeController::class)
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->middleware('throttle:parent-notice-unsub')
        ->name('api.parent-notices.unsubscribe');

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
            ->middleware(['throttle:password-change', 'throttle:contact', 'no_store'])
            ->name('api.auth.contact');

        // T27 — đổi mật khẩu: huỷ phiên khác, bind lại phiên hiện tại (ADR-003).
        Route::put('/auth/password', [PasswordController::class, 'update'])
            ->middleware(['throttle:password-change', 'no_store'])
            ->name('api.auth.password');

        // T29 (ADR-006) — liên hệ phụ huynh của chính học sinh. Không cần `account.verified`. PUT cần mật khẩu hiện tại
        // (CurrentPasswordGuard) và dùng chung hạn mức dò mật khẩu `password-change` với đổi liên hệ/mật khẩu.
        Route::get('/me/parent-contact', [ParentContactController::class, 'show'])
            ->middleware('throttle:privacy-read')
            ->name('api.me.parent-contact.show');
        Route::put('/me/parent-contact', [ParentContactController::class, 'update'])
            ->middleware(['throttle:parent-contact', 'throttle:password-change'])
            ->name('api.me.parent-contact.update');

        // T34 (ADR-006) — quyền dữ liệu cá nhân của chính học sinh. Không cần `account.verified`, trừ 2 route xoá tài khoản
        // (xác nhận qua email đã xác thực: kiểm trong AccountAnonymizer → 403 ACCOUNT_NOT_VERIFIED).
        Route::get('/me/consents', [ConsentController::class, 'index'])
            ->middleware('throttle:privacy-read')
            ->name('api.me.consents.index');
        Route::post('/me/consents/accept', [ConsentController::class, 'accept'])
            ->middleware('throttle:consent-accept')
            ->name('api.me.consents.accept');
        Route::get('/me/data-export', [DataExportController::class, 'status'])
            ->middleware('throttle:privacy-read')
            ->name('api.me.data-export.status');
        Route::post('/me/data-export', [DataExportController::class, 'store'])
            ->middleware(['throttle:data-export', 'throttle:password-change'])
            ->name('api.me.data-export.store');
        Route::post('/me/account/delete/otp', [AccountDeletionController::class, 'sendOtp'])
            ->middleware('throttle:otp-send')
            ->name('api.me.account.delete.otp');
        Route::post('/me/account/delete', [AccountDeletionController::class, 'confirm'])
            ->middleware('throttle:otp-verify')
            ->name('api.me.account.delete');

        // T14 — xin học khóa miễn phí (US-012). `account.verified` chỉ gắn ở route này (US-001 AC9: chặn
        // đăng ký miễn phí/checkout, KHÔNG chặn xem/học). ADR-006 (T29): không còn middleware phụ huynh đồng ý.
        Route::post('/courses/{course}/free-enrollments', [FreeEnrollmentController::class, 'store'])
            ->middleware('account.verified')
            ->name('api.courses.free-enrollments.store');

        // T16 — giỏ hàng (US-004). Không cần `account.verified` (xem/sửa giỏ không bị chặn; chỉ checkout T18 chặn).
        // PUT /cart/coupon: `throttle:coupon` (10/phút + 60/giờ/IP) và trần 30 lần SAI/ngày/HS do CartService đếm.
        Route::get('/cart', [CartController::class, 'show'])->middleware('throttle:cart')->name('api.cart.show');
        Route::post('/cart/items', [CartItemController::class, 'store'])->middleware('throttle:cart')->name('api.cart.items.store');
        Route::delete('/cart/items/{course}', [CartItemController::class, 'destroy'])
            ->middleware('throttle:cart')
            ->whereNumber('course')
            ->name('api.cart.items.destroy');
        Route::put('/cart/coupon', [CartCouponController::class, 'update'])
            ->middleware('throttle:coupon')
            ->name('api.cart.coupon.update');
        Route::delete('/cart/coupon', [CartCouponController::class, 'destroy'])->middleware('throttle:cart')->name('api.cart.coupon.destroy');

        // T18 — checkout (US-005). `account.verified` (US-001 AC9). ADR-006: middleware `parent.consent` đã bị xoá.
        // Preview không ghi DB; POST /checkout tạo đơn pending + giao dịch cổng (hoặc hoàn tất đơn 0đ).
        // `/orders/{code}/pay`, `/orders/{code}/check-payment`, GET /orders: T20.
        Route::middleware(['account.verified'])->group(function (): void {
            Route::get('/checkout/preview', [CheckoutController::class, 'preview'])->middleware('throttle:cart')->name('api.checkout.preview');
            Route::post('/checkout', [CheckoutController::class, 'store'])
                ->middleware('throttle:checkout')
                ->name('api.checkout.store');
        });
        // US-022 (T38) — đơn của tôi + học sinh tự huỷ đơn thủ công. KHÔNG cần `account.verified` (xem/huỷ đơn không cần xác thực)
        // và chạy được cả khi `FEATURE_MANUAL_PAYMENT` tắt (AC29). Tra đơn theo `code` + `user_id`: người khác nhận 404.
        Route::get('/orders', [MyOrderController::class, 'index'])->middleware('throttle:orders-read')->name('api.orders.index');
        Route::get('/orders/{code}', [MyOrderController::class, 'show'])
            ->where('code', '[A-Za-z0-9]{1,20}')
            ->middleware('throttle:orders-read')
            ->name('api.orders.show');
        Route::post('/orders/{code}/cancel', [OrderCancelController::class, 'store'])
            ->where('code', '[A-Za-z0-9]{1,20}')
            ->middleware('throttle:order-cancel')
            ->name('api.orders.cancel');
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
        // Đánh dấu đã học thủ công: chỉ bài link ngoài (US-006, PO 2026-10-07). Cùng limiter với heartbeat (6/phút/user/bài).
        Route::post('/learn/lessons/{lesson}/complete', [ProgressController::class, 'complete'])
            ->whereNumber('lesson')
            ->middleware('throttle:heartbeat')
            ->name('api.learn.lessons.complete');

        // T23 — Khóa học của tôi + tiến độ (US-008). Quyền: role học sinh (group) + LessonAccessService (COURSE_NOT_OWNED).
        Route::middleware('throttle:me-courses')->group(function (): void {
            Route::get('/me/courses', [MyCourseController::class, 'index'])->name('api.me.courses.index');
            Route::get('/me/courses/{course}/progress', [MyCourseController::class, 'progress'])
                ->whereNumber('course')
                ->name('api.me.courses.progress');
        });

        // T22 — làm quiz (US-007). Quyền (COURSE_NOT_OWNED; lượt của người khác → 404) kiểm trong QuizAttemptService.
        Route::middleware('throttle:quiz')->group(function (): void {
            Route::post('/learn/quizzes/{quiz}/attempts', [AttemptController::class, 'store'])
                ->whereNumber('quiz')->name('api.quiz.attempts.store');
            Route::post('/learn/quiz-attempts/{attempt}/submit', [AttemptController::class, 'submit'])
                ->whereNumber('attempt')->name('api.quiz.attempts.submit');
        });
        Route::middleware('throttle:quiz-read')->group(function (): void {
            Route::get('/learn/quizzes/{quiz}/attempts', [AttemptController::class, 'index'])
                ->whereNumber('quiz')->name('api.quiz.attempts.index');
            Route::get('/learn/quiz-attempts/{attempt}', [AttemptController::class, 'show'])
                ->whereNumber('attempt')->name('api.quiz.attempts.show');
        });
        Route::put('/learn/quiz-attempts/{attempt}/answers/{question}', [AnswerController::class, 'update'])
            ->whereNumber(['attempt', 'question'])
            ->middleware('throttle:quiz-answer')
            ->name('api.quiz.answers.update');
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
