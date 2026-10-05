<?php

namespace App\Console\Commands;

use App\Enums\EnrollmentStatus;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Đối soát các bộ đếm denormalize (data-model §3.2). Mỗi bộ đếm là 1 method `recount*` riêng để task sau
 * (T15: `coupons.used_count`) thêm mà không đụng phần cũ.
 */
class CountersRecountCommand extends Command
{
    protected $signature = 'counters:recount {--dry-run : Chỉ báo số dòng lệch, không sửa}';

    protected $description = 'Đối soát lại các bộ đếm denormalize (courses.enrollments_count, ...)';

    public function handle(): int
    {
        $this->recountCourseEnrollments();
        $this->recountCouponUsedCount();

        return self::SUCCESS;
    }

    /** `courses.enrollments_count` = số enrollment `active` (kể cả khóa đã xoá mềm). */
    private function recountCourseEnrollments(): void
    {
        $active = DB::table('enrollments')
            ->selectRaw('course_id, COUNT(*) AS c')
            ->where('status', EnrollmentStatus::Active->value)
            ->groupBy('course_id');

        $drifted = DB::table('courses')
            ->leftJoinSub($active, 'e', 'e.course_id', '=', 'courses.id')
            ->whereRaw('courses.enrollments_count <> COALESCE(e.c, 0)')
            ->count();

        $this->info("courses.enrollments_count: {$drifted} dòng lệch.");

        if ($drifted === 0 || $this->option('dry-run')) {
            return;
        }

        DB::update(
            'UPDATE courses
             LEFT JOIN (SELECT course_id, COUNT(*) AS c FROM enrollments WHERE status = ? GROUP BY course_id) e
               ON e.course_id = courses.id
             SET courses.enrollments_count = COALESCE(e.c, 0)
             WHERE courses.enrollments_count <> COALESCE(e.c, 0)',
            [EnrollmentStatus::Active->value],
        );

        $this->info('Đã sửa.');
    }

    /**
     * `coupons.used_count` = số dòng `coupon_usages` của mã (DBA #5), mọi trạng thái mã. Bảng `coupon_usages` do
     * T18 tạo: chưa có thì bỏ qua (không có nguồn sự thật để đối soát, KHÔNG đặt về 0).
     */
    private function recountCouponUsedCount(): void
    {
        if (! $this->tableExists('coupon_usages')) {
            $this->info('coupons.used_count: bỏ qua (chưa có bảng coupon_usages).');

            return;
        }

        $drifted = DB::table('coupons')
            ->leftJoinSub(
                DB::table('coupon_usages')->selectRaw('coupon_id, COUNT(*) AS c')->groupBy('coupon_id'),
                'u',
                'u.coupon_id',
                '=',
                'coupons.id',
            )
            ->whereRaw('coupons.used_count <> COALESCE(u.c, 0)')
            ->count();

        $this->info("coupons.used_count: {$drifted} dòng lệch.");

        if ($drifted === 0 || $this->option('dry-run')) {
            return;
        }

        DB::update(
            'UPDATE coupons
             LEFT JOIN (SELECT coupon_id, COUNT(*) AS c FROM coupon_usages GROUP BY coupon_id) u
               ON u.coupon_id = coupons.id
             SET coupons.used_count = COALESCE(u.c, 0)
             WHERE coupons.used_count <> COALESCE(u.c, 0)',
        );

        $this->info('Đã sửa.');
    }

    /** Thử đọc thay vì tra information_schema: chạy được cả khi bảng là bảng tạm (test) và không tốn truy vấn lược đồ. */
    private function tableExists(string $table): bool
    {
        try {
            DB::table($table)->limit(1)->get();

            return true;
        } catch (QueryException $e) {
            if ($e->getCode() === '42S02') {
                return false;
            }

            throw $e;
        }
    }
}
