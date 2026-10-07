<?php

namespace App\Console\Commands;

use App\Models\VideoAsset;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Migration\VideoProviderMigrator as M;
use Illuminate\Cache\Lock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * T37-1: chuyển video VideoLab -> Bunny (ADR-002 §6.4). Idempotent, tiếp tục được khi bị ngắt, có khoá chống chạy
 * song song. Không áp hạn mức tải ngày (`video_upload_usages`): đây là việc vận hành, không phải học liệu mới do
 * người dùng tải lên. Không in khoá; thông điệp lỗi do ta tự viết.
 */
class VideosMigrateProviderCommand extends Command
{
    private const LOCK = 'videos:migrate-provider';

    protected $signature = 'videos:migrate-provider
        {--from=internal : Nhà cung cấp nguồn (hiện chỉ hỗ trợ internal)}
        {--to=bunny : Nhà cung cấp đích (hiện chỉ hỗ trợ bunny)}
        {--asset= : Chỉ chuyển asset có id này}
        {--limit= : Tối đa số asset xử lý trong lần chạy}
        {--wait= : Số giây chờ Bunny mã hoá mỗi asset (mặc định video.migration.wait_seconds)}
        {--dry-run : Chỉ liệt kê, không gọi Bunny, không ghi DB}
        {--rollback : Trả asset (--asset bắt buộc) về VideoLab theo sổ; chỉ khi chưa --delete-source}
        {--unlock : Nhả khoá chạy song song bị kẹt (sau khi chắc chắn không có tiến trình nào đang chạy)}
        {--delete-source : Sau khi xác nhận, xoá video nguồn ở VideoLab của các asset đã chuyển xong}
        {--force : Bỏ qua hỏi xác nhận của --delete-source}';

    protected $description = 'Chuyển video VideoLab (internal) sang Bunny; giữ video cũ cho tới khi dùng --delete-source';

    public function handle(M $migrator): int
    {
        if ($this->option('from') !== M::FROM || $this->option('to') !== M::TO) {
            $this->error('Hiện chỉ hỗ trợ --from=internal --to=bunny.');

            return self::FAILURE;
        }

        if ($this->option('unlock')) {
            return $this->unlock();
        }

        $assetId = $this->intOption('asset');
        $limit = $this->intOption('limit');
        $wait = $this->intOption('wait', true);

        if ($assetId === false || $limit === false || $wait === false) {
            $this->error('--asset và --limit phải là số nguyên dương; --wait phải là số nguyên không âm.');

            return self::FAILURE;
        }

        if ($this->option('rollback') && $assetId === null) {
            $this->error('--rollback cần --asset=ID.');

            return self::FAILURE;
        }

        $deleteSource = (bool) $this->option('delete-source');

        try {
            $migrator->assertReady($deleteSource || (bool) $this->option('rollback'));
        } catch (VideoProviderException|InvalidArgumentException $e) {
            $this->error($e->getMessage().' (cần Bunny cấu hình đủ và VIDEO_ENABLED_PROVIDERS có bunny'.($deleteSource ? ' và internal' : '').')');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($migrator, $assetId, $limit, $deleteSource);
        }

        $lock = Cache::lock(self::LOCK, $this->lockSeconds());

        if (! $lock->get()) {
            $this->error('Đang có một lần chạy videos:migrate-provider khác; thoát để không tạo trùng video ở Bunny.');

            return self::FAILURE;
        }

        try {
            if ($this->option('rollback')) {
                $asset = VideoAsset::query()->find($assetId);
                $r = $asset === null ? ['outcome' => M::SKIPPED, 'message' => 'không có asset này.'] : $migrator->rollback($asset);
                $this->line("asset #{$assetId}: [{$r['outcome']}] {$r['message']}");

                // Mọi nhánh từ chối đều trả 1 để script tự động không hiểu nhầm là đã hoàn tác (QA T37-1 N1).
                return $r['outcome'] === M::MIGRATED ? self::SUCCESS : self::FAILURE;
            }

            return $this->migrateAll($migrator, $assetId, $limit, $deleteSource, $lock, $wait);
        } finally {
            $lock->release();
        }
    }

    private function migrateAll(M $migrator, ?int $assetId, ?int $limit, bool $deleteSource, LockContract $lock, ?int $waitOption): int
    {
        $wait = $waitOption ?? (int) config('video.migration.wait_seconds', 300);
        $count = [M::MIGRATED => 0, M::WAITING => 0, M::SKIPPED => 0, M::FAILED => 0];

        foreach ($migrator->candidates($assetId, $limit) as $asset) {
            if ($lock instanceof Lock) {
                $lock->refresh($this->lockSeconds());
            }
            // gia hạn trước mỗi asset: một asset lớn có thể mất hàng giờ
            $r = $migrator->migrate($asset, $wait);
            $count[$r['outcome']]++;
            $this->line("asset #{$asset->getKey()}: [{$r['outcome']}] {$r['message']}");
        }

        $this->info("Đã chuyển: {$count[M::MIGRATED]}, đang chờ Bunny: {$count[M::WAITING]}, bỏ qua: {$count[M::SKIPPED]}, lỗi: {$count[M::FAILED]}");

        if ($deleteSource) {
            $count[M::FAILED] += $this->deleteSources($migrator, $assetId, $limit);
        }

        if ($count[M::WAITING] > 0) {
            $this->warn("Còn {$count[M::WAITING]} asset chờ Bunny mã hoá: chạy lại lệnh để ghi nhận (mã thoát 2).");
        }

        // 0: xong hết · 1: có lỗi · 2: không lỗi nhưng còn asset đang chờ.
        return $count[M::FAILED] > 0 ? self::FAILURE : ($count[M::WAITING] > 0 ? 2 : self::SUCCESS);
    }

    private function lockSeconds(): int
    {
        return max(60, (int) config('video.migration.upload_timeout_seconds', 3600) + (int) config('video.migration.wait_seconds', 300) + 900);
    }

    private function unlock(): int
    {
        if (! $this->option('force') && ! $this->confirm('Nhả khoá videos:migrate-provider? Chỉ làm khi CHẮC CHẮN không có tiến trình chuyển nào đang chạy (hai tiến trình cùng chạy có thể tạo video trùng ở Bunny).')) {
            $this->warn('Đã huỷ.');

            return self::SUCCESS;
        }

        Cache::lock(self::LOCK)->forceRelease();
        $this->info('Đã nhả khoá.');

        return self::SUCCESS;
    }

    private function deleteSources(M $migrator, ?int $assetId, ?int $limit): int
    {
        $rows = $migrator->deletableSources($assetId, $limit);

        if ($rows->isEmpty()) {
            $this->info('Không có video nguồn nào cần xoá.');

            return 0;
        }

        if (! $this->option('force') && ! $this->confirm("Xoá VĨNH VIỄN {$rows->count()} video nguồn ở VideoLab (đã có bản ready ở Bunny)?")) {
            $this->warn('Đã huỷ xoá nguồn.');

            return 0;
        }

        $failed = 0;

        foreach ($rows as $row) {
            $r = $migrator->deleteSource($row);
            $failed += $r['outcome'] === M::FAILED ? 1 : 0;
            $this->line("asset #{$row->video_asset_id}: [{$r['outcome']}] {$r['message']}");
        }

        return $failed;
    }

    private function dryRun(M $migrator, ?int $assetId, ?int $limit, bool $deleteSource): int
    {
        $this->info('DRY-RUN: không gọi Bunny, không ghi DB.');
        $candidates = $migrator->candidates($assetId, $limit);

        foreach ($candidates as $asset) {
            $open = $migrator->openMigration($asset);
            $source = $migrator->sourcePath($asset);
            $size = $source !== null ? round((int) filesize($source) / 1048576, 1).' MB' : null;

            $this->line("asset #{$asset->getKey()}: ".match (true) {
                $open !== null => "tiếp tục lần chuyển dở (video Bunny đã tạo, trạng thái {$open->status})",
                $source === null => 'BỎ QUA: tệp gốc không còn trong kho VideoLab',
                default => "sẽ tạo video ở Bunny và tải tệp gốc ({$size})",
            });
        }

        $this->info("Dry-run: {$candidates->count()} asset sẽ xử lý.");

        if ($deleteSource) {
            $this->info('Dry-run: '.$migrator->deletableSources($assetId, $limit)->count().' video nguồn sẽ bị xoá nếu xác nhận.');
        }

        return self::SUCCESS;
    }

    private function intOption(string $name, bool $allowZero = false): int|false|null
    {
        $raw = $this->option($name);

        if ($raw === null) {
            return null;
        }

        return is_numeric($raw) && (int) $raw >= ($allowZero ? 0 : 1) && (string) (int) $raw === (string) $raw ? (int) $raw : false;
    }
}
