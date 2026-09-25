<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.password_fresh` — khung middleware cho T28 (đăng nhập quản trị).
 *
 * T28 sẽ hiện thực: `must_change_password=true` → 403 `PASSWORD_CHANGE_REQUIRED`
 * (trừ chính route `PUT /admin/auth/password`). Ở T01/T02, middleware chỉ là
 * pass-through.
 */
class EnsurePasswordFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
