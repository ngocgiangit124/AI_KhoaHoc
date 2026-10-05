<?php

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\Auth\LoginController as StaffLoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\MeController as StaffMeController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaController as StaffMfaController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController as StaffPasswordController;
use App\Http\Controllers\Api\V1\Admin\ChapterController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CourseController;
use App\Http\Controllers\Api\V1\Admin\CoursePublicationController;
use App\Http\Controllers\Api\V1\Admin\CourseTeacherController;
use App\Http\Controllers\Api\V1\Admin\CurriculumOrderController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentRequestController;
use App\Http\Controllers\Api\V1\Admin\LessonController;
use App\Http\Controllers\Api\V1\Admin\LessonVideoUploadController;
use App\Http\Controllers\Api\V1\Admin\QuizController;
use App\Http\Controllers\Api\V1\Admin\QuizQuestionController;
use App\Http\Controllers\Api\V1\Admin\StaffAccountController;
use App\Http\Controllers\Api\V1\Admin\SubjectController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Auth\CsrfController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession as SanctumAuthenticateSession;

/*
|--------------------------------------------------------------------------
| Host admin-api (quản trị) — ADR-004 §2.1, api-contract §2.5
|--------------------------------------------------------------------------
|
| Route quản trị CHỈ tồn tại trên host này. Đã có middleware 'api' áp dụng
| từ bootstrap/app.php (Route::middleware('api')->group(...)); ở đây bọc
| thêm Route::domain() + prefix để có /api/v1/... và `admin.origin` cho
| toàn bộ nhóm (S6).
*/

Route::domain(config('app.admin_api_host'))
    ->prefix('api/v1')
    ->middleware('admin.origin')
    ->group(function (): void {
        // M3 (review bảo mật T01/T02) — limiter `csrf` riêng (30/phút/IP mặc định,
        // xem AppServiceProvider::configureRateLimiters()), tránh client ngoài
        // trình duyệt tạo phiên Redis không giới hạn.
        Route::get('/csrf-token', CsrfController::class)
            ->middleware('throttle:csrf')
            ->name('admin.csrf-token');

        // Đăng nhập quản trị (T28, api-contract §2.5). KHÔNG dùng `guest`: người đang giữ phiên cũ
        // (hết hạn idle, đang chờ MFA, vừa bị khoá) vẫn đăng nhập lại được; `StaffAuthService` thay
        // phiên cũ bằng phiên mới. Giới hạn sai 10/giờ/tài khoản + 50/giờ/IP nằm trong service.
        Route::post('/admin/auth/login', [StaffLoginController::class, 'store'])
            ->middleware(['throttle:login', 'no_store'])
            ->name('admin.auth.login');

        $staffRoles = 'role:admin,quan_ly_trang,giao_vien';

        // Ngoại lệ nhóm `staff` (api-contract §1.3): logout chỉ cần phiên hợp lệ, để phiên hết hạn/đang
        // chờ MFA/phải đổi mật khẩu vẫn đăng xuất được.
        Route::post('/admin/auth/logout', [StaffLoginController::class, 'destroy'])
            ->middleware(['auth:sanctum', $staffRoles, 'no_store'])
            ->name('admin.auth.logout');

        // Bước giữa: đã qua mật khẩu nhưng chưa qua MFA / chưa đổi mật khẩu lần đầu. Có
        // AuthenticateSession (huỷ phiên khi mật khẩu đổi), `staff.idle`, nhưng KHÔNG có
        // `staff.password_fresh` (và MFA verify/resend không có `staff.mfa_passed`).
        $stepMiddleware = [
            'auth:sanctum', $staffRoles, SanctumAuthenticateSession::class, 'account.active', 'staff.idle',
            'no_store',
        ];

        Route::middleware($stepMiddleware)->group(function (): void {
            Route::post('/admin/auth/mfa/verify', [StaffMfaController::class, 'verify'])
                ->middleware('throttle:otp-verify')
                ->name('admin.auth.mfa.verify');

            Route::post('/admin/auth/mfa/resend', [StaffMfaController::class, 'resend'])
                ->middleware('throttle:otp-send')
                ->name('admin.auth.mfa.resend');

            // Khác contract ban đầu (chỉ `auth:sanctum`): thêm `staff.mfa_passed` để người mới biết mật khẩu
            // (chưa qua MFA) không đổi được mật khẩu của chủ tài khoản.
            Route::put('/admin/auth/password', [StaffPasswordController::class, 'update'])
                ->middleware(['staff.mfa_passed', 'throttle:admin-password'])
                ->name('admin.auth.password.update');
        });

        // Nhóm `staff` chuẩn (api-contract §1.3): MỌI route quản trị cần đăng nhập khác phải nằm trong
        // nhóm này (test kiến trúc RouteMiddlewareGroupsTest). T06+ thêm route vào đây, đặt tên `admin.*`.
        Route::middleware([
            'auth:sanctum', $staffRoles, SanctumAuthenticateSession::class, 'account.active', 'staff.idle',
            'staff.mfa_passed', 'staff.password_fresh', 'no_store',
        ])->group(function (): void {
            Route::get('/admin/auth/me', StaffMeController::class)->name('admin.auth.me');

            // T06 — Chuyên đề (US-011). Quyền theo SubjectPolicy: staff CRUD, giáo viên chỉ xem.
            Route::get('/admin/subjects', [SubjectController::class, 'index'])->name('admin.subjects.index');
            Route::post('/admin/subjects', [SubjectController::class, 'store'])->name('admin.subjects.store');
            Route::put('/admin/subjects/{subject}', [SubjectController::class, 'update'])->name('admin.subjects.update');
            Route::patch('/admin/subjects/{subject}/status', [SubjectController::class, 'updateStatus'])->name('admin.subjects.status');
            Route::delete('/admin/subjects/{subject}', [SubjectController::class, 'destroy'])->name('admin.subjects.destroy');

            // T08 — Khóa học (US-009). Quyền theo CoursePolicy: staff toàn quyền, giáo viên chỉ khóa được gán.
            Route::get('/admin/teachers', [TeacherController::class, 'index'])->name('admin.teachers.index');
            Route::get('/admin/courses', [CourseController::class, 'index'])->name('admin.courses.index');
            Route::post('/admin/courses', [CourseController::class, 'store'])->name('admin.courses.store');
            Route::get('/admin/courses/{course}', [CourseController::class, 'show'])->name('admin.courses.show');
            Route::put('/admin/courses/{course}', [CourseController::class, 'update'])->name('admin.courses.update');
            Route::delete('/admin/courses/{course}', [CourseController::class, 'destroy'])->name('admin.courses.destroy');
            Route::post('/admin/courses/{course}/publish', [CoursePublicationController::class, 'publish'])->name('admin.courses.publish');
            Route::post('/admin/courses/{course}/unpublish', [CoursePublicationController::class, 'unpublish'])->name('admin.courses.unpublish');
            Route::patch('/admin/courses/{course}/manual-order', [CourseController::class, 'updateManualOrder'])->name('admin.courses.manual-order');
            Route::put('/admin/courses/{course}/teachers', [CourseTeacherController::class, 'update'])->name('admin.courses.teachers');

            // T09 — Chương/bài (US-009 AC8, AC11). scopeBindings: chương phải thuộc khóa, bài phải thuộc chương
            // (sai → 404); quyền `manageContent` theo khóa (giáo viên chỉ khóa được gán).
            Route::scopeBindings()->group(function (): void {
                Route::get('/admin/courses/{course}/chapters', [ChapterController::class, 'index'])->name('admin.chapters.index');
                Route::post('/admin/courses/{course}/chapters', [ChapterController::class, 'store'])->name('admin.chapters.store');
                Route::put('/admin/courses/{course}/chapters/{chapter}', [ChapterController::class, 'update'])->name('admin.chapters.update');
                Route::delete('/admin/courses/{course}/chapters/{chapter}', [ChapterController::class, 'destroy'])->name('admin.chapters.destroy');
                Route::put('/admin/courses/{course}/curriculum/order', [CurriculumOrderController::class, 'update'])->name('admin.curriculum.order');
                Route::post('/admin/courses/{course}/chapters/{chapter}/lessons', [LessonController::class, 'store'])->name('admin.lessons.store');
                Route::put('/admin/courses/{course}/chapters/{chapter}/lessons/{lesson}', [LessonController::class, 'update'])->name('admin.lessons.update');
                Route::delete('/admin/courses/{course}/chapters/{chapter}/lessons/{lesson}', [LessonController::class, 'destroy'])->name('admin.lessons.destroy');
            });

            // T11 — Upload video cho bài + trạng thái xử lý (US-009 AC11). Quyền `manageContent` theo khóa;
            // scopeBindings: bài phải thuộc khóa (sai/đã xoá → 404). Hạn mức 20 GB/ngày kiểm ở service.
            Route::scopeBindings()->group(function (): void {
                Route::post('/admin/courses/{course}/lessons/{lesson}/video-uploads', [LessonVideoUploadController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('admin.lessons.video-uploads.store');
                Route::get('/admin/courses/{course}/lessons/{lesson}/video', [LessonVideoUploadController::class, 'show'])
                    ->name('admin.lessons.video.show');
            });

            // T21 — Soạn quiz (US-007/US-009). scopeBindings: quiz phải thuộc khóa, câu hỏi phải thuộc quiz (sai/đã xoá →
            // 404); quyền `manageContent` theo khóa. Đáp án đúng chỉ xuất hiện ở các route admin-api này.
            Route::scopeBindings()->group(function (): void {
                Route::get('/admin/courses/{course}/quizzes', [QuizController::class, 'index'])->name('admin.quizzes.index');
                Route::post('/admin/courses/{course}/quizzes', [QuizController::class, 'store'])->name('admin.quizzes.store');
                Route::get('/admin/courses/{course}/quizzes/{quiz}', [QuizController::class, 'show'])->name('admin.quizzes.show');
                Route::put('/admin/courses/{course}/quizzes/{quiz}', [QuizController::class, 'update'])->name('admin.quizzes.update');
                Route::delete('/admin/courses/{course}/quizzes/{quiz}', [QuizController::class, 'destroy'])->name('admin.quizzes.destroy');
                Route::get('/admin/courses/{course}/quizzes/{quiz}/questions', [QuizQuestionController::class, 'index'])->name('admin.quiz-questions.index');
                Route::post('/admin/courses/{course}/quizzes/{quiz}/questions', [QuizQuestionController::class, 'store'])->name('admin.quiz-questions.store');
                Route::get('/admin/courses/{course}/quizzes/{quiz}/questions/{question}', [QuizQuestionController::class, 'show'])->name('admin.quiz-questions.show');
                Route::put('/admin/courses/{course}/quizzes/{quiz}/questions/{question}', [QuizQuestionController::class, 'update'])->name('admin.quiz-questions.update');
                Route::delete('/admin/courses/{course}/quizzes/{quiz}/questions/{question}', [QuizQuestionController::class, 'destroy'])->name('admin.quiz-questions.destroy');
            });

            // T14 — Duyệt đăng ký khóa miễn phí (US-012). Quyền theo EnrollmentPolicy: staff mọi khóa,
            // giáo viên chỉ khóa mình phụ trách.
            Route::get('/admin/enrollment-requests', [EnrollmentRequestController::class, 'index'])->name('admin.enrollment-requests.index');
            Route::post('/admin/enrollment-requests/{enrollment}/approve', [EnrollmentRequestController::class, 'approve'])->name('admin.enrollment-requests.approve');
            Route::post('/admin/enrollment-requests/{enrollment}/reject', [EnrollmentRequestController::class, 'reject'])->name('admin.enrollment-requests.reject');

            // T15 — Mã giảm giá (US-013). Quyền theo CouponPolicy: chỉ staff (admin, quản lý trang).
            Route::get('/admin/coupons', [CouponController::class, 'index'])->name('admin.coupons.index');
            Route::post('/admin/coupons', [CouponController::class, 'store'])->name('admin.coupons.store');
            Route::get('/admin/coupons/{coupon}', [CouponController::class, 'show'])->name('admin.coupons.show');
            Route::put('/admin/coupons/{coupon}', [CouponController::class, 'update'])->name('admin.coupons.update');
            Route::post('/admin/coupons/{coupon}/deactivate', [CouponController::class, 'deactivate'])->name('admin.coupons.deactivate');
            Route::post('/admin/coupons/{coupon}/activate', [CouponController::class, 'activate'])->name('admin.coupons.activate');
            Route::delete('/admin/coupons/{coupon}', [CouponController::class, 'destroy'])->name('admin.coupons.destroy');

            // T33 — Tài khoản staff + nhật ký thao tác (US-016). Chỉ admin (Gate `manage-system`, kiểm trước validate).
            Route::get('/admin/staff', [StaffAccountController::class, 'index'])->name('admin.staff.index');
            Route::post('/admin/staff', [StaffAccountController::class, 'store'])->middleware('throttle:30,1')->name('admin.staff.store');
            Route::get('/admin/staff/{staff}', [StaffAccountController::class, 'show'])->name('admin.staff.show');
            Route::post('/admin/staff/{staff}/lock', [StaffAccountController::class, 'lock'])->middleware('throttle:30,1')->name('admin.staff.lock');
            Route::post('/admin/staff/{staff}/unlock', [StaffAccountController::class, 'unlock'])->middleware('throttle:30,1')->name('admin.staff.unlock');
            Route::patch('/admin/staff/{staff}/role', [StaffAccountController::class, 'updateRole'])->middleware('throttle:30,1')->name('admin.staff.role');
            Route::post('/admin/staff/{staff}/reset-password', [StaffAccountController::class, 'resetPassword'])->middleware('throttle:30,1')->name('admin.staff.reset-password');
            Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
        });
    });
