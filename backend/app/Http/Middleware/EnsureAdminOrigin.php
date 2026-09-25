<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `admin.origin` (ADR-004 §2.2, S6) — mọi route trên host admin-api chỉ
 * chấp nhận request có `Origin` (hoặc `Referer` cho GET) trùng đúng `ADMIN_URL`.
 * Ngăn XSS/CSRF từ origin khác gọi thẳng API quản trị.
 */
class EnsureAdminOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = rtrim((string) config('app.admin_url'), '/');

        $origin = $this->resolveOrigin($request);

        if ($origin === null || rtrim($origin, '/') !== $expected) {
            throw new DomainException(
                code: 'ORIGIN_NOT_ALLOWED',
                message: 'Yêu cầu không được phép từ nguồn này.',
                status: 403,
            );
        }

        return $next($request);
    }

    private function resolveOrigin(Request $request): ?string
    {
        if ($origin = $request->headers->get('Origin')) {
            return $origin;
        }

        // GET không phải lúc nào cũng có header Origin — dùng Referer (S6).
        if ($request->isMethod('GET') && $referer = $request->headers->get('Referer')) {
            return $this->originFromUrl($referer);
        }

        return null;
    }

    private function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
