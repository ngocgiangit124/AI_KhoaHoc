<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `no_store` (S16) — mọi response đã xác thực không được cache (kể cả bởi
 * Next.js Data Cache/CDN). Đặt trên mọi route trong nhóm `student`/`staff`
 * (api-contract §1.3), trừ `POST /auth/logout` và `POST /admin/auth/logout`.
 */
class NoStoreForAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Cookie, Origin');

        return $response;
    }
}
