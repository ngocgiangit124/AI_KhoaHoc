<?php

namespace App\VideoLab\Jobs;

use App\VideoLab\Services\TranscodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Transcode HLS (queue `video`, connection riêng retry_after > timeout). timeout 3600s, tries 2, idempotent theo
 * guid. Chạy trong container `worker-video` (sandbox: non-root, không .env, không mạng ra ngoài, giới hạn CPU/RAM).
 */
class TranscodeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $guid)
    {
        $this->tries = (int) config('videolab.job.tries');
        $this->timeout = (int) config('videolab.job.timeout');
        $this->onConnection((string) config('videolab.job.connection'));
        $this->onQueue((string) config('videolab.job.queue'));
    }

    public function handle(TranscodeService $service): void
    {
        $service->run($this->guid);
    }

    public function failed(?Throwable $e): void
    {
        app(TranscodeService::class)->markFailed($this->guid, 'Xử lý video thất bại.');
    }
}
