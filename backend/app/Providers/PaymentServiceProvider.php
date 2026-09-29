<?php

namespace App\Providers;

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;

/**
 * Đăng ký `PaymentGatewayManager` (ADR-001, T17). Tách khỏi `AppServiceProvider`
 * để giảm xung đột với các task khác cùng sửa file đó.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);
    }
}
