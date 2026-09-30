<?php

namespace App\Services\Curriculum;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * US-009 — CRUD chương. Mọi thao tác chạy trong transaction và KHOÁ HÀNG
 * `courses` trước (thứ tự khoá thống nhất: course → chapter → lesson, dùng
 * chung cho `LessonService`/`CurriculumOrderService`, tránh deadlock), rồi
 * đọc lại chương bằng `lockForUpdate()` để chắc nó vẫn còn/vẫn thuộc khóa này
 * (route binding đã chạy TRƯỚC khi khoá — có thể đã bị xoá/chuyển bởi request
 * song song).
 */
class ChapterService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * BR7 — chương mới luôn xếp CUỐI; `position` không bao giờ nhận từ request.
     * `course_id` lấy từ route model (đã `scopeBindings`), không từ payload.
     *
     * @param  array{title: string}  $data
     */
    public function create(Course $course, array $data): Chapter
    {
        return DB::transaction(function () use ($course, $data): Chapter {
            $locked = ContentLock::course($course);

            $position = (int) Chapter::query()->where('course_id', $locked->getKey())->max('position') + 1;

            $chapter = new Chapter([
                'course_id' => $locked->getKey(),
                'title' => $data['title'],
                'position' => $position,
            ]);
            $chapter->save();

            $this->auditLogger->log('chapter.create', $chapter, [
                'course_id' => $locked->getKey(),
                'title' => $chapter->title,
            ]);

            return $chapter;
        });
    }

    /**
     * @param  array{title: string}  $data
     */
    public function update(Course $course, Chapter $chapter, array $data): Chapter
    {
        return DB::transaction(function () use ($course, $chapter, $data): Chapter {
            ContentLock::course($course);
            $locked = ContentLock::chapter($course, $chapter);

            $before = $locked->title;
            $locked->title = $data['title'];
            $locked->save();

            $this->auditLogger->log('chapter.update', $locked, [
                'course_id' => $course->getKey(),
                'title' => ['before' => $before, 'after' => $locked->title],
            ]);

            return $locked;
        });
    }

    /**
     * US-009 "Trường hợp biên & lỗi": xoá chương có bài học thì cascade xoá
     * mềm các bài — CHẶN (409) nếu khóa học đang có học sinh `active` đang
     * học. Chương rỗng luôn xoá được. Xoá mềm nên tiến độ học cũ được giữ.
     */
    public function delete(Course $course, Chapter $chapter): void
    {
        DB::transaction(function () use ($course, $chapter): void {
            $lockedCourse = ContentLock::course($course);
            $locked = ContentLock::chapter($course, $chapter);

            $lessonIds = Lesson::query()
                ->where('chapter_id', $locked->getKey())
                ->lockForUpdate()
                ->pluck('id');

            if ($lessonIds->isNotEmpty()) {
                ContentGuard::assertNoActiveLearners(
                    $lockedCourse,
                    'CHAPTER_HAS_ACTIVE_LEARNERS',
                    'Khóa học đang có học sinh học nên không thể xoá chương còn bài học.',
                );
                ContentGuard::assertPublishedKeepsContent($lockedCourse, $lessonIds->map(fn ($id): int => (int) $id)->all());

                Lesson::query()->whereIn('id', $lessonIds)->delete();
            }

            $locked->delete();

            $this->auditLogger->log('chapter.delete', $locked, [
                'course_id' => $course->getKey(),
                'title' => $locked->title,
                'lessons_deleted' => $lessonIds->count(),
            ]);
        });
    }
}
