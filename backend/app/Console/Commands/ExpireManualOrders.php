<?php

namespace App\Console\Commands;

use App\Services\Orders\ManualOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Huỷ đơn thanh toán thủ công đã quá hạn chờ (US-022, mỗi 15 phút). Chỉ đụng đơn `payment_method = manual`; job huỷ đơn MoMo (T20)
 * là việc khác. Mỗi đơn một transaction ngắn, kiểm lại dưới khoá; chạy lại không đổi gì (idempotent).
 */
class ExpireManualOrders extends Command
{
    protected $signature = 'orders:expire-manual {--limit=500 : Số đơn tối đa mỗi lần chạy}';

    protected $description = 'Huỷ đơn thanh toán thủ công (manual) quá hạn chờ duyệt (lý do expired)';

    public function handle(ManualOrderService $orders): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cancelled = $orders->expireDue($limit);

        Log::info('orders.expire_manual', ['cancelled' => $cancelled, 'limit' => $limit]);
        $this->info("orders: {$cancelled} đơn manual đã huỷ (hết hạn).");

        return self::SUCCESS;
    }
}
