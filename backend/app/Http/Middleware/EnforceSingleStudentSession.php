<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `student.single_session` — khung middleware cho T05 (ADR-003).
 *
 * T05 sẽ hiện thực: so `current_session_id` của user với session hiện tại,
 * lệch → 401 `SESSION_REPLACED`/`SESSION_EXPIRED`/`SESSION_REVOKED` (đọc từ
 * tombstone). Ở T01/T02, middleware chỉ là pass-through để test kiến trúc
 * (T02) có thể gắn alias này lên mọi route nhóm `student` ngay từ đầu.
 */
class EnforceSingleStudentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
