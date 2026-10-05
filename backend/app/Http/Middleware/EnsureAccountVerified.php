<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `account.verified` — chặn checkout/đăng ký học miễn phí khi chưa xác thực OTP
 * (US-001 AC9) → 403 `ACCOUNT_NOT_VERIFIED`.
 */
class EnsureAccountVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isVerified()) {
            throw new DomainException(
                code: 'ACCOUNT_NOT_VERIFIED',
                message: 'Bạn cần xác thực tài khoản trước khi thực hiện thao tác này.',
                status: 403,
            );
        }

        return $next($request);
    }
}
