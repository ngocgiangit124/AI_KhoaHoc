<?php

namespace App\Services\Video\Contracts;

use Illuminate\Http\Request;

/**
 * Nhà cung cấp có webhook KHÔNG chữ ký (Bunny) tự xác thực yêu cầu bằng bí mật nằm trên URL. Sai/thiếu → service
 * trả "không biết" (404), không gọi mạng.
 */
interface VerifiesWebhookRequest
{
    public function verifyWebhookRequest(Request $request): bool;
}
