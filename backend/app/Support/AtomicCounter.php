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

    private const ADD = <<<'LUA'
local v = redis.call('INCRBY', KEYS[1], ARGV[1])
if redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
return v
LUA;

    /** Tất-cả-hoặc-không: có khoá nào đã >= trần thì KHÔNG cộng khoá nào; trả {0, vị trí khoá bị chặn} hoặc {1, số mới...}. */
    // Redis Cluster: các khoá của 1 lần gọi phải cùng hash slot (CROSSSLOT) — xem docs/ops/production-checklist.md §4 (GL-A2/V2-5).
    private const HIT_ALL = <<<'LUA'
for i = 1, #KEYS do
  local v = tonumber(redis.call('GET', KEYS[i])) or 0
  if v >= tonumber(ARGV[i + 1]) then return {0, i} end
end
local out = {1}
for i = 1, #KEYS do
  local v = redis.call('INCR', KEYS[i])
  if v == 1 then redis.call('EXPIRE', KEYS[i], ARGV[1]) end
  out[#out + 1] = v
end
return out
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

    /**
     * GL-A2 (S1/R3): cộng 1 vào MỌI khoá theo kiểu tất-cả-hoặc-không. Khoá nào đã đạt trần (`>= max`, kiểm TRƯỚC khi cộng)
     * thì không khoá nào bị cộng (request bị chặn không để lại lượt ở khoá nào), kể cả khi đua nhiều tiến trình
     * (1 script Lua). Đặt khoá "rộng" (IP) trước khoá tài khoản để IP bị chặn không chạm bộ đếm tài khoản.
     *
     * @param  list<array{0: string, 1: int}>  $limits  [khoá, trần]
     * @return array{blocked: ?string, counts: list<int>} `blocked` = khoá đầu tiên chạm trần (null nếu đã cộng)
     */
    public static function hitAll(array $limits, int $decaySeconds): array
    {
        $store = self::redisStore();

        if ($store === null) {
            foreach ($limits as [$key, $max]) {
                if (self::attempts($key) >= $max) {
                    return ['blocked' => $key, 'counts' => []];
                }
            }

            return ['blocked' => null, 'counts' => array_map(fn (array $l): int => self::hit($l[0], $decaySeconds), $limits)];
        }

        $keys = array_map(fn (array $l): string => $store->getPrefix().self::clean($l[0]), $limits);
        $args = [$decaySeconds, ...array_map(fn (array $l): int => $l[1], $limits)];

        // @phpstan-ignore-next-line (như hit())
        $result = self::connection($store)->eval(self::HIT_ALL, count($keys), ...$keys, ...$args);

        if ((int) $result[0] === 0) {
            return ['blocked' => $limits[(int) $result[1] - 1][0], 'counts' => []];
        }

        return ['blocked' => null, 'counts' => array_map('intval', array_slice($result, 1))];
    }

    /**
     * Cộng `$amount` (có thể âm để hoàn) và trả giá trị mới; cửa sổ `$decaySeconds` bắt đầu từ lần cộng đầu tiên.
     * Store khác Redis (test, 1 tiến trình): dự phòng qua cache của limiter.
     */
    public static function add(string $key, int $amount, int $decaySeconds): int
    {
        $key = self::clean($key);
        $store = self::redisStore();

        if ($store === null) {
            $cache = Cache::store(config('cache.limiter'));
            $cache->add($key, 0, $decaySeconds);

            return (int) $cache->increment($key, $amount);
        }

        // @phpstan-ignore-next-line (như hit())
        return (int) self::connection($store)->eval(self::ADD, 1, $store->getPrefix().$key, $amount, $decaySeconds);
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
