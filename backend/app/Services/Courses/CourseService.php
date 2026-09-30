<?php

namespace App\Services\Courses;

use App\Enums\CourseStatus;
use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\HtmlSanitizer;
use App\Services\Uploads\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * US-009 — CRUD/publish/unpublish/xoá khóa học (host admin-api, `staff`).
 * Đây là nơi DUY NHẤT đổi `status`/`published_at`/`manual_order` của
 * `Course` (S17 — không nằm trong `$fillable`).
 */
class CourseService
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ImageUploadService $images,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * BR1/AC1/AC9 — luôn tạo `status = draft`. Giáo Viên tự tạo (BR8) tự
     * động là giáo viên phụ trách DUY NHẤT, bỏ qua hoàn toàn `teacher_ids`
     * gửi lên (S5/S17 — không tin request cho hành vi cấp quyền).
     *
     * @param  array{
     *     title: string,
     *     grade_level: int,
     *     subject_ids: list<int>,
     *     short_description: string,
     *     description?: string|null,
     *     price: int,
     *     thumbnail: UploadedFile,
     *     teacher_ids?: list<int>,
     * }  $data
     */
    public function create(array $data, User $actor): Course
    {
        return DB::transaction(function () use ($data, $actor): Course {
            $course = new Course([
                'title' => $data['title'],
                'slug' => $this->uniqueSlug($data['title']),
                'short_description' => $data['short_description'],
                'description' => $this->sanitizer->sanitize($data['description'] ?? null),
                'grade_level' => $data['grade_level'],
                'price' => $data['price'],
            ]);
            $course->status = CourseStatus::Draft;
            $course->created_by = $actor->getKey();
            $course->thumbnail_path = $this->images->store($data['thumbnail']);
            $course->save();

            $course->subjects()->attach(array_values(array_unique($data['subject_ids'])));

            // BR8 — GV tự tạo: LUÔN chỉ chính mình, không đọc `teacher_ids`
            // của request dù có gửi lên (api-contract §2.5 "GV gửi thì bị bỏ
            // qua, tự thêm chính mình").
            $teacherIds = $actor->isTeacher()
                ? [$actor->getKey()]
                : array_values(array_unique($data['teacher_ids'] ?? []));

            $course->teachers()->attach($this->teacherPivotData($teacherIds, $actor));

            $this->auditLogger->log('course.create', $course, [
                'title' => $course->title,
                'status' => $course->status->value,
                'price' => $course->price,
                'grade_level' => $course->grade_level,
            ]);

            return $course;
        });
    }

    /**
     * `UpdateCourseRequest` đã giới hạn field nào role/trạng thái publish nào
     * được gửi (2 bộ rule staff/GV — S5/S17); Service chỉ áp dụng CHÍNH XÁC
     * những key thực sự có mặt trong `$data` (bỏ qua hoàn toàn key thừa nếu
     * lỡ có), KHÔNG tự suy luận lại quyền ở đây (single source of truth về
     * field nào hợp lệ nằm ở FormRequest, đã test riêng).
     *
     * `status`, `manual_order`, `teacher_ids` KHÔNG bao giờ được xử lý ở đây
     * — luôn đi qua `publish()`/`unpublish()`/`updateManualOrder()`/
     * `CourseTeacherService::sync()`.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Course $course, array $data, User $actor): Course
    {
        return DB::transaction(function () use ($course, $data): Course {
            $priceChange = null;

            if (array_key_exists('title', $data)) {
                $course->title = $data['title'];
            }

            if (array_key_exists('short_description', $data)) {
                $course->short_description = $data['short_description'];
            }

            if (array_key_exists('description', $data)) {
                $course->description = $this->sanitizer->sanitize($data['description']);
            }

            if (array_key_exists('grade_level', $data)) {
                $course->grade_level = $data['grade_level'];
            }

            if (array_key_exists('price', $data) && $data['price'] !== $course->price) {
                $priceChange = ['before' => $course->price, 'after' => $data['price']];
                $course->price = $data['price'];
            }

            if (array_key_exists('thumbnail', $data) && $data['thumbnail'] instanceof UploadedFile) {
                $previousThumbnail = $course->thumbnail_path;
                $course->thumbnail_path = $this->images->store($data['thumbnail']);
                $this->images->delete($previousThumbnail);
            }

            $course->save();

            if (array_key_exists('subject_ids', $data)) {
                $course->subjects()->sync(array_values(array_unique($data['subject_ids'])));
            }

            if ($priceChange !== null) {
                $this->auditLogger->log('course.price.change', $course, $priceChange);
            }

            $this->auditLogger->log('course.update', $course, ['fields' => array_keys($data)]);

            return $course;
        });
    }

    /**
     * BR4/BR5/AC4/AC5 — có ít nhất 1 `enrollment` (bất kể trạng thái — kể cả
     * `rejected`/`revoked` vẫn là dấu vết học sinh đã từng tương tác thật với
     * khóa học) thì chặn xoá cứng/mềm, chỉ còn đường "Ngừng bán". Không xoá
     * cứng bao giờ — Model dùng `SoftDeletes`; "xoá mềm" ở đây cascade thủ
     * công sang `chapters`/`lessons` (migration chỉ `cascadeOnDelete()` ở
     * TẦNG DB cho DELETE thật — vô hiệu với soft delete, xem
     * `docs/db/T07-review.md`).
     */
    public function delete(Course $course): void
    {
        if (Enrollment::query()->where('course_id', $course->getKey())->exists()) {
            throw new DomainException(
                code: 'COURSE_HAS_ENROLLMENT',
                message: 'Khóa học đã có học sinh mua/đăng ký nên không thể xoá. Hãy chuyển sang "Ngừng bán".',
                status: 409,
            );
        }

        DB::transaction(function () use ($course): void {
            Lesson::query()->where('course_id', $course->getKey())->delete();
            Chapter::query()->where('course_id', $course->getKey())->delete();
            $course->delete();
        });

        $this->auditLogger->log('course.delete', $course, ['title' => $course->title]);
    }

    /**
     * BR2/BR3/AC2/AC3 — chặn nếu chưa có tối thiểu 1 chương VÀ 1 bài học.
     * `published_at` chỉ set ở LẦN publish ĐẦU TIÊN (data-model §3.2 "Set lần
     * đầu publish") — publish lại sau khi unpublish KHÔNG đổi mốc thời gian
     * này (giữ ổn định cho sort "mới nhất"/hiển thị "ngày phát hành").
     */
    public function publish(Course $course): Course
    {
        if ($course->status === CourseStatus::Published) {
            return $course;
        }

        if (! $course->chapters()->exists() || ! $course->lessons()->exists()) {
            throw new DomainException(
                code: 'COURSE_NOT_PUBLISHABLE',
                message: 'Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.',
                status: 422,
            );
        }

        $course->status = CourseStatus::Published;
        $course->published_at ??= now();
        $course->save();

        $this->auditLogger->log('course.publish', $course);

        return $course;
    }

    /**
     * BR2/BR4 — chỉ có ý nghĩa khi khóa ĐANG `published`; unpublish 1 khóa
     * chưa từng xuất bản (`draft`) không phải nghiệp vụ hợp lệ (không có gì
     * để "ngừng bán").
     */
    public function unpublish(Course $course): Course
    {
        if ($course->status !== CourseStatus::Published) {
            throw new DomainException(
                code: 'COURSE_NOT_PUBLISHED',
                message: 'Khóa học chưa được xuất bản nên không thể ngừng bán.',
                status: 409,
            );
        }

        $course->status = CourseStatus::Unpublished;
        $course->save();

        $this->auditLogger->log('course.unpublish', $course);

        return $course;
    }

    /**
     * US-002 BR6 ("Nổi bật") — chỉ staff (`CoursePolicy::updateManualOrder`).
     */
    public function updateManualOrder(Course $course, ?int $order): Course
    {
        $course->manual_order = $order;
        $course->save();

        $this->auditLogger->log('course.manual_order.update', $course, ['manual_order' => $order]);

        return $course;
    }

    /**
     * data-model §3.2 — `slug` unique TRÊN TOÀN BỘ bảng kể cả dòng đã xoá
     * mềm (`docs/db/T07-review.md`: "T08 đảm bảo hàm sinh slug duy nhất phải
     * kiểm tra trùng bằng `Course::withTrashed()`, không chỉ `Course::query()`").
     * Slug CHỈ sinh lúc tạo — không tự đổi khi sửa `title` sau đó (tránh phá
     * vỡ URL công khai/SEO đã chia sẻ, US-002/US-003).
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'khoa-hoc';
        }

        $slug = $base;
        $suffix = 2;

        while (Course::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  list<int>  $teacherIds
     * @return array<int, array{added_by: int, created_at: Carbon}>
     */
    private function teacherPivotData(array $teacherIds, User $actor): array
    {
        $pivot = [];

        foreach ($teacherIds as $teacherId) {
            $pivot[$teacherId] = ['added_by' => $actor->getKey(), 'created_at' => now()];
        }

        return $pivot;
    }
}
