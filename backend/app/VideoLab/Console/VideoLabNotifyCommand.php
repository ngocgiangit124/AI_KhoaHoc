<?php

namespace App\VideoLab\Console;

use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Models\Video;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cụm 4 M1 — báo app khi video xong/lỗi mà KHÔNG để worker-video ghi vào queue `default` (worker bị coi là không tin
 * cậy, Redis ACL chỉ cho nó đụng queue `video`). Worker chỉ cập nhật `vl_videos`; lệnh này chạy ở scheduler của app,
 * nhận phần việc chưa báo (`notified_at` null) và dispatch webhook như trước. Mỗi video được "giành" bằng một UPDATE có
 * điều kiện nên chạy trùng/nhiều máy không bắn đôi.
 */
class VideoLabNotifyCommand extends Command
{
    protected $signature = 'videolab:notify {--limit=200 : Số video tối đa mỗi lượt}';

    protected $description = 'Dispatch webhook cho video đã xong/lỗi (worker-video không tự dispatch vào queue của app)';

    public function handle(): int
    {
        $sent = 0;

        Video::query()
            ->whereIn('status', [Video::FINISHED, Video::ERROR])
            ->whereNull('notified_at')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'guid'])
            ->each(function (Video $video) use (&$sent): void {
                $claimed = Video::query()->whereKey($video->getKey())->whereNull('notified_at')->update(['notified_at' => now()]);

                if ($claimed !== 1) {
                    return;
                }

                try {
                    SendVideoLabWebhookJob::dispatch($video->guid);
                    $sent++;
                } catch (Throwable $e) {
                    // Không dispatch được (Redis lỗi): trả về chưa báo để lượt sau thử lại.
                    Video::query()->whereKey($video->getKey())->update(['notified_at' => null]);

                    throw $e;
                }
            });

        $this->info('Đã xếp hàng webhook: '.$sent);

        return self::SUCCESS;
    }
}
