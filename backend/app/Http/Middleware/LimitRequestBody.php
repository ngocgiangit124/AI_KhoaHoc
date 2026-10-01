<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `body.limit:{KB}` — trần kích thước body TRƯỚC khi FormRequest mở
 * rộng wildcard / chạy rule (T21, S8). Nginx (`client_max_body_size` 5 MB
 * toàn cục) là chốt đầu tiên; đây là chốt trong ứng dụng cho các route soạn
 * nội dung, nơi body hợp lệ chỉ vài KB. Kiểm cả `Content-Length` khai báo
 * lẫn độ dài thực của body (chunked không có Content-Length).
 *
 * Vượt trần → 413 `PAYLOAD_TOO_LARGE` (envelope chuẩn ở `ApiExceptionRenderer`).
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, string $kilobytes): Response
    {
        $limit = max(1, (int) $kilobytes) * 1024;

        $declared = $request->headers->get('Content-Length');

        if ($declared !== null && ctype_digit($declared) && (int) $declared > $limit) {
            abort(413);
        }

        if (strlen($request->getContent()) > $limit) {
            abort(413);
        }

        return $next($request);
    }
}
