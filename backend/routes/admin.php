<?php

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

        // M1 (review bảo mật T01/T02) — khung nhóm route quản trị chuẩn: MỌI
        // route cần đăng nhập trên admin-api nằm trong nhóm dưới đây (đủ
        // auth:sanctum + role, khớp test kiến trúc `RouteMiddlewareGroupsTest`).
        // `staff.mfa_passed`/`staff.password_fresh` là pass-through cho tới T28
        // (đăng nhập quản trị) — T02 đã tạo khung. Mọi route con ĐẶT TÊN với
        // tiền tố `admin.` (yêu cầu của test kiến trúc).
        Route::middleware([
            'auth:sanctum',
            'account.active',
            'staff.idle',
            'staff.mfa_passed',
            'staff.password_fresh',
            'no_store',
            'role:admin,quan_ly_trang,giao_vien',
        ])->group(function (): void {
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

            // Đăng nhập quản trị (T28), khóa học/chương/bài (T08+), mã giảm
            // giá/đơn hàng (T15, T24) — thêm dần ở các task sau.
        });
    });
