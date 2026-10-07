<?php

namespace App\Services\Video\Migration;

use App\Enums\VideoAssetStatus;
use App\Models\Lesson;
use App\Models\VideoAsset;
use App\Models\VideoProviderMigration as Migration;
use App\Services\Audit\AuditLogger;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Providers\BunnyStreamProvider;
use App\Services\Video\VideoProviderManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * T37-1 (ADR-002 §6.4): chuyển video `ready` của VideoLab (`internal`) sang Bunny.
 *
 * Mỗi asset đi qua sổ `video_provider_migrations`: tạo video ở Bunny -> PUT tệp gốc -> chờ Bunny `ready` ->
 * (transaction) đổi provider/guid/library/thời lượng của asset. Asset giữ nguyên provider cũ (học sinh vẫn phát bằng
 * VideoLab) cho tới đúng bước cuối; mọi lỗi chỉ ghi vào sổ và lần chạy sau tiếp tục. HTTP luôn ngoài transaction.
 * Video nguồn không bị xoá trừ khi gọi `deleteSource()` (cờ `--delete-source`).
 *
 * Chỉ hỗ trợ internal -> bunny (đủ cho ADR-002). Tệp gốc đọc thẳng từ đĩa VideoLab (`filesystems.disks.videolab.root`,
 * cùng quy ước đường dẫn `source/{guid}.bin` — sao chép có chủ đích để không import code module VideoLab).
 */
class VideoProviderMigrator
{
    public const FROM = 'internal';

    public const TO = 'bunny';

    public const MIGRATED = 'migrated';

    public const WAITING = 'waiting';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly AuditLogger $audit,
    ) {}

    /** Kiểm cấu hình hai phía (không gọi mạng). @throws VideoProviderException */
    public function assertReady(bool $needSource = false): void
    {
        $this->target();

        if ($needSource) {
            $this->providers->driver(self::FROM);
        }
    }

    /**
     * Asset cần xử lý: đang dở (có sổ pending/uploaded) trước, rồi các asset `ready` còn lại theo id.
     *
     * @return Collection<int, VideoAsset>
     */
    public function candidates(?int $assetId = null, ?int $limit = null): Collection
    {
        $base = fn () => VideoAsset::query()->where('provider', self::FROM)->where('status', VideoAssetStatus::Ready)
            ->when($assetId !== null, fn ($q) => $q->whereKey($assetId));

        $open = $base()->whereIn('id', Migration::query()->whereIn('status', [Migration::PENDING, Migration::UPLOADED])
            ->where('to_provider', self::TO)->select('video_asset_id'))->orderBy('id')->get();

        $rest = $base()->whereNotIn('id', $open->modelKeys())->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))->get();

        $all = $open->concat($rest)->values();

        return $limit !== null ? $all->take($limit)->values() : $all;
    }

    /** Đường dẫn tệp gốc nếu còn và có nội dung; null nếu không. */
    public function sourcePath(VideoAsset $asset): ?string
    {
        if (preg_match(self::GUID, $asset->provider_video_id) !== 1) {
            return null;
        }

        $path = rtrim((string) config('filesystems.disks.videolab.root'), '/').'/source/'.$asset->provider_video_id.'.bin';

        return is_file($path) && is_readable($path) && filesize($path) > 0 ? $path : null;
    }

    public function openMigration(VideoAsset $asset): ?Migration
    {
        return Migration::query()->where('video_asset_id', $asset->getKey())->where('to_provider', self::TO)
            ->whereIn('status', [Migration::PENDING, Migration::UPLOADED])->latest('id')->first();
    }

    /**
     * @return array{outcome: string, message: string}
     */
    public function migrate(VideoAsset $asset, int $waitSeconds): array
    {
        $asset = $asset->fresh();

        if ($asset === null || $asset->provider !== self::FROM || $asset->status !== VideoAssetStatus::Ready) {
            return $this->result(self::SKIPPED, 'không còn ở VideoLab ở trạng thái ready.');
        }

        try {
            $target = $this->target();
            $row = $this->openMigration($asset);

            if ($row === null) {
                if ($this->sourcePath($asset) === null) {
                    return $this->result(self::SKIPPED, 'tệp gốc không còn trong kho VideoLab (quá hạn giữ hoặc đã dọn); tải lại video hoặc đặt VIDEOLAB_KEEP_SOURCE=true cho video sau này.');
                }

                $row = $this->openRow($asset, $target);
            }

            if ($row->status === Migration::PENDING) {
                $source = $this->sourcePath($asset);

                if ($source === null) {
                    $this->abandon($target, $row, 'tệp gốc không còn để tải lại.');

                    return $this->result(self::SKIPPED, 'tệp gốc biến mất giữa chừng; đã bỏ video dở ở Bunny.');
                }

                try {
                    // Lần trước có thể bị ngắt SAU khi Bunny đã nhận tệp: đã nhận/đang mã hoá thì không PUT lại.
                    $existing = $target->getVideo($row->to_video_id)->status;

                    if ($existing !== VideoAssetStatus::Created && $existing !== VideoAssetStatus::Failed) {
                        $row->forceFill(['status' => Migration::UPLOADED, 'last_error' => null])->save();

                        return $this->awaitAndFinalize($asset, $row, $target, $waitSeconds);
                    }

                    $row->increment('attempts');
                    $target->uploadSource($row->to_video_id, $source);
                } catch (VideoNotFoundException) {
                    $this->abandon($target, $row, 'video đích không còn ở Bunny.');

                    return $this->result(self::FAILED, 'video đích biến mất ở Bunny; lần chạy sau tạo lại.');
                }

                $row->forceFill(['status' => Migration::UPLOADED, 'last_error' => null])->save();
            }

            return $this->awaitAndFinalize($asset, $row, $target, $waitSeconds);
        } catch (VideoProviderException $e) {
            return $this->fail($asset, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->fail($asset, 'lỗi không xác định (xem log ứng dụng).');
        }
    }

    /**
     * Xoá video nguồn ở VideoLab cho sổ đã hoàn tất. Chỉ xoá khi Bunny vẫn `ready` và asset đang trỏ đúng video đó.
     *
     * @return Collection<int, Migration>
     */
    public function deletableSources(?int $assetId = null, ?int $limit = null): Collection
    {
        return Migration::query()->where('status', Migration::COMPLETED)->whereNull('source_deleted_at')
            ->where('from_provider', self::FROM)
            ->when($assetId !== null, fn ($q) => $q->where('video_asset_id', $assetId))
            ->orderBy('id')->when($limit !== null, fn ($q) => $q->limit($limit))->get();
    }

    /**
     * @return array{outcome: string, message: string}
     */
    public function deleteSource(Migration $row): array
    {
        try {
            $target = $this->target();
            $asset = VideoAsset::query()->find($row->video_asset_id);

            if ($asset === null || $asset->provider !== self::TO || $asset->provider_video_id !== $row->to_video_id) {
                return $this->result(self::SKIPPED, 'asset không còn trỏ tới video Bunny của lần chuyển này.');
            }

            if ($target->getVideo($row->to_video_id)->status !== VideoAssetStatus::Ready) {
                return $this->result(self::SKIPPED, 'video ở Bunny chưa ready; không xoá nguồn.');
            }

            $this->providers->driver(self::FROM)->deleteVideo($row->from_video_id);
            $row->forceFill(['source_deleted_at' => now()])->save();

            $this->audit->log('video.provider_source_deleted', $asset, [
                'migration_id' => $row->getKey(),
                'from_provider' => $row->from_provider,
                'from_video_id' => $row->from_video_id,
            ]);

            return $this->result(self::MIGRATED, 'đã xoá video nguồn ở VideoLab.');
        } catch (VideoProviderException $e) {
            return $this->result(self::FAILED, $e->getMessage());
        }
    }

    /**
     * Trả asset về VideoLab theo sổ (`from_*`). Chỉ khi chưa xoá nguồn, asset còn trỏ đúng video Bunny của lần chuyển
     * và video VideoLab còn `ready`. Video Bunny được GIỮ (dọn tay/sau); sổ chuyển `rolled_back`.
     *
     * @return array{outcome: string, message: string}
     */
    public function rollback(VideoAsset $asset): array
    {
        try {
            $row = Migration::query()->where('video_asset_id', $asset->getKey())->where('status', Migration::COMPLETED)
                ->where('to_provider', self::TO)->latest('id')->first();

            if ($row === null) {
                return $this->result(self::SKIPPED, 'không có lần chuyển hoàn tất nào để hoàn tác.');
            }

            if ($row->source_deleted_at !== null) {
                return $this->result(self::SKIPPED, 'video nguồn đã bị xoá (--delete-source); không thể hoàn tác.');
            }

            $fresh = $asset->fresh();

            if ($fresh === null || $fresh->provider !== self::TO || $fresh->provider_video_id !== $row->to_video_id) {
                return $this->result(self::SKIPPED, 'asset không còn trỏ tới video Bunny của lần chuyển này.');
            }

            $old = $this->providers->driver($row->from_provider)->getVideo($row->from_video_id);

            if ($old->status !== VideoAssetStatus::Ready) {
                return $this->result(self::FAILED, 'video VideoLab không còn ready; không hoàn tác.');
            }

            DB::transaction(function () use ($row, $old): void {
                $locked = VideoAsset::query()->whereKey($row->video_asset_id)->lockForUpdate()->firstOrFail();
                $lockedRow = Migration::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedRow->status !== Migration::COMPLETED || $locked->provider_video_id !== $lockedRow->to_video_id) {
                    throw new VideoProviderException('Trạng thái đã đổi trong lúc hoàn tác; thử lại.');
                }

                $duration = max(0, (int) $old->durationSeconds);
                $locked->forceFill([
                    'provider' => $lockedRow->from_provider,
                    'provider_library_id' => $lockedRow->from_library_id,
                    'provider_video_id' => $lockedRow->from_video_id,
                    'duration_seconds' => $duration,
                ])->save();

                Lesson::query()->where('video_asset_id', $locked->getKey())->update(['duration_seconds' => $duration, 'updated_at' => now()]);
                $lockedRow->forceFill(['status' => Migration::ROLLED_BACK, 'last_error' => null])->save();

                $this->audit->log('video.provider_rolled_back', $locked, [
                    'migration_id' => $lockedRow->getKey(),
                    'from_provider' => self::TO,
                    'to_provider' => $lockedRow->from_provider,
                    'kept_video_id' => $lockedRow->to_video_id,
                ]);
            });

            return $this->result(self::MIGRATED, 'đã trả về VideoLab; video Bunny '.$row->to_video_id.' được giữ để dọn sau.');
        } catch (VideoProviderException $e) {
            return $this->result(self::FAILED, $e->getMessage());
        }
    }

    /** @throws VideoProviderException */
    private function target(): BunnyStreamProvider
    {
        $provider = $this->providers->driver(self::TO);

        if (! $provider instanceof BunnyStreamProvider) {
            throw new VideoProviderException('Nhà cung cấp đích không phải Bunny.');
        }

        return $provider;
    }

    /** Tạo video đích rồi ghi sổ; nếu ghi sổ lỗi thì xoá video vừa tạo để không mồ côi. */
    private function openRow(VideoAsset $asset, BunnyStreamProvider $target): Migration
    {
        $remote = $target->createVideo('migrated-asset-'.$asset->getKey());

        try {
            // Khoá hàng asset rồi kiểm lại: nếu tiến trình khác đã mở sổ trong lúc ta gọi Bunny (khoá lệnh bị kẹt/hết
            // hạn) thì dùng sổ đó và bỏ video vừa tạo, không bao giờ có hai sổ dở cho một asset.
            $row = DB::transaction(function () use ($asset, $target, $remote): ?Migration {
                VideoAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first();

                if ($this->openMigration($asset) !== null) {
                    return null;
                }

                $row = new Migration;
                $row->forceFill([
                    'video_asset_id' => $asset->getKey(),
                    'from_provider' => $asset->provider,
                    'from_library_id' => $asset->provider_library_id,
                    'from_video_id' => $asset->provider_video_id,
                    'to_provider' => self::TO,
                    'to_library_id' => $target->libraryId(),
                    'to_video_id' => $remote->guid,
                    'status' => Migration::PENDING,
                    'attempts' => 0,
                ])->save();

                return $row;
            });

            if ($row === null) {
                $this->bestEffortDelete($target, $remote->guid);

                return $this->openMigration($asset) ?? throw new VideoProviderException('Không mở được sổ chuyển; thử lại.');
            }

            return $row;
        } catch (Throwable $e) {
            $this->bestEffortDelete($target, $remote->guid);

            throw $e;
        }
    }

    /**
     * @return array{outcome: string, message: string}
     */
    private function awaitAndFinalize(VideoAsset $asset, Migration $row, BunnyStreamProvider $target, int $waitSeconds): array
    {
        $deadline = time() + max(0, $waitSeconds);
        $interval = max(0, (int) config('video.migration.poll_interval_seconds', 10));

        while (true) {
            try {
                $remote = $target->getVideo($row->to_video_id);
            } catch (VideoNotFoundException) {
                $row->forceFill(['status' => Migration::ABANDONED, 'last_error' => 'video đích không còn ở Bunny.'])->save();

                return $this->result(self::FAILED, 'video đích biến mất ở Bunny; lần chạy sau tạo lại.');
            }

            if ($remote->status === VideoAssetStatus::Ready) {
                return $this->finalize($asset, $row, $target, $remote);
            }

            if ($remote->status === VideoAssetStatus::Failed) {
                $this->bestEffortDelete($target, $row->to_video_id);
                $row->forceFill(['status' => Migration::FAILED, 'last_error' => mb_substr((string) $remote->errorMessage, 0, 500)])->save();

                return $this->result(self::FAILED, 'Bunny không mã hoá được video; asset giữ nguyên VideoLab, lần chạy sau thử lại từ đầu.');
            }

            if (time() >= $deadline) {
                return $this->result(self::WAITING, 'Bunny đang mã hoá; chạy lại lệnh để ghi nhận khi xong.');
            }

            if ($interval > 0) {
                sleep($interval);
            }
        }
    }

    /**
     * @return array{outcome: string, message: string}
     */
    private function finalize(VideoAsset $asset, Migration $row, BunnyStreamProvider $target, ProviderVideo $remote): array
    {
        $libraryId = $target->libraryId();
        $duration = max(0, (int) $remote->durationSeconds);

        $done = DB::transaction(function () use ($asset, $row, $remote, $libraryId, $duration): bool {
            $lockedRow = Migration::query()->whereKey($row->getKey())->lockForUpdate()->first();
            $locked = VideoAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first();

            if ($lockedRow === null || $lockedRow->status !== Migration::UPLOADED) {
                return false;
            }

            if ($locked === null || $locked->provider !== self::FROM || $locked->provider_video_id !== $lockedRow->from_video_id) {
                $lockedRow->forceFill(['status' => Migration::ABANDONED, 'last_error' => 'asset đã đổi/bị xoá trong lúc chuyển.'])->save();

                return false;
            }

            $locked->forceFill([
                'provider' => self::TO,
                'provider_library_id' => $libraryId,
                'provider_video_id' => $remote->guid,
                'status' => VideoAssetStatus::Ready,
                'duration_seconds' => $duration,
                'error_message' => null,
            ])->save();

            Lesson::query()->where('video_asset_id', $locked->getKey())->update(['duration_seconds' => $duration, 'updated_at' => now()]);

            $lockedRow->forceFill(['status' => Migration::COMPLETED, 'completed_at' => now(), 'last_error' => null])->save();

            $this->audit->log('video.provider_migrated', $locked, [
                'migration_id' => $lockedRow->getKey(),
                'from_provider' => $lockedRow->from_provider,
                'from_video_id' => $lockedRow->from_video_id,
                'to_provider' => self::TO,
                'to_video_id' => $remote->guid,
                'duration_seconds' => $duration,
            ]);

            return true;
        });

        if (! $done) {
            $fresh = Migration::query()->find($row->getKey());

            if ($fresh !== null && $fresh->status === Migration::ABANDONED) {
                $this->bestEffortDelete($target, $row->to_video_id); // asset đã đổi: không để video mồ côi ở Bunny
            }

            return $this->result(self::SKIPPED, 'asset đã đổi hoặc đã được xử lý bởi tiến trình khác; không chuyển.');
        }

        return $this->result(self::MIGRATED, "đã chuyển sang Bunny (thời lượng {$duration}s); video VideoLab được giữ lại.");
    }

    private function abandon(BunnyStreamProvider $target, Migration $row, string $reason): void
    {
        $this->bestEffortDelete($target, $row->to_video_id);
        $row->forceFill(['status' => Migration::ABANDONED, 'last_error' => $reason])->save();
    }

    private function bestEffortDelete(BunnyStreamProvider $target, string $guid): void
    {
        try {
            $target->deleteVideo($guid);
        } catch (Throwable) {
            // Dọn dẹp không được che lỗi gốc; video mồ côi (nếu có) nằm ở Bunny, tên `migrated-asset-{id}`.
        }
    }

    /**
     * @return array{outcome: string, message: string}
     */
    private function fail(VideoAsset $asset, string $message): array
    {
        $row = $this->openMigration($asset);

        if ($row !== null) {
            $row->forceFill(['last_error' => mb_substr($message, 0, 500)])->save();
        }

        return $this->result(self::FAILED, $message.' Asset giữ nguyên VideoLab; lần chạy sau thử lại.');
    }

    /**
     * @return array{outcome: string, message: string}
     */
    private function result(string $outcome, string $message): array
    {
        return ['outcome' => $outcome, 'message' => $message];
    }
}
