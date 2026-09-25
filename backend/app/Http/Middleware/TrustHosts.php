<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as BaseTrustHosts;

/**
 * Laravel bỏ qua TrustHosts khi `app()->environment('local')` hoặc khi chạy
 * test (`runningUnitTests()`) — không phù hợp với VitaminVui vì 2 host
 * (api./admin-api.) là ranh giới bảo mật thật ngay cả ở local/Docker
 * (ADR-004 §2.2, S6): ConfigureHostContext dựa vào host đã được xác thực để
 * chọn cookie/CORS đúng, nên phải luôn bật, mọi môi trường.
 */
class TrustHosts extends BaseTrustHosts
{
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
