<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Services\Auth\StudentSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `student.single_session` (ADR-003): học sinh chỉ có 1 phiên hiện hành.
 *
 * Không có nhánh "nhận nuôi": id khác `current_session_id` (kể cả `logged_out`/NULL) luôn bị từ chối.
 * `X-Device-Id` chỉ chọn thông điệp.
 */
class EnforceSingleStudentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->role !== UserRole::Student || ! $request->hasSession()) {
            return $next($request);
        }

        if ($user->current_session_id === $request->session()->getId()) {
            return $next($request);
        }

        $device = StudentSessionService::deviceIdFromRequest($request);
        $sameDevice = $device !== null && $device === $user->current_device_id;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($sameDevice) {
            throw new DomainException('SESSION_EXPIRED', 'Phiên đăng nhập đã hết hiệu lực, vui lòng đăng nhập lại.', 401);
        }

        throw new DomainException('SESSION_REPLACED', 'Tài khoản của bạn đã đăng nhập ở thiết bị khác. Nếu không phải bạn, hãy đổi mật khẩu ngay.', 401);
    }
}
