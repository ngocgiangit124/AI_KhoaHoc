<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use Illuminate\Console\Command;

/** Xoá mã OTP cũ (đã hết hạn từ lâu). Limiter OTP chỉ xét cửa sổ 24h nên giữ mặc định 7 ngày là đủ. */
class OtpPruneCommand extends Command
{
    protected $signature = 'otp:prune {--dry-run : Chỉ đếm, không xoá}';

    protected $description = 'Dọn otp_codes tạo quá số ngày giữ lại (config ops.otp_retention_days)';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('ops.otp_retention_days'));
        $query = OtpCode::query()->where('created_at', '<', $cutoff)->where('expires_at', '<', now());

        $count = $this->option('dry-run') ? $query->count() : $this->deleteInChunks($cutoff);
        $this->info("otp_codes: {$count} dòng".($this->option('dry-run') ? ' sẽ xoá.' : ' đã xoá.'));

        return self::SUCCESS;
    }

    private function deleteInChunks(\DateTimeInterface $cutoff): int
    {
        $total = 0;

        do {
            $deleted = OtpCode::query()->where('created_at', '<', $cutoff)->where('expires_at', '<', now())->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted === 1000);

        return $total;
    }
}
