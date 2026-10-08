<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * T30 — xoá tài khoản HỌC SINH chưa xác thực OTP (cả email lẫn SĐT) quá `ops.unverified_account_days` ngày và
 * chưa có đơn / ghi danh / tiến độ học / lượt làm quiz / lượt dùng mã. Các FK restrict nên truy vấn loại trừ tường minh.
 * Bản ghi `consents` và `carts`/`otp_codes` của tài khoản bị xoá cùng (consents là FK restrict nên xoá trước, cùng transaction).
 */
class UsersPurgeUnverifiedCommand extends Command
{
    protected $signature = 'users:purge-unverified {--dry-run : Chỉ đếm, không xoá} {--days= : Ghi đè số ngày}';

    protected $description = 'Xoá tài khoản học sinh chưa xác thực quá hạn và chưa phát sinh dữ liệu học/mua';

    public function handle(): int
    {
        $raw = $this->option('days');
        if ($raw !== null && ! ctype_digit((string) $raw)) {
            $this->error('--days phải là số nguyên dương.');

            return self::FAILURE;
        }
        $days = $raw !== null ? (int) $raw : (int) config('ops.unverified_account_days');
        if ($days < 1) {
            $this->error('Số ngày phải >= 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = $this->candidates($cutoff)->count();
            $this->info("users: {$count} tài khoản sẽ xoá.");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) config('ops.purge_chunk'));
        $total = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $ids = $this->candidates($cutoff)->where('users.id', '>', $lastId)->orderBy('users.id')->limit($chunk)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                break;
            }
            $lastId = max($ids);

            try {
                $total += $this->purgeBatch($ids, $cutoff);
            } catch (Throwable $e) {
                // Cô lập user lỗi: thử từng user một, mỗi user một transaction riêng.
                Log::warning('users:purge-unverified: lô lỗi, thử từng tài khoản.', ['count' => count($ids), 'error' => $e->getMessage()]);
                foreach ($ids as $id) {
                    try {
                        $total += $this->purgeBatch([$id], $cutoff);
                    } catch (Throwable $single) {
                        $skipped++;
                        Log::warning('users:purge-unverified: bỏ qua 1 tài khoản.', ['error' => $single->getMessage()]);
                    }
                }
            }

            if (count($ids) < $chunk) {
                break;
            }
            usleep((int) config('ops.purge_sleep_ms') * 1000);
        }

        Log::info('users:purge-unverified hoàn tất.', ['deleted' => $total, 'skipped' => $skipped]);
        $this->info("users: {$total} tài khoản đã xoá.");

        if ($skipped > 0) {
            $this->error("users: {$skipped} tài khoản bị bỏ qua do lỗi (xem log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Xoá một lô: khoá các hàng còn đủ điều kiện trước, rồi chỉ xoá consents + users của đúng các hàng đã khoá
     * (user vừa xác thực/phát sinh dữ liệu sau khi chọn lô sẽ không bị đụng tới).
     *
     * @param  list<int>  $ids
     */
    public function purgeBatch(array $ids, \DateTimeInterface $cutoff): int
    {
        return DB::transaction(function () use ($ids, $cutoff): int {
            $locked = $this->candidates($cutoff)->whereIn('users.id', $ids)->lockForUpdate()->pluck('users.id')->all();
            if ($locked === []) {
                return 0;
            }

            DB::table('consents')->whereIn('user_id', $locked)->delete();

            return DB::table('users')->whereIn('id', $locked)->delete();
        });
    }

    private function candidates(\DateTimeInterface $cutoff): Builder
    {
        $none = fn (string $table, string $column = 'user_id') => fn (Builder $q) => $q
            ->select(DB::raw(1))->from($table)->whereColumn("{$table}.{$column}", 'users.id');

        return DB::table('users')
            ->where('users.role', UserRole::Student->value)
            ->whereNull('users.email_verified_at')
            ->whereNull('users.phone_verified_at')
            // T34: tài khoản đã ẩn danh hoá (giữ dòng làm gốc FK cho đơn/tiến độ) không bao giờ bị xoá cứng.
            ->whereNull('users.anonymized_at')
            ->where('users.created_at', '<', $cutoff)
            ->whereNotExists($none('orders'))
            ->whereNotExists($none('enrollments'))
            ->whereNotExists($none('lesson_progress'))
            ->whereNotExists($none('quiz_attempts'))
            ->whereNotExists($none('coupon_usages'));
    }
}
