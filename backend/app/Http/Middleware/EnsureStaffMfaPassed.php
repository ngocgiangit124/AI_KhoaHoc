<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.mfa_passed` (T28 — ADR-004 §3 S15, api-contract §2.5).
 *
 * Chỉ Admin/Quản lý trang cần MFA (Giáo Viên không bắt buộc — README §3.2).
 * `session('staff_mfa_passed')` được đặt bởi `Admin\Auth\LoginController`
 * (`true` ngay khi không cần MFA — GV, hoặc flag `features.staff_mfa` tắt) và
 * `Admin\Auth\MfaController` (sau khi xác thực đúng mã).
 */
class EnsureStaffMfaPassed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->requiresMfa($user->role)) {
            return $next($request);
        }

        if ($request->session()->get('staff_mfa_passed') !== true) {
            throw new DomainException(
                code: 'MFA_REQUIRED',
                message: 'Vui lòng xác thực mã OTP trước khi tiếp tục.',
                status: 403,
            );
        }

        return $next($request);
    }

    private function requiresMfa(UserRole $role): bool
    {
        if (! (bool) config('features.staff_mfa')) {
            return false;
        }

        return $role === UserRole::Admin || $role === UserRole::PageManager;
    }
}
