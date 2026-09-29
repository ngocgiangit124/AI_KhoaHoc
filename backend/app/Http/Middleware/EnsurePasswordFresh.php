<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.password_fresh` (T28 — ADR-004 §3, api-contract §2.5):
 * `must_change_password=true` → 403 `PASSWORD_CHANGE_REQUIRED` cho MỌI route
 * khác ngoài `PUT /admin/auth/password` (route đó không mang middleware này —
 * xem `routes/admin.php` — nếu không sẽ tự khoá chính lối thoát duy nhất).
 */
class EnsurePasswordFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            throw new DomainException(
                code: 'PASSWORD_CHANGE_REQUIRED',
                message: 'Vui lòng đổi mật khẩu trước khi tiếp tục.',
                status: 403,
            );
        }

        return $next($request);
    }
}
