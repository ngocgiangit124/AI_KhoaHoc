<?php

namespace App\Console\Commands;

use App\Services\Counters\Recounter;
use Illuminate\Console\Command;

/**
 * Đối soát các bộ đếm denormalize (data-model §7, README §4 — cron hằng ngày
 * 02:00; lịch chạy trong `routes/console.php` do T30 wiring cùng các cron
 * khác). Danh sách `$recounters` được tiêm qua `App\Providers\CounterServiceProvider`
 * — thêm bộ đếm mới (vd T15: `coupons.used_count`) chỉ cần thêm 1 dòng ở
 * provider đó, KHÔNG sửa file này.
 */
class CountersRecountCommand extends Command
{
    protected $signature = 'counters:recount';

    protected $description = 'Đối soát lại các bộ đếm denormalize (courses.enrollments_count, ...)';

    /**
     * @param  list<Recounter>  $recounters
     */
    public function __construct(private readonly array $recounters)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->recounters as $recounter) {
            $fixed = $recounter->recount();

            $this->components->info("{$recounter->label()}: đã sửa {$fixed} bản ghi lệch.");
        }

        return self::SUCCESS;
    }
}
