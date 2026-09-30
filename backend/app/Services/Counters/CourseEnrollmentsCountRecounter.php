<?php

namespace App\Services\Counters;

use App\Enums\EnrollmentStatus;
use App\Models\Course;

/**
 * Đối soát `courses.enrollments_count` theo số enrollment `active` THẬT
 * (T14, DBA #5, data-model §7 "Bộ đếm denormalize"). `EnrollmentService`
 * (`approve()`/`revoke()`) đã tự tăng/giảm cột này trong transaction — class
 * này chỉ SỬA LỆCH (chạy qua `counters:recount` hằng ngày), không phải đường
 * ghi chính.
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

        Course::query()
            ->select(['id', 'enrollments_count'])
            ->withCount(['enrollments as active_enrollments_count' => function ($query): void {
                $query->where('status', EnrollmentStatus::Active->value);
            }])
            ->chunkById(500, function ($courses) use (&$fixed): void {
                foreach ($courses as $course) {
                    /** @var int $actual */
                    $actual = $course->getAttribute('active_enrollments_count');

                    if ((int) $course->enrollments_count !== $actual) {
                        Course::query()->whereKey($course->getKey())->update([
                            'enrollments_count' => $actual,
                        ]);

                        $fixed++;
                    }
                }
            });

        return $fixed;
    }
}
