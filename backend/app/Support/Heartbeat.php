<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Nhịp sống của worker/scheduler lưu trong cache (Redis ở production) để `ops:health` kiểm tra. */
class Heartbeat
{
    private static int $lastWorkerWrite = 0;

    public static function beat(string $name): void
    {
        try {
            Cache::put(config('ops.health.cache_prefix').$name, time(), 3600);
        } catch (Throwable $e) {
            // Heartbeat không được làm sập worker/scheduler.
            Log::debug('Không ghi được heartbeat', ['name' => $name, 'exception' => $e::class]);
        }
    }

    /** Worker lặp mỗi giây: chỉ ghi cache tối đa mỗi 15 giây/tiến trình. */
    public static function workerBeat(): void
    {
        $now = time();

        if ($now - self::$lastWorkerWrite >= 15) {
            self::$lastWorkerWrite = $now;
            self::beat('worker');
        }
    }

    /** Số giây kể từ nhịp cuối; null nếu chưa từng có. */
    public static function age(string $name): ?int
    {
        $at = Cache::get(config('ops.health.cache_prefix').$name);

        return is_numeric($at) ? max(0, time() - (int) $at) : null;
    }
}
