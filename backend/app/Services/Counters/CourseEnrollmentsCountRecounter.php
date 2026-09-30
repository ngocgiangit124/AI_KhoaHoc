<?php

namespace App\Services\Counters;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * Đối soát `courses.enrollments_count` theo số enrollment `active` THẬT
 * (T14, DBA #5, data-model §7 "Bộ đếm denormalize"). `EnrollmentService`
 * (`approve()`/`revoke()`) đã tự tăng/giảm cột này trong transaction — class
 * này chỉ SỬA LỆCH (chạy qua `counters:recount` hằng ngày), không phải đường
 * ghi chính.
 *
 * Nguyên tử theo từng lô id (review R6): một câu UPDATE tự đếm bằng subquery,
 * không đọc-rồi-ghi ở PHP nên approve chạy xen giữa không bị ghi đè lệch.
 * Bao gồm khóa xoá mềm.
 */
class CourseEnrollmentsCountRecounter implements Recounter
{
    public function label(): string
    {
        return 'courses.enrollments_count';
    }

    public function recount(): int
    {
        $fixed = 0;

        Course::withTrashed()
            ->select('id')
            ->chunkById(500, function ($courses) use (&$fixed): void {
                $fixed += DB::update(
                    'UPDATE courses SET enrollments_count = ('
                    .'SELECT COUNT(*) FROM enrollments WHERE enrollments.course_id = courses.id AND enrollments.status = ?'
                    .') WHERE courses.id BETWEEN ? AND ? AND enrollments_count <> ('
                    .'SELECT COUNT(*) FROM enrollments WHERE enrollments.course_id = courses.id AND enrollments.status = ?'
                    .')',
                    [
                        EnrollmentStatus::Active->value,
                        $courses->first()->id,
                        $courses->last()->id,
                        EnrollmentStatus::Active->value,
                    ]
                );
            });

        return $fixed;
    }
}
