<?php

use App\Http\Controllers\Api\V1\Admin\SubjectController;
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
        Route::get('/csrf-token', CsrfController::class);

        // Nhóm `staff` (api-contract §1.3). `staff.mfa_passed`/`staff.password_fresh`
        // là pass-through cho tới T28 (đăng nhập quản trị) — T02 đã tạo khung.
        Route::middleware([
            'auth:sanctum',
            'account.active',
            'staff.idle',
            'staff.mfa_passed',
            'staff.password_fresh',
            'no_store',
            'role:admin,quan_ly_trang,giao_vien',
        ])->group(function (): void {
            // Nội dung & danh mục — Chuyên đề (T06, US-011).
            Route::get('/subjects', [SubjectController::class, 'index']);
            Route::post('/subjects', [SubjectController::class, 'store']);
            Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
            Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);
            Route::patch('/subjects/{subject}/status', [SubjectController::class, 'updateStatus']);

            // Đăng nhập quản trị (T28), khóa học/chương/bài (T08+), mã giảm
            // giá/đơn hàng (T15, T24) — thêm dần ở các task sau.
        });
    });
