<?php

namespace App\Services\Learning;

use App\Enums\VideoAssetStatus;
use App\Enums\VideoSource;
use App\Exceptions\DomainException;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\Content\ExternalVideoLink;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use InvalidArgumentException;

/**
 * Cấp link phát (ADR-002 §4). Không bao giờ trả URL nguồn: `hls` là URL ký có hạn của nhà cung cấp, `embed`
 * dựng lại từ ID đã validate (YouTube-nocookie/Vimeo). Gọi nhà cung cấp ngoài transaction.
 */
class PlaybackService
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly VideoProviderManager $providers,
        private readonly PlaybackAuditor $auditor,
    ) {}

    /**
     * Học sinh đăng nhập (chủ khóa, hoặc xem bài preview).
     *
     * @return array{kind: string, url: string, expires_at: ?string, resume_at_seconds: int}
     */
    public function forStudent(User $user, Lesson $lesson, ?string $ip, ?string $userAgent): array
    {
        $owned = $this->access->assertCanWatch($user, $lesson);
        $lesson->loadMissing('videoAsset');

        // Ràng IP chỉ cho bài trả phí (bài preview công khai không ràng).
        $bindIp = (bool) config('video.bind_ip') && ! $lesson->is_preview;
        $result = $this->resolve($lesson, $user->getKey(), $bindIp ? $ip : null);
        $result['resume_at_seconds'] = $owned ? $this->resumeAt($user, $lesson) : 0;

        $this->auditor->record((int) $user->getKey(), $lesson, $ip, $userAgent);

        return $result;
    }

    /**
     * Bài preview công khai (không đăng nhập, không ràng IP).
     *
     * @return array{kind: string, url: string, expires_at: ?string, resume_at_seconds: int}
     */
    public function forPublicPreview(Lesson $lesson, ?string $ip, ?string $userAgent): array
    {
        if (! $this->access->isPublicPreview($lesson)) {
            throw $this->access->notFound();
        }

        $lesson->loadMissing('videoAsset');
        $result = $this->resolve($lesson, 0, null);
        $result['resume_at_seconds'] = 0;

        $this->auditor->record(null, $lesson, $ip, $userAgent, 'guest');

        return $result;
    }

    /**
     * Staff/giáo viên xem thử (quyền đã kiểm ở controller). Không ràng IP, không đọc tiến độ.
     *
     * @return array{kind: string, url: string, expires_at: ?string, resume_at_seconds: int}
     */
    public function forStaff(User $user, Lesson $lesson, ?string $ip, ?string $userAgent): array
    {
        $lesson->loadMissing('videoAsset');
        $result = $this->resolve($lesson, $user->getKey(), null);
        $result['resume_at_seconds'] = 0;

        $this->auditor->record((int) $user->getKey(), $lesson, $ip, $userAgent, 'staff');

        return $result;
    }

    /**
     * @return array{kind: string, url: string, expires_at: ?string}
     */
    private function resolve(Lesson $lesson, int|string $userId, ?string $boundIp): array
    {
        if ($lesson->video_source === VideoSource::ExternalLink) {
            $url = ExternalVideoLink::embedUrl($lesson->external_provider, $lesson->external_video_id);
            if ($url === null) {
                throw $this->notAvailable();
            }

            return ['kind' => 'embed', 'url' => $url, 'expires_at' => null];
        }

        $asset = $lesson->video_source === VideoSource::Upload ? $lesson->videoAsset : null;
        if ($asset === null) {
            throw $this->notAvailable();
        }

        if ($asset->status !== VideoAssetStatus::Ready) {
            throw new DomainException('VIDEO_NOT_READY', 'Video đang được xử lý, vui lòng quay lại sau.', 409);
        }

        try {
            $info = $this->providers->driver($asset->provider)->playback($asset, new PlaybackContext(
                (int) $userId,
                $boundIp,
                max(1, (int) config('video.playback_ttl_minutes')) * 60,
            ));
        } catch (VideoProviderException|InvalidArgumentException) {
            throw new DomainException('VIDEO_PROVIDER_UNAVAILABLE', 'Không tải được video, vui lòng thử lại.', 503);
        }

        return ['kind' => $info->kind, 'url' => $info->url, 'expires_at' => $info->expiresAt->toIso8601String()];
    }

    /** Về 0 khi đã xem gần hết (xem lại từ đầu); còn lại tiếp tục đúng vị trí đã lưu. */
    private function resumeAt(User $user, Lesson $lesson): int
    {
        $position = LessonProgress::query()
            ->where('user_id', $user->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->value('last_position_seconds');

        $position = (int) $position;
        $duration = (int) $lesson->duration_seconds;

        return $duration > 0 && $position >= $duration - 5 ? 0 : $position;
    }

    private function notAvailable(): DomainException
    {
        return new DomainException('VIDEO_NOT_AVAILABLE', 'Bài học này chưa có video.', 404);
    }
}
