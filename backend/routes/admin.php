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
        Route::get('/csrf-token', CsrfController::class);

        // Đăng nhập quản trị (T28), nội dung/danh mục (T06+), mã giảm giá/đơn
        // hàng (T15, T24) — thêm dần ở các task sau.
    });
