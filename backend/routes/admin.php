<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\MeController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentRequestController;
use App\Http\Controllers\Api\V1\Admin\SubjectController;
use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Models\Subject;
use Illuminate\Support\Facades\Route;

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

        // T28 (api-contract §2.5) — đăng nhập quản trị. Các route dưới đây
        // KHÔNG nằm trong nhóm `staff` đầy đủ (bên dưới): đây chính là các
        // "lối vào" của luồng đăng nhập (guest, hoặc auth:sanctum nhưng CHƯA
        // qua MFA/đổi mật khẩu). `mfa/verify` tự đòi `staff.mfa_passed` sẽ tự
        // khoá chính lối thoát duy nhất nên KHÔNG có; `password.update` (R1,
        // review-T28.md) VẪN đòi `staff.mfa_passed` (chỉ miễn
        // `staff.password_fresh` — lối thoát duy nhất khỏi
        // `must_change_password`). Test kiến trúc
        // `tests/Feature/T02/RouteMiddlewareGroupsTest.php`
        // (`VV_ADMIN_PUBLIC_ROUTE_NAMES`, `VV_ADMIN_MFA_EXEMPT_ROUTE_NAMES`,
        // `VV_ADMIN_PASSWORD_FRESH_EXEMPT_ROUTE_NAMES`) khẳng định đúng danh
        // sách allowlist theo TÊN route này (2 allowlist TÁCH RIÊNG cho 2
        // middleware — không gộp chung như trước R1).
        Route::post('/admin/auth/login', [LoginController::class, 'store'])
            ->middleware(['guest', 'throttle:login'])
            ->name('admin.auth.login');

        // `auth:sanctum` (đã đăng nhập, đang chờ MFA) + `staff.session` (đồng
        // bộ với các route khác đã đăng nhập — vô hại ở bước này, chưa từng
        // đổi mật khẩu) + `account.active`/`staff.idle`/`no_store` (lưới an
        // toàn chuẩn cho MỌI route auth:sanctum trên host này — S19, xem
        // `RouteMiddlewareGroupsTest`). KHÔNG có `staff.mfa_passed`/
        // `staff.password_fresh` (chính route để ĐẠT được `mfa_passed`) và
        // KHÔNG có `role:...` (route public theo M1 — GV gọi nhầm route này
        // chỉ nhận lỗi mã OTP không đúng, không rò rỉ gì thêm).
        Route::post('/admin/auth/mfa/verify', [MfaController::class, 'verify'])
            ->middleware([
                'auth:sanctum',
                'staff.session',
                'account.active',
                'staff.idle',
                'no_store',
                'throttle:otp-verify',
            ])
            ->name('admin.auth.mfa.verify');

        // R1 (review-T28.md, [BLOCKER]) — CÓ `staff.mfa_passed`: nếu KHÔNG,
        // ai đó chỉ cần biết đúng `current_password` của 1 tài khoản
        // admin/QLT (lộ qua phishing/dò được/dùng lại mật khẩu đã rò rỉ nơi
        // khác) có thể đăng nhập, nhận `mfa_required: true` (KHÔNG cần nhập
        // mã OTP), rồi gọi thẳng route này để đổi mật khẩu — vô hiệu hoá hoàn
        // toàn tác dụng của MFA (ADR-004 §3: "Admin/QLT phải nhập OTP MỖI
        // LẦN đăng nhập" trước khi làm bất kỳ hành động nào). Yêu cầu MFA ở
        // đây KHÔNG chặn đường hợp lệ nào: GV không cần MFA
        // (`EnsureStaffMfaPassed` tự bỏ qua theo vai trò) nên vẫn đổi được
        // ngay sau đăng nhập; Admin/QLT đã qua MFA (luồng bình thường) cũng
        // không bị ảnh hưởng — chỉ đóng đúng lỗ hổng "biết mật khẩu, chưa
        // qua MFA, vẫn đổi được mật khẩu".
        //
        // KHÔNG có `staff.password_fresh` (chính route để thoát khỏi
        // `must_change_password` — nếu có sẽ tự khoá lối thoát duy nhất). Có
        // `role:...` (M1) vì đây KHÔNG phải route công khai — phải đã đăng
        // nhập với vai trò staff.
        Route::put('/admin/auth/password', [PasswordController::class, 'update'])
            ->middleware([
                'auth:sanctum',
                'staff.session',
                'account.active',
                'staff.idle',
                'staff.mfa_passed',
                'no_store',
                'role:admin,quan_ly_trang,giao_vien',
            ])
            ->name('admin.auth.password.update');

        // Ngoại lệ duy nhất (api-contract §1.3): chỉ auth:sanctum (+ role —
        // M1, bắt buộc vì `vvAdminRouteViolations()` không có ngoại lệ riêng
        // cho URI `auth/logout` như bên host api).
        Route::post('/admin/auth/logout', [LoginController::class, 'destroy'])
            ->middleware(['auth:sanctum', 'role:admin,quan_ly_trang,giao_vien'])
            ->name('admin.auth.logout');

        // M1 (review bảo mật T01/T02) — khung nhóm route quản trị chuẩn: MỌI
        // route cần đăng nhập trên admin-api nằm trong nhóm dưới đây (đủ
        // auth:sanctum + role, khớp test kiến trúc `RouteMiddlewareGroupsTest`).
        // T28 hiện thực đầy đủ `staff.mfa_passed`/`staff.password_fresh`
        // (trước đó là pass-through). Mọi route con ĐẶT TÊN với tiền tố
        // `admin.` (yêu cầu của test kiến trúc).
        Route::middleware([
            'auth:sanctum',
            'staff.session',
            'account.active',
            'staff.idle',
            'staff.mfa_passed',
            'staff.password_fresh',
            'no_store',
            'role:admin,quan_ly_trang,giao_vien',
        ])->group(function (): void {
            Route::get('/admin/auth/me', MeController::class)->name('admin.auth.me');

            // Nội dung & danh mục — Chuyên đề (T06, US-011). `can:...` chạy
            // TRƯỚC FormRequest::rules() (SubstituteBindings có priority mặc
            // định của framework đứng trước Authorize — Kernel::$middlewarePriority
            // — nên route model binding {subject} đã sẵn sàng khi middleware
            // `can:` đọc nó): Giáo Viên gửi payload sai (tên rỗng/trùng/HTML)
            // vẫn nhận 403 thay vì 422 lộ chi tiết validate (R2 review-T06,
            // US-011 "Trường hợp biên & lỗi"). `FormRequest::authorize()` vẫn
            // giữ `true` theo đúng quy ước dự án (api-contract §1.3).
            Route::get('/subjects', [SubjectController::class, 'index'])->name('admin.subjects.index');
            Route::post('/subjects', [SubjectController::class, 'store'])
                ->middleware('can:create,'.Subject::class)
                ->name('admin.subjects.store');
            Route::put('/subjects/{subject}', [SubjectController::class, 'update'])
                ->middleware('can:update,subject')
                ->name('admin.subjects.update');
            Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])
                ->middleware('can:delete,subject')
                ->name('admin.subjects.destroy');
            Route::patch('/subjects/{subject}/status', [SubjectController::class, 'updateStatus'])
                ->middleware('can:updateStatus,subject')
                ->name('admin.subjects.status');

            // T14 (US-012, api-contract §2.5) — duyệt đăng ký khóa miễn phí.
            // `index` tự authorize trong controller (cần course_id ĐÃ
            // validate — không đặt được ở middleware `can:` route, giống
            // `SubjectController::index`); `approve`/`reject` dùng
            // `can:decide,enrollment` (mẫu T06).
            Route::get('/enrollment-requests', [EnrollmentRequestController::class, 'index'])
                ->name('admin.enrollment-requests.index');
            Route::post('/enrollment-requests/{enrollment}/approve', [EnrollmentRequestController::class, 'approve'])
                ->middleware('can:decide,enrollment')
                ->name('admin.enrollment-requests.approve');
            Route::post('/enrollment-requests/{enrollment}/reject', [EnrollmentRequestController::class, 'reject'])
                ->middleware('can:decide,enrollment')
                ->name('admin.enrollment-requests.reject');

            // Khóa học/chương/bài (T08+), mã giảm giá/đơn hàng (T15, T24) —
            // thêm dần ở các task sau.
        });
    });
