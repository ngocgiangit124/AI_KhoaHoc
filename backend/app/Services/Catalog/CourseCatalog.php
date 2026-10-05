<?php

namespace App\Services\Catalog;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\SubjectStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Subject;
use App\Models\User;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Truy vấn danh mục công khai (US-002) + chi tiết (US-003). Chỉ khóa `published` chưa xoá; chuyên đề `hidden`
 * không bao giờ lộ ra (kể cả trong bộ lọc và danh sách chuyên đề của khóa).
 */
class CourseCatalog
{
    public const PER_PAGE = 25;

    private const MAX_SEARCH_WORDS = 8;

    /**
     * @param  list<int>  $subjectIds
     * @return LengthAwarePaginator<int, Course>
     */
    public function search(?int $grade, array $subjectIds, ?string $q, string $sort, int $page): LengthAwarePaginator
    {
        $query = $this->published()
            ->select([
                'courses.id', 'courses.title', 'courses.slug', 'courses.short_description', 'courses.grade_level',
                'courses.price', 'courses.thumbnail_path', 'courses.enrollments_count', 'courses.published_at',
            ])
            ->with([
                'subjects' => fn (Relation $r) => $this->activeSubjects($r),
                'teachers' => fn (Relation $r) => $r->select('users.id', 'users.name')
                    ->orderBy('course_teacher.created_at')->orderBy('users.id'),
            ]);

        if ($grade !== null) {
            $query->where('courses.grade_level', $grade);
        }

        $subjectIds = array_values(array_unique($subjectIds));
        if ($subjectIds !== []) {
            // Khóa thuộc ÍT NHẤT MỘT trong các chuyên đề đã chọn; chuyên đề ẩn/không tồn tại không khớp gì.
            $query->whereIn('courses.id', DB::table('course_subject as cs')
                ->join('subjects as s', 's.id', '=', 'cs.subject_id')
                ->whereIn('cs.subject_id', $subjectIds)
                ->where('s.status', SubjectStatus::Active->value)
                ->select('cs.course_id'));
        }

        $words = $this->searchWords($q);
        if ($words === [] && $q !== null && trim($q) !== '') {
            // Từ khoá không còn ký tự nào sau khi chuẩn hoá (CJK/emoji...) → không khớp khóa nào.
            $query->whereRaw('1 = 0');
        }
        foreach ($words as $word) {
            $query->where('courses.search_text', 'like', Like::contains($word));
        }

        $this->applySort($query, $sort);

        return $query->paginate(self::PER_PAGE, page: $page);
    }

    /**
     * Chi tiết công khai theo slug: chỉ `published` chưa xoá, ngược lại null (→ 404).
     */
    public function findPublished(string $slug): ?Course
    {
        return $this->published()
            ->where('courses.slug', $slug)
            ->with([
                'subjects' => fn (Relation $r) => $this->activeSubjects($r),
                'teachers' => fn (Relation $r) => $r->select('users.id', 'users.name', 'users.bio', 'users.avatar_path')
                    ->orderBy('course_teacher.created_at')->orderBy('users.id'),
                'chapters' => fn (Relation $r) => $r->select('id', 'course_id', 'title', 'position')
                    ->orderBy('position')->orderBy('id'),
                'chapters.lessons' => fn (Relation $r) => $r->select('id', 'chapter_id', 'title', 'position', 'duration_seconds', 'is_preview')
                    ->orderBy('position')->orderBy('id'),
            ])
            ->first();
    }

    /**
     * Trạng thái nút hành động cho học sinh đăng nhập (US-003 BR3/BR5/BR6). Khóa đã gỡ xuất bản vẫn trả cho
     * học sinh đang có quyền học (không 404); người khác → null (404).
     *
     * @return array{viewer_state: string, resume_lesson_id: int|null}|null
     */
    public function viewerState(string $slug, User $user): ?array
    {
        $course = Course::query()->where('slug', $slug)->first(['id', 'status', 'price']);
        if ($course === null) {
            return null;
        }

        /** @var EnrollmentStatus|null $enrollmentStatus */
        $enrollmentStatus = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::PendingApproval->value])
            ->orderByRaw('status = ? desc', [EnrollmentStatus::Active->value])
            ->value('status');
        $owned = $enrollmentStatus === EnrollmentStatus::Active;

        if ($course->status !== CourseStatus::Published && ! $owned) {
            return null;
        }

        if ($owned) {
            return ['viewer_state' => 'owned', 'resume_lesson_id' => $this->resumeLessonId($user, $course)];
        }

        $state = match (true) {
            $enrollmentStatus !== null => 'pending_approval',
            $course->price === 0 => 'can_register_free',
            // `in_cart` được nối ở T16 khi có bảng giỏ hàng.
            default => 'can_buy',
        };

        return ['viewer_state' => $state, 'resume_lesson_id' => null];
    }

    /**
     * Bài xem gần nhất (`lesson_progress.last_accessed_at`), chưa có thì bài đầu tiên theo thứ tự chương/bài.
     */
    private function resumeLessonId(User $user, Course $course): ?int
    {
        $recent = LessonProgress::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->where('lesson_progress.user_id', $user->id)
            ->where('lesson_progress.course_id', $course->id)
            ->whereNull('lessons.deleted_at')
            ->orderByDesc('lesson_progress.last_accessed_at')
            ->orderByDesc('lesson_progress.id')
            ->value('lessons.id');

        if ($recent !== null) {
            return (int) $recent;
        }

        $first = Lesson::query()
            ->join('chapters', 'chapters.id', '=', 'lessons.chapter_id')
            ->where('lessons.course_id', $course->id)
            ->whereNull('chapters.deleted_at')
            ->orderBy('chapters.position')->orderBy('chapters.id')
            ->orderBy('lessons.position')->orderBy('lessons.id')
            ->value('lessons.id');

        return $first === null ? null : (int) $first;
    }

    /**
     * @return Builder<Course>
     */
    private function published(): Builder
    {
        return Course::query()->where('courses.status', CourseStatus::Published->value);
    }

    /**
     * @param  Relation<Subject, Course, *>  $relation
     */
    private function activeSubjects(Relation $relation): void
    {
        $relation->where('subjects.status', SubjectStatus::Active->value)
            ->select('subjects.id', 'subjects.name', 'subjects.slug')
            ->orderBy('subjects.name')->orderBy('subjects.id');
    }

    /**
     * Chuẩn hoá giống `courses.search_text` (bỏ dấu, chữ thường), tách từ; mọi từ phải khớp (AND).
     *
     * @return list<string>
     */
    private function searchWords(?string $q): array
    {
        if ($q === null) {
            return [];
        }

        $ascii = mb_strtolower(Str::ascii(trim($q)));
        $words = preg_split('/\s+/u', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_SEARCH_WORDS);
    }

    /**
     * Mọi kiểu sắp xếp đều kết thúc bằng `id` để phân trang ổn định (không trùng/bỏ sót — AC6).
     *
     * @param  Builder<Course>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('courses.enrollments_count'),
            // Có `manual_order` lên trước (tăng dần), chưa set xếp sau.
            'featured' => $query->orderByRaw('courses.manual_order is null')->orderBy('courses.manual_order'),
            default => null,
        };

        $query->orderByDesc('courses.published_at')->orderByDesc('courses.id');
    }
}
