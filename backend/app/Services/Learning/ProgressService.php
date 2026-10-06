<?php

namespace App\Services\Learning;

use App\Enums\EnrollmentStatus;
use App\Enums\LessonProgressStatus;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Support\AtomicCounter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ghi tiến độ học (ADR-002 §5, DBA 2.5). Không tin client:
 *  - số giây "đã xem" cộng thêm bị chặn bởi thời gian thật trôi qua kể từ heartbeat trước (tối đa 2x + bù trễ);
 *  - heartbeat đến dồn dập/gửi lại cùng vị trí không được cộng thêm (idempotent);
 *  - quyền kiểm lại mỗi lần (thu hồi giữa phiên => 403 COURSE_NOT_OWNED), bài đã xoá mềm => 404.
 *
 * Khoá (thứ tự cố định, tránh deadlock): khóa học (S) -> bài (S) -> dòng lesson_progress (X).
 * Khoá S trên dòng khóa học chặn race với xoá bài/chương của T09 (CurriculumService khoá X dòng khóa rồi mới
 * kiểm `lesson_progress`): xoá phải đợi heartbeat đang chạy, hoặc heartbeat thấy bài đã xoá. Nhiều học sinh
 * cùng khoá S không chặn nhau; chỉ ghi vào dòng khóa học (đổi giá, enrollments_count) mới phải chờ chốc lát.
 */
class ProgressService
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly CourseProgressService $courseProgress,
    ) {}

    /**
     * @return array{status: string, completed: bool, course_percent: int}
     *
     * @throws DomainException 403 COURSE_NOT_OWNED, 404 NOT_FOUND
     */
    public function heartbeat(User $user, Lesson $lesson, int $positionSeconds, int $watchedDeltaSeconds): array
    {
        $courseId = (int) $lesson->course_id;
        $userId = (int) $user->getKey();

        $status = DB::transaction(fn () => $this->record($userId, (int) $lesson->getKey(), $courseId, $positionSeconds, $watchedDeltaSeconds), 3);

        $this->touchEnrollment($userId, $courseId);

        return [
            'status' => $status->value,
            'completed' => $status === LessonProgressStatus::Completed,
            'course_percent' => $this->courseProgress->percent($userId, $courseId),
        ];
    }

    private function record(int $userId, int $lessonId, int $courseId, int $position, int $delta): LessonProgressStatus
    {
        $course = Course::query()->whereKey($courseId)->sharedLock()->first(['id']);
        $lesson = $course === null ? null : Lesson::query()->whereKey($lessonId)->where('course_id', $courseId)->sharedLock()->first();

        if ($lesson === null) {
            throw $this->access->notFound();
        }

        // Quyền kiểm sau khi giữ khoá: bài còn sống thì mới có quyền để nói tới.
        if (! $this->access->ownsCourse($userId, $courseId)) {
            throw $this->access->notOwned();
        }

        $cfg = (array) config('learning.heartbeat');

        // Đọc khoá X trước; chỉ khi chưa có dòng mới INSERT IGNORE rồi đọc khoá lại. KHÔNG insertOrIgnore trước:
        // khi dòng đã có, INSERT IGNORE giữ khoá S trên unique key, 2 heartbeat cùng lúc cùng nâng lên X => deadlock.
        $find = fn () => LessonProgress::query()->where('user_id', $userId)->where('lesson_id', $lessonId)->lockForUpdate()->first();
        $row = $find();

        if ($row === null) {
            DB::table('lesson_progress')->insertOrIgnore([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
                'course_id' => $courseId,
                'watched_seconds' => 0,
                'last_position_seconds' => 0,
                'status' => LessonProgressStatus::InProgress->value,
                'last_accessed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = $find() ?? throw new \RuntimeException('lesson_progress biến mất sau khi insert.');
        }

        // Lấy giờ SAU khi đã giữ khoá dòng: heartbeat xếp hàng chờ luôn thấy mốc của người đi trước.
        $now = now();
        $nowTs = $now->getTimestamp();

        $duration = (int) $lesson->duration_seconds;
        $position = $duration > 0 ? min($position, $duration) : $position;

        $elapsed = $row->last_heartbeat_at === null
            ? (int) $cfg['first_interval_seconds']
            : max(0, $nowTs - $row->last_heartbeat_at->getTimestamp());

        $cap = $elapsed * (int) $cfg['max_speed'] + ($elapsed >= (int) $cfg['min_interval_seconds'] ? (int) $cfg['slack_seconds'] : 0);

        // Gửi lại đúng heartbeat vừa rồi (retry mạng): không cộng thêm.
        if ($row->last_heartbeat_at !== null && $elapsed < (int) $cfg['replay_window_seconds'] && $position === $row->last_position_seconds) {
            $cap = 0;
        }

        $credited = $this->capByUser($userId, max(0, min($delta, $cap)), $cfg);
        $watched = $row->watched_seconds + $credited;
        if ($duration > 0) {
            $watched = min($watched, $duration);
        }

        $changes = [
            'watched_seconds' => $watched,
            'last_position_seconds' => $position,
            'last_accessed_at' => $now,
            'last_heartbeat_at' => $now,
            'updated_at' => $now,
        ];

        // Không bao giờ quay lại in_progress; bài chưa có thời lượng thì không tự hoàn thành.
        if ($row->status === LessonProgressStatus::InProgress && $duration > 0
            && $watched >= (int) ceil($duration * (float) config('learning.complete_ratio'))) {
            $changes['status'] = LessonProgressStatus::Completed->value;
            $changes['completed_at'] = $now;
        }

        LessonProgress::query()->whereKey($row->getKey())->update($changes);

        return LessonProgressStatus::from((string) ($changes['status'] ?? $row->status->value));
    }

    /**
     * Trần cộng giây theo người học trên mọi bài (cụm 2 L1). Giữ chỗ nguyên tử bằng `AtomicCounter::add`, phần vượt
     * trần được hoàn và cộng 0 (không báo lỗi để player không vỡ). Khoá cache, không phải khoá DB: không ảnh hưởng thứ tự khoá.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function capByUser(int $userId, int $credited, array $cfg): int
    {
        if ($credited <= 0) {
            return 0;
        }

        $window = max(1, (int) ($cfg['user_credit_window_seconds'] ?? 60));
        $limit = ($cfg['user_credit_cap_seconds'] ?? null) === null
            ? (int) $cfg['max_speed'] * ($window + (int) $cfg['first_interval_seconds']) + (int) $cfg['slack_seconds']
            : (int) $cfg['user_credit_cap_seconds'];

        if ($limit <= 0) {
            return $credited;
        }

        $key = 'hb-credit:u:'.$userId;
        $total = AtomicCounter::add($key, $credited, $window);

        if ($total <= $limit) {
            return $credited;
        }

        $excess = min($credited, $total - $limit);
        AtomicCounter::add($key, -$excess, $window);

        return $credited - $excess;
    }

    /**
     * `enrollments.last_accessed_at`: tối đa 1 lần/5 phút. Chỉ là thống kê nên "best effort": tìm ID bằng đọc thường rồi
     * UPDATE theo khoá chính (cùng thứ tự khoá PK -> chỉ mục phụ như EnrollmentService, tránh deadlock khi thu hồi
     * đồng thời) và nuốt lỗi khoá nếu vẫn xảy ra (heartbeat đã ghi tiến độ xong, không được trả 500 vì việc này).
     */
    private function touchEnrollment(int $userId, int $courseId): void
    {
        $threshold = now()->subMinutes((int) config('learning.heartbeat.enrollment_touch_minutes'));

        try {
            $id = DB::table('enrollments')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->where('status', EnrollmentStatus::Active->value)
                ->where(fn ($q) => $q->whereNull('last_accessed_at')->orWhere('last_accessed_at', '<', $threshold))
                ->value('id');

            if ($id !== null) {
                DB::table('enrollments')->where('id', $id)->where('status', EnrollmentStatus::Active->value)->update(['last_accessed_at' => now()]);
            }
        } catch (QueryException $e) {
            Log::debug('learning.touch_enrollment_skipped', ['user_id' => $userId, 'course_id' => $courseId, 'error' => $e->getCode()]);
        }
    }
}
