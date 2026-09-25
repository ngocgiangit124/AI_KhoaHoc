<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/csrf-token — cả 2 host (api-contract §2.1, §2.5).
 *
 * Frontend gọi trước mọi request thay đổi dữ liệu, lấy token và gửi lại qua
 * header `X-CSRF-TOKEN` (ADR-004 §2.2 — không cần đọc cookie XSRF-TOKEN từ JS).
 */
class CsrfController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Sanctum (EnsureFrontendRequestsAreStateful) chỉ gắn StartSession vào
        // pipeline khi Origin/Referer khớp SANCTUM_STATEFUL_DOMAINS. Thiếu/sai
        // Origin (ví dụ gọi trực tiếp bằng curl không header) thì $request
        // không có session — trả lỗi có kiểm soát (400), không để crash 500 (R3).
        if (! $request->hasSession()) {
            throw new DomainException(
                code: 'ORIGIN_NOT_ALLOWED',
                message: 'Không thể khởi tạo phiên CSRF cho nguồn gọi này.',
                status: 400,
            );
        }

        $request->session()->start();

        return response()
            ->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, private');
    }
}
