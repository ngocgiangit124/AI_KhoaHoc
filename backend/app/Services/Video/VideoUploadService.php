<?php

namespace App\Services\Video;

use App\Enums\VideoAssetStatus;
use App\Enums\VideoSource;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use App\Services\Audit\AuditLogger;
use App\Services\Video\Data\UploadTarget;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Tạo phiên upload video cho bài (ADR-002 §4), 3 pha để KHÔNG giữ khoá DB khi gọi HTTP ra nhà cung cấp. `video_asset_id`, `lesson_id`, `created_by`, `provider*` chỉ đặt
 * ở đây (S5), không từ request. Khoá hàng khóa + người tạo để (a) hạn mức ngày không bị vượt khi gọi song song,
 * (b) không xung đột với CurriculumService (cùng khoá hàng khóa).
 */
class VideoUploadService
{
    /** Tiền tố provider_video_id tạm của asset đã giữ chỗ hạn mức nhưng chưa có video ở nhà cung cấp. */
    public const PENDING_PREFIX = 'pending-';

    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{asset: VideoAsset, target: UploadTarget}
     */
    public function createUpload(User $actor, Course $course, Lesson $lesson, string $filename, int $size): array
    {
        $maxBytes = (int) config('video.max_upload_mb') * 1024 * 1024;

        if ($size < 1 || $size > $maxBytes) {
            throw new DomainException('VIDEO_TOO_LARGE', 'Dung lượng video vượt giới hạn cho phép ('.config('video.max_upload_mb').' MB).', 422);
        }

        try {
            $provider = $this->providers->driver();
        } catch (InvalidArgumentException|VideoProviderException $e) {
            Log::error('Video provider không khả dụng', ['error' => $e->getMessage()]);

            throw new DomainException('VIDEO_PROVIDER_UNAVAILABLE', 'Dịch vụ video hiện chưa sẵn sàng. Vui lòng thử lại sau.', 503);
        }

        // Pha 1 (tx ngắn): giữ chỗ hạn mức bằng asset `created` (provider_video_id tạm). Không gọi provider ở đây.
        $asset = DB::transaction(function () use ($actor, $course, $lesson, $filename, $size, $provider): VideoAsset {
            Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $locked = Lesson::query()->where('course_id', $course->getKey())->whereKey($lesson->getKey())->firstOrFail();

            $this->enforceDailyQuota($actor, $size);

            $asset = new VideoAsset;
            $asset->forceFill([
                'provider' => $provider->name(),
                'provider_library_id' => (string) config('video.library_id'),
                'provider_video_id' => self::PENDING_PREFIX.Str::uuid(),
                'status' => VideoAssetStatus::Created,
                'original_filename' => $this->displayName($filename),
                'declared_size_bytes' => $size,
                'lesson_id' => $locked->getKey(),
                'created_by' => $actor->getKey(),
            ])->save();

            return $asset;
        });

        // Pha 2 (NGOÀI tx, không giữ khoá): gọi nhà cung cấp.
        $guid = null;

        try {
            $video = $provider->createVideo($this->title($lesson));
            $guid = $video->guid;
            $target = $provider->uploadTarget($video, (int) config('video.upload_ttl_minutes') * 60);
        } catch (Throwable $e) {
            $this->abandon($provider, $asset, $guid, 'Không tạo được phiên upload ở nhà cung cấp video.');
            Log::error('Tạo phiên upload video thất bại', ['error' => $e->getMessage()]);

            if ($e instanceof VideoProviderException) {
                throw new DomainException('VIDEO_PROVIDER_UNAVAILABLE', 'Dịch vụ video hiện chưa sẵn sàng. Vui lòng thử lại sau.', 503);
            }

            throw $e;
        }

        // Pha 3 (tx ngắn): kiểm lại bài còn hợp lệ dưới khoá khóa học rồi mới gắn.
        try {
            DB::transaction(function () use ($course, $lesson, $asset, $guid, $size, $provider): void {
                Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
                $locked = Lesson::query()->where('course_id', $course->getKey())->whereKey($lesson->getKey())->first();

                if ($locked === null) {
                    throw new DomainException('NOT_FOUND', 'Bài học không còn tồn tại.', 404);
                }

                $asset->forceFill(['provider_video_id' => $guid, 'status' => VideoAssetStatus::Uploading])->save();

                $replaced = $locked->video_asset_id;

                // Video cũ (nếu có) thành mồ côi, do `videos:prune-orphans` dọn.
                $locked->forceFill([
                    'video_source' => VideoSource::Upload,
                    'video_asset_id' => $asset->getKey(),
                    'external_provider' => null,
                    'external_video_id' => null,
                    'duration_seconds' => null,
                ])->save();

                $this->audit->log('lesson.video_upload', $locked, [
                    'course_id' => $course->getKey(),
                    'video_asset_id' => $asset->getKey(),
                    'size_bytes' => $size,
                    'provider' => $provider->name(),
                    'replaced_asset_id' => $replaced,
                ]);
            });
        } catch (Throwable $e) {
            $this->abandon($provider, $asset, $guid, 'Không gắn được video vào bài học.');

            throw $e;
        }

        return ['asset' => $asset->refresh(), 'target' => $target];
    }

    /** Đánh asset failed (bài không đổi) và dọn video đã tạo ở nhà cung cấp (best-effort). */
    private function abandon(Contracts\VideoProvider $provider, VideoAsset $asset, ?string $guid, string $message): void
    {
        try {
            VideoAsset::query()->whereKey($asset->getKey())->update(['status' => VideoAssetStatus::Failed->value, 'error_message' => $message]);
        } catch (Throwable) {
            // bỏ qua: pruner/check-stuck sẽ dọn
        }

        $this->discard($provider, $guid);
    }

    private function enforceDailyQuota(User $actor, int $size): void
    {
        $quota = (int) config('video.daily_quota_gb') * 1024 * 1024 * 1024;
        $used = (int) VideoAsset::query()
            ->where('created_by', $actor->getKey())
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('declared_size_bytes');

        if ($used + $size > $quota) {
            throw new DomainException(
                'VIDEO_QUOTA_EXCEEDED',
                'Đã vượt hạn mức tải video trong ngày ('.config('video.daily_quota_gb').' GB). Vui lòng thử lại vào ngày mai.',
                422,
                ['remaining_bytes' => max(0, $quota - $used)],
            );
        }
    }

    /** Dọn video vừa tạo ở nhà cung cấp khi giao dịch DB thất bại (best-effort). */
    private function discard(Contracts\VideoProvider $provider, ?string $guid): void
    {
        if ($guid === null) {
            return;
        }

        try {
            $provider->deleteVideo($guid);
        } catch (Throwable $e) {
            Log::warning('Không dọn được video mồ côi ở nhà cung cấp', ['provider' => $provider->name(), 'guid' => $guid]);
        }
    }

    private function title(Lesson $lesson): string
    {
        return 'lesson-'.$lesson->getKey();
    }

    /** Tên file chỉ để hiển thị (S3): bỏ đường dẫn, ký tự điều khiển; không bao giờ dùng làm đường dẫn. */
    private function displayName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return mb_substr($name, 0, 255);
    }
}
