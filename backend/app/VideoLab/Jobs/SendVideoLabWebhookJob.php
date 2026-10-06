<?php

namespace App\VideoLab\Jobs;

use App\VideoLab\Models\Video;
use App\VideoLab\Support\Signature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Báo ứng dụng (webhook) rằng video xong/lỗi. Chạy ở queue mặc định (worker CÓ mạng — worker `video` bị cô lập).
 * Payload chỉ để gợi ý: ứng dụng luôn gọi lại getVideo() (ADR-002 §2). Ký HMAC trong `X-VideoLab-Signature`.
 * Retry backoff 10s/60s/300s; lỡ webhook thì `videos:check-stuck` vẫn đồng bộ.
 */
class SendVideoLabWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public readonly string $guid) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $video = Video::query()->where('guid', $this->guid)->first();

        if ($video === null) {
            return;
        }

        $body = json_encode([
            'VideoLibraryId' => $video->library_id,
            'VideoGuid' => $video->guid,
            'Status' => $video->status,
        ], JSON_THROW_ON_ERROR);

        $response = Http::withHeaders([
            'Host' => (string) config('videolab.webhook_host'),
            'Content-Type' => 'application/json',
            'X-VideoLab-Signature' => Signature::webhook($body),
        ])->withBody($body, 'application/json')
            ->timeout(10)->connectTimeout(5)
            ->post((string) config('videolab.webhook_url'));

        if (! $response->successful()) {
            throw new RuntimeException('Webhook trả HTTP '.$response->status());
        }
    }
}
