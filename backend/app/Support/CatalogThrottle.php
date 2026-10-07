<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Limiter `catalog` (ADR-004 §2.8):
 * - không token / token sai: 120/phút theo IP kết nối, bỏ qua `X-Client-IP` (giả được);
 * - token đúng + `X-Client-IP` là IP hợp lệ: 120/phút theo IP khách đó + chung trần `ssr-total`;
 * - token đúng + thiếu/sai định dạng `X-Client-IP`: CHỈ trần `ssr-total` (không rơi về IP của máy Next,
 *   vì mọi khách dùng chung IP đó). Chống cào theo IP khách ở trường hợp này do Nginx `limit_req` đảm nhiệm.
 * Không đọc X-Forwarded-For.
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

        $total = Limit::perMinute((int) config('internal.catalog_ssr_total_per_minute'))->by('ssr-total');
        $clientIp = filter_var((string) $request->header(self::CLIENT_IP_HEADER), FILTER_VALIDATE_IP);

        if ($clientIp === false) {
            return $total;
        }

        return [Limit::perMinute($perIp)->by('ssr-client:'.$clientIp), $total];
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
