<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `role` — chặn thô theo vai trò (ADR-004 §3: middleware `role` chỉ là
 * lớp chặn thô, mọi action vẫn phải gọi `$this->authorize()`).
 *
 * Dùng: `role:admin,quan_ly_trang`.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $allowed = array_map(
            static fn (string $role) => UserRole::tryFrom($role),
            $roles,
        );

        if ($user === null || ! in_array($user->role, $allowed, true)) {
            throw new DomainException(
                code: 'FORBIDDEN',
                message: 'Bạn không có quyền thực hiện thao tác này.',
                status: 403,
            );
        }

        return $next($request);
    }
}
