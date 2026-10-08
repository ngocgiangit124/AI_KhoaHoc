<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `account.active` — tài khoản bị khoá → 403 `ACCOUNT_LOCKED`; đã ẩn danh hoá (xoá tài khoản, T34) → 401 `SESSION_REVOKED`.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status === UserStatus::Locked) {
            throw new DomainException(
                code: 'ACCOUNT_LOCKED',
                message: 'Tài khoản của bạn đã bị khoá.',
                status: 403,
            );
        }

        // T34 (phòng thủ thêm): phiên của tài khoản đã ẩn danh hoá luôn bị huỷ ở pha A; nếu vẫn lọt tới đây thì coi như bị huỷ.
        // `getAttribute` an toàn với model dựng tay chưa nạp đủ cột (strict mode báo thiếu thuộc tính).
        if ($user !== null && ($user->getAttributes()['anonymized_at'] ?? null) !== null) {
            throw new DomainException(
                code: 'SESSION_REVOKED',
                message: 'Tài khoản đã được xoá.',
                status: 401,
            );
        }

        return $next($request);
    }
}
