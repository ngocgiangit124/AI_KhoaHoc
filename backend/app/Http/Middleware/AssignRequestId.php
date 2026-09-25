<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gán một request_id duy nhất cho mỗi request, dùng để:
 * - trả trong header `X-Request-Id` (api-contract §1.4);
 * - đính kèm vào body lỗi 5xx;
 * - liên kết log xử lý (đo p95 — DBA #10).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
