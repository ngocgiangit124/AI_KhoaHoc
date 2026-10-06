<?php

namespace App\VideoLab\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS riêng cho VideoLab (không dùng HandleCors của app, ADR-002 §3a.6), không credentials.
 * - `tus`: origin = ADMIN_URL; headers TUS + chữ ký.
 * - `cdn`: origin = FRONTEND_URL + ADMIN_URL; chỉ GET/HEAD + Range.
 * Origin không nằm trong danh sách: preflight → 403; request thường không được thêm header CORS.
 */
class VideoLabCors
{
    public function handle(Request $request, Closure $next, string $profile = 'tus'): Response
    {
        $origin = (string) $request->headers->get('Origin', '');
        $allowed = $this->origins($profile);
        $ok = $origin !== '' && in_array(rtrim($origin, '/'), $allowed, true);

        if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')) {
            if (! $ok) {
                return response('', 403);
            }

            $response = response('', 204);
            $response->headers->set('Access-Control-Allow-Methods', $profile === 'tus' ? 'POST, HEAD, PATCH, OPTIONS' : 'GET, HEAD, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', $this->allowedHeaders($profile));
            $response->headers->set('Access-Control-Max-Age', '600');
        } else {
            /** @var Response $response */
            $response = $next($request);
        }

        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Origin', ', '));

        if ($ok) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Expose-Headers', $profile === 'tus'
                ? 'Location, Upload-Offset, Upload-Length, Tus-Resumable, Tus-Version, Tus-Max-Size, Tus-Extension'
                : 'Content-Length, Content-Range, Accept-Ranges');
        }

        return $response;
    }

    /** @return list<string> */
    private function origins(string $profile): array
    {
        $list = $profile === 'tus'
            ? [config('app.admin_url')]
            : [config('app.frontend_url'), config('app.admin_url')];

        return array_values(array_filter(array_map(
            static fn ($o) => is_string($o) && $o !== '' ? rtrim($o, '/') : null,
            $list
        )));
    }

    private function allowedHeaders(string $profile): string
    {
        return $profile === 'tus'
            ? 'Tus-Resumable, Upload-Length, Upload-Offset, Upload-Metadata, AuthorizationSignature, AuthorizationExpire, VideoId, LibraryId, Content-Type'
            : 'Range, Content-Type';
    }
}
