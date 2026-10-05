<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.password_fresh` (US-016 BR2): `must_change_password=true` → 403
 * `PASSWORD_CHANGE_REQUIRED`. Chỉ route `PUT /admin/auth/password` (và logout) được miễn.
 */
class EnsurePasswordFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            throw new DomainException(
                code: 'PASSWORD_CHANGE_REQUIRED',
                message: 'Bạn cần đổi mật khẩu trước khi tiếp tục.',
                status: 403,
            );
        }

        return $next($request);
    }
}
