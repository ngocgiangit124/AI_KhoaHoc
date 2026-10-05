<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Services\Auth\Staff\StaffSession;
use App\Services\Auth\Staff\StaffSessionRevoker;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.idle` (ADR-004 §2.2, api-contract §1.7): phiên quản trị quá 120 phút không hoạt động
 * hoặc quá 12 giờ kể từ đăng nhập → 401 `STAFF_IDLE_TIMEOUT`, đồng thời huỷ session (request kế
 * tiếp nhận 401 `UNAUTHENTICATED`).
 *
 * Fail-closed: đã đăng nhập mà thiếu mốc thời gian trong session (không phải do luồng đăng nhập
 * quản trị tạo ra) bị coi là hết hạn. Test dùng `vvActAsStaff()` (tests/Feature/T28/helpers.php).
 */
class StaffIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();

        $loginAt = $session->get(StaffSession::LOGIN_AT);
        $lastActivity = $session->get(StaffSession::LAST_ACTIVITY);

        $idleExpired = ! is_int($lastActivity)
            || $now - $lastActivity > (int) config('auth.staff.idle_minutes') * 60;
        $absoluteExpired = ! is_int($loginAt)
            || $now - $loginAt > (int) config('auth.staff.absolute_hours') * 3600;

        if ($idleExpired || $absoluteExpired) {
            Auth::guard('web')->logout();
            $session->invalidate();

            throw new DomainException(
                code: 'STAFF_IDLE_TIMEOUT',
                message: $idleExpired
                    ? 'Phiên làm việc đã hết hạn do không hoạt động, vui lòng đăng nhập lại.'
                    : 'Phiên làm việc đã quá thời hạn tối đa, vui lòng đăng nhập lại.',
                status: 401,
            );
        }

        // T33: tài khoản bị khoá/đổi vai trò/đặt lại mật khẩu sau khi phiên này được cấp → huỷ phiên.
        if ((int) $session->get(StaffSession::REVOKE_VERSION, 0) !== StaffSessionRevoker::version($request->user()->getAuthIdentifier())) {
            Auth::guard('web')->logout();
            $session->invalidate();

            throw new AuthenticationException;
        }

        $session->put(StaffSession::LAST_ACTIVITY, $now);

        return $next($request);
    }
}
