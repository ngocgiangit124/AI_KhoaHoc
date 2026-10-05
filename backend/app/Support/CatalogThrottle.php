<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Limiter `catalog`: request SSR nội bộ (header bí mật đúng) được tính theo IP khách thật; mọi trường hợp khác
 * theo IP kết nối như cũ. Không đọc X-Forwarded-For — chỉ tin `X-Client-IP` khi kèm token đúng.
 */
class CatalogThrottle
{
    public const TOKEN_HEADER = 'X-Internal-Token';

    public const CLIENT_IP_HEADER = 'X-Client-IP';

    /** @return list<Limit>|Limit */
    public static function limits(Request $request): array|Limit
    {
        $perIp = (int) config('internal.catalog_per_minute');

        if (! self::isInternal($request)) {
            return Limit::perMinute($perIp)->by((string) $request->ip());
        }

        $clientIp = filter_var((string) $request->header(self::CLIENT_IP_HEADER), FILTER_VALIDATE_IP);
        $key = $clientIp !== false ? $clientIp : (string) $request->ip();

        return [
            Limit::perMinute($perIp)->by('ssr-client:'.$key),
            Limit::perMinute((int) config('internal.catalog_ssr_total_per_minute'))->by('ssr-total'),
        ];
    }

    public static function isInternal(Request $request): bool
    {
        $expected = config('internal.ssr_token');
        $given = $request->header(self::TOKEN_HEADER);

        if (! is_string($expected) || $expected === '' || ! is_string($given) || $given === '') {
            return false;
        }

        // So hash cùng độ dài để không lộ độ dài/nội dung qua thời gian.
        return hash_equals(hash('sha256', $expected), hash('sha256', $given));
    }
}
