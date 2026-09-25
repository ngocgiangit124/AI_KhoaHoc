<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.idle` — khung middleware cho T28 (Đăng nhập quản trị).
 *
 * T28 sẽ hiện thực: so `session('staff_last_activity')` với now(), quá 120 phút
 * không hoạt động hoặc quá 12 giờ kể từ đăng nhập → 401 `STAFF_IDLE_TIMEOUT`
 * (ADR-004 §2.2). Ở T01/T02, middleware chỉ là pass-through để test kiến trúc
 * (T02) có thể gắn alias này lên mọi route nhóm `staff` ngay từ đầu.
 */
class StaffIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
