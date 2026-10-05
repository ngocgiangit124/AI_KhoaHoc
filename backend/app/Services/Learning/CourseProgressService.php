<?php

namespace App\Services\Learning;

use App\Enums\LessonProgressStatus;
use Illuminate\Support\Facades\DB;

/**
 * % hoàn thành khóa = số bài đã hoàn thành / tổng số bài chưa xoá (làm tròn xuống; 100 chỉ khi xong hết).
 * Tính khi đọc (data-model §3.3, không denormalize ở MVP). T23 dùng `percentsFor()` cho danh sách khóa.
 */
class CourseProgressService
{
    public function percent(int $userId, int $courseId): int
    {
        return $this->percentsFor($userId, [$courseId])[$courseId] ?? 0;
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, int> course_id => 0..100 (mọi ID đều có khoá)
     */
    public function percentsFor(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $totals = DB::table('lessons')
            ->whereIn('course_id', $courseIds)
            ->whereNull('deleted_at')
            ->groupBy('course_id')
            ->selectRaw('course_id, COUNT(*) as total')
            ->pluck('total', 'course_id');

        $done = DB::table('lesson_progress as lp')
            ->join('lessons as l', 'l.id', '=', 'lp.lesson_id')
            ->where('lp.user_id', $userId)
            ->whereIn('lp.course_id', $courseIds)
            ->where('lp.status', LessonProgressStatus::Completed->value)
            ->whereNull('l.deleted_at')
            ->groupBy('lp.course_id')
            ->selectRaw('lp.course_id, COUNT(*) as done')
            ->pluck('done', 'course_id');

        $result = [];
        foreach ($courseIds as $id) {
            $total = (int) ($totals[$id] ?? 0);
            $result[$id] = $total === 0 ? 0 : min(100, intdiv((int) ($done[$id] ?? 0) * 100, $total));
        }

        return $result;
    }

    /**
     * ID các bài đã hoàn thành của học sinh trong khóa (bài đã xoá mềm bị loại).
     *
     * @return list<int>
     */
    public function completedLessonIds(int $userId, int $courseId): array
    {
        return DB::table('lesson_progress as lp')
            ->join('lessons as l', 'l.id', '=', 'lp.lesson_id')
            ->where('lp.user_id', $userId)
            ->where('lp.course_id', $courseId)
            ->where('lp.status', LessonProgressStatus::Completed->value)
            ->whereNull('l.deleted_at')
            ->pluck('lp.lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
