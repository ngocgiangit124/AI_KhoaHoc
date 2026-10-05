<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController as StaffLoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\MeController as StaffMeController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaController as StaffMfaController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController as StaffPasswordController;
use App\Http\Controllers\Api\V1\Admin\SubjectController;
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
        });
    });
