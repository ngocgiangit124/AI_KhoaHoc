<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T30 — xoá `audit_logs` quá thời hạn lưu (`ops.audit_retention_months`, mặc định 24 tháng), theo lô nhỏ.
 * Dùng query builder thô vì model AuditLog cố ý chặn xoá (S15); đây là đường duy nhất được phép, chỉ qua lệnh này.
 * Production: user DB của scheduler cần quyền DELETE trên `audit_logs` (xem TODO DBA ở model AuditLog).
 */
class AuditPurgeCommand extends Command
{
    /** Đồng bộ với trigger `audit_logs_block_delete` (migration 2026_10_16_100000). */
    public const MIN_RETENTION_MONTHS = 24;

    protected $signature = 'audit:purge {--dry-run : Chỉ đếm, không xoá} {--months= : Ghi đè thời hạn lưu (tháng)}';

    protected $description = 'Xoá audit_logs cũ hơn thời hạn lưu (config ops.audit_retention_months)';

    public function handle(): int
    {
        $raw = $this->option('months');
        if ($raw !== null && ! ctype_digit((string) $raw)) {
            $this->error('--months phải là số nguyên dương.');

            return self::FAILURE;
        }
        $months = $raw !== null ? (int) $raw : (int) config('ops.audit_retention_months');
        if ($months < 1) {
            $this->error('Thời hạn lưu phải >= 1 tháng.');

            return self::FAILURE;
        }
        if ($months < self::MIN_RETENTION_MONTHS) {
            // Trigger `audit_logs_block_delete` (L2) chỉ cho xoá dòng quá 24 tháng; thấp hơn sẽ nổ lỗi DB giữa chừng.
            $this->error('Thời hạn lưu phải >= '.self::MIN_RETENTION_MONTHS.' tháng (sàn của trigger DB bảo vệ audit_logs).');

            return self::FAILURE;
        }

        $cutoff = now()->subMonthsNoOverflow($months);
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $count = DB::table('audit_logs')->where('created_at', '<', $cutoff)->count();
            $this->info("audit_logs: {$count} dòng sẽ xoá (trước {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) config('ops.purge_chunk'));
        $total = 0;
        do {
            $deleted = DB::table('audit_logs')->where('created_at', '<', $cutoff)->orderBy('id')->limit($chunk)->delete();
            $total += $deleted;
            if ($deleted === $chunk) {
                usleep((int) config('ops.purge_sleep_ms') * 1000);
            }
        } while ($deleted === $chunk);

        Log::info('audit:purge hoàn tất.', ['deleted' => $total, 'months' => $months]);

        $this->info("audit_logs: {$total} dòng đã xoá.");

        return self::SUCCESS;
    }
}
