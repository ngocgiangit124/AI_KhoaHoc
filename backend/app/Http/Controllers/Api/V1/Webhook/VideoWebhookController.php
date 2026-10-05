<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\VideoWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VideoWebhookController extends Controller
{
    public function __construct(private readonly VideoWebhookService $webhooks) {}

    public function __invoke(Request $request, string $provider): Response
    {
        try {
            $known = $this->webhooks->handle($provider, $request);
        } catch (VideoProviderException) {
            // Không xác minh được với nhà cung cấp: trả 503 để họ gửi lại.
            throw new DomainException('VIDEO_PROVIDER_UNAVAILABLE', 'Tạm thời chưa xử lý được.', 503);
        }

        abort_unless($known, 404);

        return response()->noContent();
    }
}
