<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `guest` (api-contract §2.2: `POST /auth/register`, `POST /auth/login`)
 * — thay cho `Illuminate\Auth\Middleware\RedirectIfAuthenticated` mặc định
 * (dành cho app Blade, redirect 302 tới route `login` không tồn tại ở API
 * JSON thuần — ADR-004 §1). Đã đăng nhập thì trả lỗi theo envelope chuẩn
 * (api-contract §1.7) thay vì crash `RouteNotFoundException`.
 *
 * Dùng mã lỗi `FORBIDDEN` (403) đã có sẵn trong bảng api-contract §1.7 thay vì
 * bịa mã mới — hợp đồng không định nghĩa riêng cho trường hợp "đã đăng nhập
 * mà còn gọi guest-only". [Câu hỏi cho Architect nếu cần mã riêng biệt hơn.]
 */
class EnsureGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            throw new DomainException(
                code: 'FORBIDDEN',
                message: 'Bạn đã đăng nhập.',
                status: 403,
            );
        }

        return $next($request);
    }
}
