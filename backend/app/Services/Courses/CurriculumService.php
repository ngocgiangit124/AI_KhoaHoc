<?php

namespace App\Services\Courses;

use App\Enums\CourseStatus;
use App\Enums\VideoSource;
use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ExternalVideoLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chương/bài của khóa học (US-009 AC8, AC11, BR7). Mọi thao tác ghi KHOÁ dòng khóa học (`lockForUpdate`) để tạo/xoá/
 * sắp xếp đồng thời không làm lệch `position` hay tập ID. `course_id`, `chapter_id`, `position`, `video_asset_id`
 * chỉ đặt ở đây (S5), không từ request.
 */
class CurriculumService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ExternalVideoLink $links,
    ) {}

    /** @return Collection<int, Chapter> chương (chưa xoá) kèm bài (chưa xoá) theo đúng thứ tự hiển thị */
    public function tree(Course $course): Collection
    {
        return $course->chapters()
            ->with(['lessons' => fn ($q) => $q->with('videoAsset:id,status')->orderBy('position')->orderBy('id')])
            ->orderBy('position')->orderBy('id')
            ->get();
    }

    public function createChapter(Course $course, string $title): Chapter
    {
        return DB::transaction(function () use ($course, $title): Chapter {
            $this->lockCourse($course);

            $chapter = new Chapter(['title' => $title]);
            $chapter->forceFill([
                'course_id' => $course->getKey(),
                'position' => ((int) Chapter::query()->where('course_id', $course->getKey())->max('position')) + 1,
            ])->save();

            $this->audit->log('chapter.create', $chapter, ['course_id' => $course->getKey(), 'title' => $title]);

            return $chapter;
        });
    }

    public function updateChapter(Course $course, Chapter $chapter, string $title): Chapter
    {
        return DB::transaction(function () use ($course, $chapter, $title): Chapter {
            $this->lockCourse($course);
            $locked = $this->freshChapter($course, $chapter);
            $from = $locked->title;

            if ($from !== $title) {
                $locked->forceFill(['title' => $title])->save();
                $this->audit->log('chapter.update', $locked, ['course_id' => $course->getKey(), 'title' => ['from' => $from, 'to' => $title]]);
            }

            return $locked;
        });
    }

    /**
     * Xoá mềm chương và các bài của chương. Có bài đã có tiến độ học sinh → 409 (giữ lịch sử học).
     */
    public function deleteChapter(Course $course, Chapter $chapter): void
    {
        DB::transaction(function () use ($course, $chapter): void {
            $lockedCourse = $this->lockCourse($course);
            $locked = Chapter::query()->where('course_id', $course->getKey())->whereKey($chapter->getKey())->first();

            if ($locked === null) {
                return; // đã bị xoá đồng thời: idempotent
            }

            $lessonIds = $locked->lessons()->pluck('id');
            $this->guardLastLesson($lockedCourse, $lessonIds->all());

            if ($lessonIds->isNotEmpty() && DB::table('lesson_progress')->whereIn('lesson_id', $lessonIds)->exists()) {
                throw new DomainException(
                    'CHAPTER_HAS_PROGRESS',
                    'Chương có bài học đã có học sinh học nên không thể xoá.',
                    409,
                );
            }

            // Quiz gắn với chương/các bài của chương bị xoá mềm theo (T21; câu hỏi giữ nguyên cho lượt làm cũ).
            Quiz::query()->where('course_id', $course->getKey())
                ->where(fn ($q) => $q->where('chapter_id', $locked->getKey())->orWhereIn('lesson_id', $lessonIds))
                ->delete();
            $locked->lessons()->delete();
            $locked->delete();

            $this->audit->log('chapter.delete', $locked, [
                'course_id' => $course->getKey(),
                'title' => $locked->title,
                'lessons_deleted' => $lessonIds->count(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data  title, is_preview, video_source, external_url, duration_seconds (đã validate)
     */
    public function createLesson(Course $course, Chapter $chapter, array $data): Lesson
    {
        return DB::transaction(function () use ($course, $chapter, $data): Lesson {
            $this->lockCourse($course);
            $parent = $this->freshChapter($course, $chapter);

            $lesson = new Lesson;
            $lesson->forceFill([
                'course_id' => $course->getKey(),
                'chapter_id' => $parent->getKey(),
                'position' => ((int) Lesson::query()->where('chapter_id', $parent->getKey())->max('position')) + 1,
            ]);
            $lesson->title = (string) $data['title'];
            $lesson->is_preview = false;
            $lesson->video_source = VideoSource::None;
            $this->applyVideoFields($lesson, $data);
            $lesson->save();

            $this->audit->log('lesson.create', $lesson, [
                'course_id' => $course->getKey(),
                'chapter_id' => $parent->getKey(),
                'title' => $lesson->title,
                'video_source' => $lesson->video_source->value,
                'is_preview' => $lesson->is_preview,
            ]);

            return $lesson;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLesson(Course $course, Chapter $chapter, Lesson $lesson, array $data): Lesson
    {
        return DB::transaction(function () use ($course, $chapter, $lesson, $data): Lesson {
            $this->lockCourse($course);
            $locked = Lesson::query()->where('course_id', $course->getKey())->where('chapter_id', $chapter->getKey())
                ->whereKey($lesson->getKey())->firstOrFail();

            if (array_key_exists('title', $data)) {
                $locked->title = (string) $data['title'];
            }

            $this->applyVideoFields($locked, $data);

            $changes = [];

            foreach ($locked->getDirty() as $field => $to) {
                $from = $locked->getOriginal($field);
                $changes[$field] = [
                    'from' => $from instanceof \BackedEnum ? $from->value : $from,
                    'to' => $to instanceof \BackedEnum ? $to->value : $to,
                ];
            }

            if ($changes !== []) {
                $locked->save();
                $this->audit->log('lesson.update', $locked, ['course_id' => $course->getKey(), 'fields' => array_keys($changes)] + $changes);
            }

            return $locked;
        });
    }

    /** Xoá mềm bài. Bài đã có tiến độ học sinh → 409 (giữ lịch sử học). */
    public function deleteLesson(Course $course, Chapter $chapter, Lesson $lesson): void
    {
        DB::transaction(function () use ($course, $chapter, $lesson): void {
            $lockedCourse = $this->lockCourse($course);
            $locked = Lesson::query()->where('course_id', $course->getKey())->where('chapter_id', $chapter->getKey())
                ->whereKey($lesson->getKey())->first();

            if ($locked === null) {
                return; // đã bị xoá đồng thời: idempotent
            }

            $this->guardLastLesson($lockedCourse, [$locked->getKey()]);

            if (DB::table('lesson_progress')->where('lesson_id', $locked->getKey())->exists()) {
                throw new DomainException(
                    'LESSON_HAS_PROGRESS',
                    'Bài học đã có học sinh học nên không thể xoá.',
                    409,
                );
            }

            Quiz::query()->where('course_id', $course->getKey())->where('lesson_id', $locked->getKey())->delete();
            $locked->delete();

            $this->audit->log('lesson.delete', $locked, [
                'course_id' => $course->getKey(),
                'chapter_id' => $chapter->getKey(),
                'title' => $locked->title,
            ]);
        });
    }

    /**
     * Sắp xếp lại toàn bộ chương/bài (kể cả chuyển bài sang chương khác). Tập ID gửi lên phải bằng đúng tập hiện có
     * (không thiếu, không thừa, không ID của khóa khác) — kiểm trong lúc khoá dòng khóa nên an toàn khi 2 người
     * kéo thả/tạo/xoá cùng lúc: người sau nhận 422 `CURRICULUM_MISMATCH` và phải tải lại cây.
     *
     * @param  list<array{chapter_id: int, lesson_ids: list<int>}>  $items
     */
    public function reorder(Course $course, array $items): void
    {
        DB::transaction(function () use ($course, $items): void {
            $this->lockCourse($course);

            $chapters = Chapter::query()->where('course_id', $course->getKey())->pluck('position', 'id');
            $lessons = Lesson::query()->where('course_id', $course->getKey())->get(['id', 'chapter_id', 'position'])->keyBy('id');

            $sentChapters = array_column($items, 'chapter_id');
            $sentLessons = array_merge([], ...array_column($items, 'lesson_ids'));

            if ($this->differs($chapters->keys()->all(), $sentChapters) || $this->differs($lessons->keys()->all(), $sentLessons)) {
                throw new DomainException(
                    'CURRICULUM_MISMATCH',
                    'Danh sách chương/bài không khớp với dữ liệu hiện tại của khóa học. Vui lòng tải lại trang.',
                    422,
                );
            }

            $moved = 0;

            foreach ($items as $i => $item) {
                $chapterPos = $i + 1;

                if ((int) $chapters[$item['chapter_id']] !== $chapterPos) {
                    Chapter::query()->whereKey($item['chapter_id'])->update(['position' => $chapterPos]);
                    $moved++;
                }

                foreach ($item['lesson_ids'] as $j => $lessonId) {
                    $lessonPos = $j + 1;
                    $current = $lessons[$lessonId];

                    if ((int) $current->chapter_id !== $item['chapter_id'] || (int) $current->position !== $lessonPos) {
                        Lesson::query()->whereKey($lessonId)->update(['chapter_id' => $item['chapter_id'], 'position' => $lessonPos]);
                        $moved++;
                    }
                }
            }

            $this->audit->log('curriculum.reorder', $course, [
                'chapters' => count($items),
                'lessons' => count($sentLessons),
                'rows_changed' => $moved,
            ]);
        });
    }

    /**
     * Áp title/is_preview/video_* từ dữ liệu đã validate, kiểm lại bất biến dưới khoá (request đã kiểm trên bản đọc cũ).
     *
     * @param  array<string, mixed>  $data
     */
    private function applyVideoFields(Lesson $lesson, array $data): void
    {
        if (array_key_exists('is_preview', $data)) {
            $lesson->is_preview = (bool) $data['is_preview'];
        }

        $source = isset($data['video_source']) ? VideoSource::from((string) $data['video_source']) : ($lesson->video_source ?? VideoSource::None);

        if ($source === VideoSource::Upload && $lesson->video_asset_id === null) {
            throw new DomainException('VIDEO_ASSET_REQUIRED', 'Bài học chưa có video tải lên.', 422);
        }

        if ($source === VideoSource::ExternalLink) {
            if (! $lesson->is_preview) {
                throw new DomainException('EXTERNAL_LINK_PREVIEW_ONLY', 'Link video ngoài chỉ dùng được cho bài học xem thử.', 422);
            }

            $url = $data['external_url'] ?? null;

            if (is_string($url) && $url !== '') {
                $parsed = $this->links->parse($url);

                if ($parsed === null) {
                    throw new DomainException('EXTERNAL_URL_INVALID', 'Link video không hợp lệ. Chỉ hỗ trợ link https của YouTube hoặc Vimeo.', 422);
                }

                $lesson->external_provider = $parsed['provider'];
                $lesson->external_video_id = $parsed['id'];
            } elseif ($lesson->external_video_id === null) {
                throw new DomainException('EXTERNAL_URL_INVALID', 'Vui lòng nhập link video YouTube hoặc Vimeo.', 422);
            }
        } else {
            $lesson->external_provider = null;
            $lesson->external_video_id = null;
        }

        if ($source !== VideoSource::Upload) {
            $lesson->video_asset_id = null; // gỡ liên kết; asset do T11 dọn
        }

        $lesson->video_source = $source;

        // Upload: thời lượng do webhook video ghi (T11), không nhận từ client.
        if ($source !== VideoSource::Upload && array_key_exists('duration_seconds', $data)) {
            $lesson->duration_seconds = $data['duration_seconds'] === null ? null : (int) $data['duration_seconds'];
        }
    }

    private function lockCourse(Course $course): Course
    {
        return Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Khóa đang xuất bản phải còn ít nhất 1 bài (BR3): không cho xoá bài/chương cuối. Gọi khi đã khoá dòng khóa học.
     *
     * @param  array<int, int|string>  $deletingLessonIds
     */
    private function guardLastLesson(Course $lockedCourse, array $deletingLessonIds): void
    {
        if ($lockedCourse->status !== CourseStatus::Published) {
            return;
        }

        $remaining = Lesson::query()->where('course_id', $lockedCourse->getKey())
            ->whereNotIn('id', $deletingLessonIds)->exists();

        if (! $remaining) {
            throw new DomainException(
                'COURSE_LAST_LESSON',
                'Khóa học đang xuất bản phải còn ít nhất 1 chương và 1 bài học. Hãy ngừng bán khóa học trước khi xoá nội dung cuối cùng.',
                409,
            );
        }
    }

    private function freshChapter(Course $course, Chapter $chapter): Chapter
    {
        return Chapter::query()->where('course_id', $course->getKey())->whereKey($chapter->getKey())->firstOrFail();
    }

    /**
     * @param  list<int|string>  $existing
     * @param  list<int>  $sent
     */
    private function differs(array $existing, array $sent): bool
    {
        $a = array_map('intval', $existing);
        $b = array_map('intval', $sent);
        sort($a);
        sort($b);

        return $a !== $b;
    }
}
