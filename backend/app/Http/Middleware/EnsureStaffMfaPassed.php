<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.mfa_passed` — khung middleware cho T28 (đăng nhập quản trị).
 *
 * T28 sẽ hiện thực: admin/quản lý trang chưa `session('mfa_passed')` → 403
 * `MFA_REQUIRED`. Ở T01/T02, middleware chỉ là pass-through.
 */
class EnsureStaffMfaPassed
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
