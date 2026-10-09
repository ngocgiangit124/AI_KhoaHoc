<?php

namespace App\Console\Commands;

use App\Models\OrderNote;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * US-022 (T38-2) — xoá nội dung tự nhập của nhân viên trên đơn đã KẾT THÚC quá `orders.manual.staff_text_retention_days`
 * ngày: `orders.refund_note`, `orders.payment_reference`, `orders.cancel_reason_public` (đặt NULL) và `order_notes.body`
 * (cột NOT NULL → thay bằng {@see self::PLACEHOLDER}). Mốc kết thúc như T38-1 (paid_at / cancelled_at / refunded_at /
 * failed → updated_at). Đơn pending không bao giờ bị đụng. Đơn, tiền, trạng thái, `confirmed_by`, thời điểm,
 * `order_status_logs`, `audit_logs`, `updated_at` giữ nguyên.
 *
 * Xoá `order_notes.body` đi bằng query builder có chủ đích (model `OrderNote` vẫn chặn sửa/xoá qua Eloquent).
 * Idempotent; lô theo PK: đọc id rồi UPDATE lặp lại điều kiện (đơn đổi trạng thái giữa chừng thì không bị xoá nhầm).
 */
class PurgeOrderStaffNotes extends Command
{
    public const PLACEHOLDER = OrderNote::PURGED_BODY;

    protected $signature = 'orders:purge-staff-notes {--dry-run : Chỉ đếm, không xoá} {--days= : Ghi đè số ngày lưu giữ (không nhỏ hơn chính sách)}';

    protected $description = 'Xoá nội dung nhân viên tự nhập trên đơn (ghi chú nội bộ, mã giao dịch, lý do) sau thời hạn lưu giữ kể từ khi đơn kết thúc';

    /** @var array<string, string> trạng thái kết thúc => cột mốc thời gian */
    private const END_COLUMNS = [
        'paid' => 'paid_at',
        'cancelled' => 'cancelled_at',
        'refunded' => 'refunded_at',
        'failed' => 'updated_at',
    ];

    public function handle(): int
    {
        $raw = $this->option('days');
        if ($raw !== null && ! ctype_digit((string) $raw)) {
            $this->error('--days phải là số nguyên dương.');

            return self::FAILURE;
        }
        $policy = (int) config('orders.manual.staff_text_retention_days');
        $days = $raw !== null ? (int) $raw : $policy;
        if ($days < 1 || $days > 3650) {
            $this->error('Số ngày lưu giữ phải trong khoảng 1..3650.');

            return self::FAILURE;
        }
        // Xoá không hoàn tác được: chạy tay không được xoá sớm hơn chính sách đang cấu hình.
        if ($days < $policy) {
            $this->error("--days không được nhỏ hơn chính sách lưu giữ ({$policy} ngày).");

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) config('ops.purge_chunk'));
        $orders = 0;
        $notes = 0;

        foreach (self::END_COLUMNS as $status => $column) {
            if ($dry) {
                $orders += $this->eligibleOrders($status, $column, $cutoff)->count();
                $notes += $this->eligibleNotes($status, $column, $cutoff)->count();

                continue;
            }

            $lastId = 0;
            do {
                $ids = $this->eligibleOrders($status, $column, $cutoff)->where('orders.id', '>', $lastId)->orderBy('orders.id')->limit($chunk)->pluck('orders.id')->all();
                if ($ids === []) {
                    break;
                }
                $lastId = (int) end($ids);

                $orders += $this->eligibleOrders($status, $column, $cutoff)->whereIn('orders.id', $ids)
                    ->update(['refund_note' => null, 'payment_reference' => null, 'cancel_reason_public' => null]);

                if (count($ids) === $chunk) {
                    usleep((int) config('ops.purge_sleep_ms') * 1000);
                }
            } while (count($ids) === $chunk);

            $lastId = 0;
            do {
                $ids = $this->eligibleNotes($status, $column, $cutoff)->where('order_notes.id', '>', $lastId)->orderBy('order_notes.id')->limit($chunk)->pluck('order_notes.id')->all();
                if ($ids === []) {
                    break;
                }
                $lastId = (int) end($ids);

                $notes += $this->eligibleNotes($status, $column, $cutoff)->whereIn('order_notes.id', $ids)->update(['order_notes.body' => self::PLACEHOLDER]);

                if (count($ids) === $chunk) {
                    usleep((int) config('ops.purge_sleep_ms') * 1000);
                }
            } while (count($ids) === $chunk);
        }

        if ($dry) {
            $this->info("orders: {$orders} đơn, order_notes: {$notes} ghi chú sẽ bị xoá nội dung (đơn kết thúc trước {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        Log::info('orders:purge-staff-notes hoàn tất.', ['orders' => $orders, 'notes' => $notes, 'days' => $days]);
        $this->info("orders: {$orders} đơn, order_notes: {$notes} ghi chú đã xoá nội dung.");

        return self::SUCCESS;
    }

    private function eligibleOrders(string $status, string $column, Carbon $cutoff): Builder
    {
        return DB::table('orders')
            ->where('orders.status', $status)
            ->where(fn (Builder $q) => $q->whereNotNull('orders.refund_note')->orWhereNotNull('orders.payment_reference')->orWhereNotNull('orders.cancel_reason_public'))
            // created_at <= mốc kết thúc: thừa về nghiệp vụ nhưng cho phép dùng index (status, created_at).
            ->where('orders.created_at', '<=', $cutoff)
            ->where("orders.{$column}", '<=', $cutoff);
    }

    private function eligibleNotes(string $status, string $column, Carbon $cutoff): Builder
    {
        return DB::table('order_notes')
            ->join('orders', 'orders.id', '=', 'order_notes.order_id')
            ->where('orders.status', $status)
            ->where('order_notes.body', '<>', self::PLACEHOLDER)
            ->where('orders.created_at', '<=', $cutoff)
            ->where("orders.{$column}", '<=', $cutoff);
    }
}
