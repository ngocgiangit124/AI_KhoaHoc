<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `account.verified` (api-contract §1.7 `403 ACCOUNT_NOT_VERIFIED`) —
 * gắn ở các route yêu cầu đã xác thực OTP (checkout — T18, đăng ký học miễn
 * phí — T14...). "Đã xác thực" = đã xác thực ít nhất 1 kênh liên lạc (khớp
 * `is_verified` ở `MeResource`) — T04 chỉ cung cấp middleware, chưa gắn vào
 * route nào (những route đó thuộc các task sau).
 */
class EnsureAccountVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $verified = $user !== null
            && ($user->email_verified_at !== null || $user->phone_verified_at !== null);

        if (! $verified) {
            throw new DomainException(
                code: 'ACCOUNT_NOT_VERIFIED',
                message: 'Vui lòng xác thực tài khoản trước khi tiếp tục.',
                status: 403,
            );
        }

        return $next($request);
    }
}
