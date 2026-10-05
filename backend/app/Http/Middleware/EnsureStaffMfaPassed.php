<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Services\Auth\Staff\StaffSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.mfa_passed` (S15, US-016 BR3): admin/quản lý trang chưa nhập OTP MFA trong phiên này
 * → 403 `MFA_REQUIRED`. Giáo viên, hoặc `FEATURE_STAFF_MFA=false` → cho qua.
 */
class EnsureStaffMfaPassed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && StaffSession::requiresMfa($user)
            && (! $request->hasSession() || ! StaffSession::mfaPassed($request->session()))) {
            throw new DomainException(
                code: 'MFA_REQUIRED',
                message: 'Vui lòng nhập mã xác thực được gửi tới email của bạn.',
                status: 403,
            );
        }

        return $next($request);
    }
}
