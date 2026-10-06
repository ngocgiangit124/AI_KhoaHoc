<?php

namespace App\VideoLab\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Lỗi VideoLab luôn là JSON (không HTML/redirect), kể cả khi client TUS không gửi Accept. */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
