<?php

namespace App\Providers;

use App\Console\Commands\CountersRecountCommand;
use App\Services\Counters\CouponUsedCountRecounter;
use App\Services\Counters\CourseEnrollmentsCountRecounter;
use Illuminate\Support\ServiceProvider;

/**
 * Đăng ký danh sách `Recounter` cho `counters:recount` (T14). Tách khỏi
 * `AppServiceProvider` để giảm xung đột với các task khác cùng sửa file đó
 * (giống `PaymentServiceProvider` — T17). T15 thêm `CouponUsedCountRecounter`
 * (`coupons.used_count`) chỉ cần thêm 1 dòng vào mảng bên dưới.
 */
class CounterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(CountersRecountCommand::class)
            ->needs('$recounters')
            ->give(fn () => [
                $this->app->make(CourseEnrollmentsCountRecounter::class),
                $this->app->make(CouponUsedCountRecounter::class),
            ]);
    }
}
