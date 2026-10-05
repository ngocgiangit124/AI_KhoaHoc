<?php

namespace App\Console\Commands;

use App\Enums\EnrollmentStatus;
use Illuminate\Console\Command;
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
}
