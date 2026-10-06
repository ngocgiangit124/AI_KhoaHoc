<?php

use App\VideoLab\Http\Controllers\CdnController;
use App\VideoLab\Http\Controllers\ManagementController;
use App\VideoLab\Http\Controllers\TusController;
use App\VideoLab\Http\Middleware\AuthenticateAccessKey;
use App\VideoLab\Http\Middleware\VideoLabCors;
use App\VideoLab\Models\Video;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VideoLab (host video.*) — ADR-002 §3, §3a.6, api-contract §2.7
|--------------------------------------------------------------------------
|
| KHÔNG dùng nhóm `web`/`api` (không session/CSRF/Sanctum). Chỉ /videolab/tus* và /videolab/cdn/* mở ra Internet;
| /videolab/library/* chỉ mạng nội bộ (Nginx allow-list + AccessKey). Đăng ký bởi VideoLabServiceProvider khi bật.
*/

Route::domain((string) config('videolab.host'))->prefix('videolab')->group(function (): void {
    // API quản lý kiểu Bunny (AccessKey).
    Route::middleware(AuthenticateAccessKey::class)
        ->prefix('library/{libraryId}/videos')
        ->where(['libraryId' => '[A-Za-z0-9_-]{1,50}', 'guid' => Video::GUID_PATTERN])
        ->group(function (): void {
            Route::post('/', [ManagementController::class, 'store']);
            Route::get('/{guid}', [ManagementController::class, 'show']);
            Route::delete('/{guid}', [ManagementController::class, 'destroy']);
        });

    // TUS (Creation + Core), CORS riêng theo ADMIN_URL.
    Route::middleware([VideoLabCors::class.':tus', 'throttle:600,1'])
        ->prefix('tus')
        ->where(['guid' => Video::GUID_PATTERN])
        ->group(function (): void {
            Route::options('/', [TusController::class, 'options']);
            Route::post('/', [TusController::class, 'create']);
            Route::options('/{guid}', [TusController::class, 'options']);
            Route::match(['HEAD'], '/{guid}', [TusController::class, 'head']);
            Route::patch('/{guid}', [TusController::class, 'patch']);
        });

    // Phát HLS có token. `guid`/`path` ràng buộc chặt (ADR-002 §3a.4): `..`, `%2e%2e`, đường dẫn tuyệt đối → không khớp → 404.
    // throttle theo IP (1 phút video ≈ 10 segment, nhiều người cùng IP): production thêm limit_req ở Nginx.
    Route::middleware([VideoLabCors::class.':cdn', 'throttle:1200,1'])
        ->prefix('cdn')
        ->group(function (): void {
            $constraints = [
                'token' => '[A-Za-z0-9_-]{43}',
                'expires' => '[0-9]{10}',
                'guid' => Video::GUID_PATTERN,
                'path' => 'playlist\.m3u8|[0-9]{3,4}p/[A-Za-z0-9_]{1,40}\.(m3u8|ts)',
            ];

            Route::options('/{token}/{expires}/{guid}/{path}', fn () => response('', 204))->where($constraints);
            Route::get('/{token}/{expires}/{guid}/{path}', [CdnController::class, 'show'])->where($constraints);
        });
});
