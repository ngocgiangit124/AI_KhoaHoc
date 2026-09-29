<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Tombstone lý do mất phiên của Học Sinh (ADR-003) — khoá cache
 * `session_replaced:{sha256(session_id)}`, TTL = `session.lifetime`. Ghi bởi
 * `App\Services\Auth\StudentSessionService` (khi thay/thu hồi phiên), đọc bởi
 * `App\Support\ApiExceptionRenderer` khi `auth:sanctum` ném
 * `AuthenticationException` (phiên cũ đã bị xoá khỏi store — trường hợp
 * thường gặp).
 *
 * Dùng store cache MẶC ĐỊNH (`Cache::` không ép `Cache::store('redis')`):
 * `CACHE_STORE` là `redis` ở local/production (Redis DB riêng theo ADR-004
 * §5 — `REDIS_CACHE_DB`) nhưng bị ép về `array` (trong tiến trình, không chia
 * sẻ) ở môi trường test (`phpunit.<task>.xml`) — nhiều task chạy test song
 * song không được cùng ghi/đọc 1 Redis thật.
 *
 * @phpstan-type TombstonePayload array{reason: string, new_device_id: string|null, at: string}
 */
final class SessionTombstoneStore
{
    public static function key(string $sessionId): string
    {
        return 'session_replaced:'.hash('sha256', $sessionId);
    }

    /**
     * @param  array{reason: string, new_device_id: string|null, at: string}  $payload
     */
    public static function put(string $sessionId, array $payload): void
    {
        Cache::put(self::key($sessionId), $payload, self::ttl());
    }

    /**
     * @return array{reason: string, new_device_id: string|null, at: string}|null
     */
    public static function get(string $sessionId): ?array
    {
        $value = Cache::get(self::key($sessionId));

        if (! is_array($value) || ! is_string($value['reason'] ?? null)) {
            return null;
        }

        $newDeviceId = $value['new_device_id'] ?? null;
        $at = $value['at'] ?? null;

        return [
            'reason' => $value['reason'],
            'new_device_id' => is_string($newDeviceId) ? $newDeviceId : null,
            'at' => is_string($at) ? $at : '',
        ];
    }

    private static function ttl(): int
    {
        // `Cache::put($key, $value, $seconds)` nhận số GIÂY (Laravel >= 5.8),
        // trong khi `session.lifetime` (config/session.php) tính bằng PHÚT —
        // phải nhân 60, không được truyền thẳng.
        return (int) config('session.lifetime') * 60;
    }
}
