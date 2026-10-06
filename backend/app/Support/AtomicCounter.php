<?php

namespace App\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bộ đếm lượt đăng nhập sai NGUYÊN TỬ trên store của limiter (`config('cache.limiter')`).
 *
 * Production dùng Redis: `hit` là 1 script Lua (INCR + EXPIRE khi giá trị = 1), `release` là GET+DECR trong 1 script,
 * nên request đồng thời không bao giờ ghi đè lượt của nhau. Không dùng `RateLimiter::hit()`: hàm của framework có
 * nhánh `put($key, 1)` ghi đè các lượt INCR đến cùng lúc (QA BUG-1, minor-fixes-2).
 *
 * Store khác Redis (`array` trong test thường, 1 tiến trình): dự phòng qua `RateLimiter`, KHÔNG nguyên tử giữa nhiều
 * tiến trình và chỉ dùng cho test/local. Tên khoá giống nhau ở cả hai nhánh nên `RateLimiter::attempts()/clear()` vẫn đọc/xoá được.
 */
final class AtomicCounter
{
    private const HIT = <<<'LUA'
local v = redis.call('INCR', KEYS[1])
if v == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
return v
LUA;

    private const RELEASE = <<<'LUA'
local v = tonumber(redis.call('GET', KEYS[1]))
if v and v > 0 then return redis.call('DECR', KEYS[1]) end
return 0
LUA;

    /** Tăng 1 và trả giá trị mới. */
    public static function hit(string $key, int $decaySeconds): int
    {
        $key = self::clean($key);
        $store = self::redisStore();

        if ($store === null) {
            return RateLimiter::hit($key, $decaySeconds);
        }

        // @phpstan-ignore-next-line (Connection::eval là phương thức Laravel, PHPStan đọc nhầm chữ ký của client Redis thô)
        return (int) self::connection($store)->eval(self::HIT, 1, $store->getPrefix().$key, $decaySeconds);
    }

    /** Giảm 1, không xuống dưới 0. */
    public static function release(string $key, int $decaySeconds): void
    {
        $key = self::clean($key);
        $store = self::redisStore();

        if ($store === null) {
            if (RateLimiter::attempts($key) > 0) {
                RateLimiter::decrement($key, $decaySeconds);
            }

            return;
        }

        // @phpstan-ignore-next-line (như trên)
        self::connection($store)->eval(self::RELEASE, 1, $store->getPrefix().$key);
    }

    /** Đọc (không đổi). */
    public static function attempts(string $key): int
    {
        $key = self::clean($key);

        return (int) RateLimiter::attempts($key);
    }

    /** Số giây còn lại của cửa sổ (tối thiểu 1). */
    public static function availableIn(string $key): int
    {
        $key = self::clean($key);
        $store = self::redisStore();

        if ($store === null) {
            return max(1, RateLimiter::availableIn($key));
        }

        return max(1, (int) self::connection($store)->ttl($store->getPrefix().$key));
    }

    /** `connection()` được PHPStan hiểu là client Redis thô; thực tế là kết nối Laravel (eval(script, numKeys, ...args)). */
    private static function connection(RedisStore $store): Connection
    {
        /** @var Connection */
        return $store->connection();
    }

    /** Cùng cách làm sạch khoá với `RateLimiter` để đọc/ghi/xoá luôn trúng 1 khoá (khoá chứa dữ liệu người dùng). */
    private static function clean(string $key): string
    {
        return preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities($key)) ?? $key;
    }

    private static function redisStore(): ?RedisStore
    {
        $store = Cache::store(config('cache.limiter'))->getStore();

        return $store instanceof RedisStore ? $store : null;
    }
}
