<?php

namespace App\Services\Learning;

use App\Models\Lesson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ghi log mỗi lần cấp link phát (channel `playback`, giữ 90 ngày) và cảnh báo bất thường (ADR-002 §4):
 * một user nhận link từ quá nhiều IP hoặc quá nhiều bài trong 1 giờ. Bộ đếm trong cache, sai số do race chấp nhận được
 * (chỉ để cảnh báo, không chặn).
 */
class PlaybackAuditor
{
    public function record(?int $userId, Lesson $lesson, ?string $ip, ?string $userAgent, string $actor = 'student'): void
    {
        Log::channel('playback')->info('playback', [
            'user_id' => $userId,
            'lesson_id' => $lesson->getKey(),
            'course_id' => $lesson->course_id,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 120),
            'actor' => $actor,
        ]);

        if ($userId !== null && $actor === 'student') {
            $this->detectAnomaly($userId, (int) $lesson->getKey(), $ip);
        }
    }

    private function detectAnomaly(int $userId, int $lessonId, ?string $ip): void
    {
        $cfg = (array) config('learning.playback_anomaly');
        $window = (int) $cfg['window_minutes'] * 60;
        $now = time();

        $key = "playback:seen:{$userId}";
        /** @var array{ips?: array<string, int>, lessons?: array<int, int>} $seen */
        $seen = (array) Cache::get($key, []);

        $ips = array_filter((array) ($seen['ips'] ?? []), fn ($t) => $now - (int) $t < $window);
        $lessons = array_filter((array) ($seen['lessons'] ?? []), fn ($t) => $now - (int) $t < $window);

        if ($ip !== null) {
            $ips[$ip] = $now;
        }
        $lessons[$lessonId] = $now;

        Cache::put($key, ['ips' => $ips, 'lessons' => $lessons], $window);

        $tooManyIps = count($ips) > (int) $cfg['max_ips'];
        $tooManyLessons = count($lessons) > (int) $cfg['max_lessons'];

        // Mỗi giờ chỉ cảnh báo 1 lần/user cho mỗi loại.
        if ($tooManyIps && Cache::add("playback:warned:ips:{$userId}", 1, $window)) {
            Log::channel('playback')->warning('playback.anomaly.ips', ['user_id' => $userId, 'distinct_ips' => count($ips)]);
        }
        if ($tooManyLessons && Cache::add("playback:warned:lessons:{$userId}", 1, $window)) {
            Log::channel('playback')->warning('playback.anomaly.lessons', ['user_id' => $userId, 'distinct_lessons' => count($lessons)]);
        }
    }
}
