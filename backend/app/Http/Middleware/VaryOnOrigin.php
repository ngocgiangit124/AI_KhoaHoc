<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route công khai cache được: header CORS thay đổi theo `Origin` nên response phải có `Vary: Origin`
 * để cache dùng chung (CDN/Nginx) không trả nhầm ACAO của origin này cho origin khác. Không bao giờ thêm Cookie.
 */
class VaryOnOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $response->setVary('Origin', false);

        return $response;
    }
}
