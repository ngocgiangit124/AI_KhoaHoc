<?php

namespace App\Services\Curriculum;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-009 BR7/AC8, S5 — sắp xếp lại chương/bài (kể cả chuyển bài sang chương
 * khác) trong 1 transaction.
 *
 * Quy tắc S5: tập `chapter_id` và tập `lesson_id` gửi lên phải BẰNG ĐÚNG tập
 * hiện có (chưa xoá) của khóa học — không thêm (ID khóa khác/đã xoá/không
 * tồn tại), không thiếu, không trùng. Kiểm DƯỚI KHOÁ HÀNG (`courses`, rồi
 * toàn bộ `chapters`, `lessons` của khóa `FOR UPDATE`) để 2 request sắp xếp,
 * hoặc sắp xếp + xoá/tạo song song, không thể chen nhau làm lệch tập.
 * Sai → 422 với thông báo CHUNG (không tiết lộ ID thuộc khóa khác).
 */
class CurriculumOrderService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<array{chapter_id: int, lesson_ids: list<int>}>  $items
     */
    public function reorder(Course $course, array $items): void
    {
        DB::transaction(function () use ($course, $items): void {
            $locked = ContentLock::course($course);

            /** @var array<int, int> $existingChapters id => position */
            $existingChapters = Chapter::query()
                ->where('course_id', $locked->getKey())
                ->lockForUpdate()
                ->pluck('position', 'id')
                ->all();

            /** @var array<int, array{chapter_id: int, position: int}> $existingLessons */
            $existingLessons = Lesson::query()
                ->where('course_id', $locked->getKey())
                ->lockForUpdate()
                ->get(['id', 'chapter_id', 'position'])
                ->mapWithKeys(fn (Lesson $l): array => [
                    $l->id => ['chapter_id' => $l->chapter_id, 'position' => $l->position],
                ])
                ->all();

            $chapterIds = array_map(fn (array $i): int => (int) $i['chapter_id'], $items);
            $lessonIds = [];

            foreach ($items as $item) {
                foreach ($item['lesson_ids'] as $lessonId) {
                    $lessonIds[] = (int) $lessonId;
                }
            }

            if (! $this->sameSet($chapterIds, array_keys($existingChapters))
                || ! $this->sameSet($lessonIds, array_keys($existingLessons))) {
                throw ValidationException::withMessages([
                    'curriculum' => 'Danh sách chương/bài học không khớp với nội dung hiện tại của khóa học. Vui lòng tải lại trang.',
                ]);
            }

            $movedLessons = 0;

            foreach ($items as $chapterIndex => $item) {
                $chapterId = (int) $item['chapter_id'];
                $chapterPosition = $chapterIndex + 1;

                if ($existingChapters[$chapterId] !== $chapterPosition) {
                    Chapter::query()->whereKey($chapterId)->update(['position' => $chapterPosition]);
                }

                foreach ($item['lesson_ids'] as $lessonIndex => $lessonId) {
                    $lessonPosition = $lessonIndex + 1;
                    $current = $existingLessons[(int) $lessonId];

                    if ($current['chapter_id'] !== $chapterId || $current['position'] !== $lessonPosition) {
                        Lesson::query()->whereKey((int) $lessonId)->update([
                            'chapter_id' => $chapterId,
                            'position' => $lessonPosition,
                        ]);
                        $movedLessons++;
                    }
                }
            }

            $this->auditLogger->log('curriculum.reorder', $locked, [
                'chapters' => count($chapterIds),
                'lessons' => count($lessonIds),
                'lessons_changed' => $movedLessons,
            ]);
        });
    }

    /**
     * Cùng tập, KHÔNG trùng, KHÔNG thừa/thiếu.
     *
     * @param  list<int>  $submitted
     * @param  list<int|string>  $existing
     */
    private function sameSet(array $submitted, array $existing): bool
    {
        if (count($submitted) !== count(array_unique($submitted))) {
            return false;
        }

        $existing = array_map('intval', $existing);
        sort($submitted);
        sort($existing);

        return $submitted === $existing;
    }
}
