<?php

namespace App\VideoLab\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API quản lý VideoLab: header `AccessKey` so bằng hash_equals (ADR-002 §3). Lớp thứ hai sau Nginx allow-list
 * (chỉ mạng nội bộ). Thiếu cấu hình khoá → từ chối tất cả.
 */
class AuthenticateAccessKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('videolab.api_key');
        $given = (string) $request->header('AccessKey', '');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
