<?php

use App\Http\Controllers\Api\V1\Auth\CsrfController;
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

        // M1 (review bảo mật T01/T02) — khung nhóm route quản trị chuẩn cho T28
        // dùng ngay khi hiện thực đăng nhập/route nội dung: MỌI route cần đăng
        // nhập trên admin-api phải nằm trong nhóm dưới đây (đủ auth:sanctum +
        // role, khớp test kiến trúc `RouteMiddlewareGroupsTest`). Để trống vì
        // T01/T02 chưa có route nào cần đăng nhập; KHÔNG registration rỗng thật
        // (Route::group không có route con là vô hại nhưng thừa) — chỉ ghi mẫu:
        //
        // Route::middleware([
        //     'auth:sanctum', 'account.active', 'staff.idle',
        //     'staff.mfa_passed', 'staff.password_fresh', 'no_store',
        //     'role:admin,quan_ly_trang,giao_vien',
        // ])->group(function (): void {
        //     // Đăng nhập quản trị (T28), nội dung/danh mục (T06+), mã giảm giá/
        //     // đơn hàng (T15, T24) — thêm dần ở các task sau, ĐẶT TÊN route với
        //     // tiền tố `admin.` và cập nhật allowlist trong
        //     // tests/Feature/T02/RouteMiddlewareGroupsTest.php nếu route đó cần
        //     // ngoại lệ (đăng nhập, MFA, đổi mật khẩu lần đầu).
        // });
    });
