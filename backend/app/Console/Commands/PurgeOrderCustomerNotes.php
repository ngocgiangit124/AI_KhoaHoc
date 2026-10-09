<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * US-022 (T38-1) — đặt `orders.customer_note = NULL` cho đơn đã KẾT THÚC quá `orders.manual.customer_note_retention_days`
 * ngày. Mốc kết thúc theo trạng thái hiện tại: paid → paid_at, cancelled → cancelled_at, refunded → refunded_at,
 * failed → updated_at (không có cột riêng). Đơn pending không bao giờ bị đụng. Chỉ đổi đúng cột `customer_note`
 * (không chạm `updated_at`, đơn, tiền, log, `order_notes`).
 *
 * Idempotent; theo lô theo PK tăng dần: đọc id (không khoá) rồi UPDATE có điều kiện lặp lại trong WHERE
 * (đơn đổi trạng thái giữa chừng thì không bị xoá nhầm), mỗi lô là một câu lệnh ngắn (autocommit).
 */
class PurgeOrderCustomerNotes extends Command
{
    protected $signature = 'orders:purge-customer-notes {--dry-run : Chỉ đếm, không xoá} {--days= : Ghi đè số ngày lưu giữ}';

    protected $description = 'Xoá lời nhắn của học sinh (orders.customer_note) sau thời hạn lưu giữ kể từ khi đơn kết thúc';

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
        $days = $raw !== null ? (int) $raw : (int) config('orders.manual.customer_note_retention_days');
        if ($days < 30 || $days > 3650) {
            $this->error('Số ngày lưu giữ phải trong khoảng 30..3650.');

            return self::FAILURE;
        }
        // Xoá không hoàn tác được: chạy tay không được xoá sớm hơn chính sách đang cấu hình (review T38-1 R1).
        $policy = (int) config('orders.manual.customer_note_retention_days');
        if ($days < $policy) {
            $this->error("--days không được nhỏ hơn chính sách lưu giữ ({$policy} ngày).");

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) config('ops.purge_chunk'));
        $total = 0;

        foreach (self::END_COLUMNS as $status => $column) {
            if ($dry) {
                $total += $this->eligible($status, $column, $cutoff)->count();

                continue;
            }

            $lastId = 0;
            do {
                $ids = $this->eligible($status, $column, $cutoff)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->pluck('id')->all();
                if ($ids === []) {
                    break;
                }
                $lastId = (int) end($ids);

                // Lặp lại điều kiện trong UPDATE: đơn vừa đổi trạng thái/đã bị xoá note thì không khớp.
                $total += $this->eligible($status, $column, $cutoff)->whereIn('id', $ids)->update(['customer_note' => null]);

                if (count($ids) === $chunk) {
                    usleep((int) config('ops.purge_sleep_ms') * 1000);
                }
            } while (count($ids) === $chunk);
        }

        if ($dry) {
            $this->info("orders.customer_note: {$total} đơn sẽ xoá lời nhắn (kết thúc trước {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        Log::info('orders:purge-customer-notes hoàn tất.', ['purged' => $total, 'days' => $days]);
        $this->info("orders.customer_note: {$total} đơn đã xoá lời nhắn.");

        return self::SUCCESS;
    }

    private function eligible(string $status, string $column, Carbon $cutoff): Builder
    {
        return DB::table('orders')
            ->where('status', $status)
            ->whereNotNull('customer_note')
            // created_at <= mốc kết thúc: điều kiện thừa về nghiệp vụ nhưng cho phép dùng index (status, created_at).
            ->where('created_at', '<=', $cutoff)
            ->where($column, '<=', $cutoff);
    }
}
