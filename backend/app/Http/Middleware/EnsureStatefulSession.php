<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `stateful` (L1 — review docs/security/review-T03-FW1.md) — đặt TRƯỚC
 * `guest`/`throttle` trên `/auth/register`, `/auth/login`, `/auth/logout`
 * (host api). Request không "stateful" (thiếu Origin/Referer khớp
 * `SANCTUM_STATEFUL_DOMAINS` — xem `EnsureFrontendRequestsAreStateful`) thì
 * không có session được gắn vào pipeline; trước khi có middleware này,
 * Service vẫn chạy hết (kể cả tạo tài khoản + consents ở `/auth/register`)
 * rồi mới vỡ ở `$request->session()->regenerate()` → 500 `INTERNAL_ERROR`,
 * và với `/auth/register` thì DỮ LIỆU ĐÃ ĐƯỢC GHI trước khi lỗi.
 *
 * Cùng cách CsrfController xử lý thiếu Origin (R3, T01) — 400 `ORIGIN_NOT_ALLOWED`
 * ngay, không chạm Service/DB.
 */
class EnsureStatefulSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new DomainException(
                code: 'ORIGIN_NOT_ALLOWED',
                message: 'Không thể xử lý yêu cầu từ nguồn gọi này.',
                status: 400,
            );
        }

        return $next($request);
    }
}
