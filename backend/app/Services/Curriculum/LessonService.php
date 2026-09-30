<?php

namespace App\Services\Curriculum;

use App\Enums\VideoSource;
use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ExternalVideoLink;
use Illuminate\Support\Facades\DB;

/**
 * US-009 — CRUD bài học (BR10 video upload/link ngoài). Đây là nơi DUY NHẤT
 * ghi `video_source`/`external_provider`/`external_video_id`/`duration_seconds`
 * (không fillable — S17). KHÔNG BAO GIỜ gán `video_asset_id`: asset chỉ được
 * gán bởi luồng tạo phiên upload của CHÍNH bài đó (T11, S5); ở đây chỉ GIỮ
 * hoặc GỠ (đặt null) asset hiện có.
 */
class LessonService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * `course_id` suy ra từ chương (đã kiểm thuộc `$course` ở route binding +
     * khoá hàng), `position` = cuối chương.
     *
     * @param  array{title: string, is_preview: bool, video_source: string, external_url?: string|null, duration_seconds?: int|null}  $data
     */
    public function create(Course $course, Chapter $chapter, array $data, User $actor): Lesson
    {
        return DB::transaction(function () use ($course, $chapter, $data): Lesson {
            ContentLock::course($course);
            $lockedChapter = ContentLock::chapter($course, $chapter);

            $position = (int) Lesson::query()->where('chapter_id', $lockedChapter->getKey())->max('position') + 1;

            $lesson = new Lesson([
                'course_id' => $lockedChapter->course_id,
                'chapter_id' => $lockedChapter->getKey(),
                'title' => $data['title'],
                'position' => $position,
                'is_preview' => (bool) $data['is_preview'],
            ]);
            $this->applyVideo($lesson, $data);
            $lesson->save();

            $this->auditLogger->log('lesson.create', $lesson, $this->auditPayload($lesson));

            return $lesson;
        });
    }

    /**
     * @param  array{title: string, is_preview: bool, video_source: string, external_url?: string|null, duration_seconds?: int|null}  $data
     */
    public function update(Course $course, Chapter $chapter, Lesson $lesson, array $data, User $actor): Lesson
    {
        return DB::transaction(function () use ($course, $chapter, $lesson, $data): Lesson {
            ContentLock::course($course);
            $lockedChapter = ContentLock::chapter($course, $chapter);
            $locked = ContentLock::lesson($lockedChapter, $lesson);

            $before = $this->auditPayload($locked);

            $locked->title = $data['title'];
            $locked->is_preview = (bool) $data['is_preview'];
            $this->applyVideo($locked, $data);
            $locked->save();

            $this->auditLogger->log('lesson.update', $locked, [
                'before' => $before,
                'after' => $this->auditPayload($locked),
            ]);

            return $locked;
        });
    }

    public function delete(Course $course, Chapter $chapter, Lesson $lesson, User $actor): void
    {
        DB::transaction(function () use ($course, $chapter, $lesson): void {
            ContentLock::course($course);
            $lockedChapter = ContentLock::chapter($course, $chapter);
            $locked = ContentLock::lesson($lockedChapter, $lesson);

            $locked->delete();

            $this->auditLogger->log('lesson.delete', $locked, $this->auditPayload($locked));
        });
    }

    /**
     * `LessonRequest` đã bảo đảm: `external_link` ⇒ `is_preview = true` và
     * `external_url` hợp lệ; `upload` ⇒ không có `duration_seconds`. Parse lại
     * ở đây (nguồn sự thật DUY NHẤT là `ExternalVideoLink`) — không tin cờ nào
     * khác; URL người nhập KHÔNG được lưu.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyVideo(Lesson $lesson, array $data): void
    {
        $source = VideoSource::from($data['video_source']);
        $previous = $lesson->exists ? $lesson->video_source : null;

        switch ($source) {
            case VideoSource::ExternalLink:
                $link = ExternalVideoLink::parse($data['external_url'] ?? null);

                if ($link === null || ! $lesson->is_preview) {
                    // Không thể xảy ra nếu đi qua LessonRequest; chặn cứng nếu gọi Service trực tiếp.
                    throw new DomainException(
                        code: 'INVALID_EXTERNAL_LINK',
                        message: 'Link video ngoài không hợp lệ hoặc bài học không phải xem thử.',
                        status: 422,
                    );
                }

                $lesson->video_source = VideoSource::ExternalLink;
                $lesson->video_asset_id = null;
                $lesson->external_provider = $link['provider'];
                $lesson->external_video_id = $link['id'];
                $lesson->duration_seconds = $data['duration_seconds'] ?? null;

                break;

            case VideoSource::Upload:
                // Giữ asset/thời lượng nếu bài ĐÃ là upload (không đụng tới);
                // mới chuyển sang upload thì chờ luồng upload (T11) gán asset.
                if ($previous !== VideoSource::Upload) {
                    $lesson->video_asset_id = null;
                    $lesson->duration_seconds = null;
                }

                $lesson->video_source = VideoSource::Upload;
                $lesson->external_provider = null;
                $lesson->external_video_id = null;

                break;

            case VideoSource::None:
                $lesson->video_source = VideoSource::None;
                $lesson->video_asset_id = null;
                $lesson->external_provider = null;
                $lesson->external_video_id = null;
                $lesson->duration_seconds = $data['duration_seconds'] ?? null;

                break;
        }
    }

    /**
     * Không ghi URL/ID video vào audit (tránh lộ link nội dung); chỉ nguồn.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(Lesson $lesson): array
    {
        return [
            'course_id' => $lesson->course_id,
            'chapter_id' => $lesson->chapter_id,
            'title' => $lesson->title,
            'is_preview' => $lesson->is_preview,
            'video_source' => $lesson->video_source->value,
        ];
    }
}
