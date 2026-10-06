<?php

namespace App\Services\Courses;

use App\Enums\CourseStatus;
use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\ImageUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nghiệp vụ khóa học quản trị (US-009). `status`, `published_at`, `manual_order`, `created_by`, `slug`,
 * `thumbnail_path` chỉ đổi ở đây bằng forceFill (S17). Sửa `title`/`short_description` luôn qua Eloquent để
 * `search_text` (Course::saving) không lệch.
 */
class CourseService
{
    /** Cột văn bản/số cho phép đi qua fill() từ dữ liệu đã validate. */
    private const FILLABLE = ['title', 'short_description', 'description', 'grade_level', 'price'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ImageUploadService $images,
        private readonly CourseTeacherService $teachers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, grade_level, short_description, description, price, subject_ids
     * @param  list<int>  $teacherIds  giáo viên phụ trách (giáo viên tự tạo thì chỉ gồm chính họ)
     */
    public function create(array $data, ?UploadedFile $thumbnail, array $teacherIds, User $actor): Course
    {
        $path = $thumbnail !== null ? $this->images->storeWebp($thumbnail) : null;

        try {
            $course = $this->withSlugRetry(fn (): Course => DB::transaction(function () use ($data, $path, $teacherIds, $actor): Course {
                $course = new Course($this->fillable($data));
                $course->forceFill([
                    'slug' => $this->uniqueSlug((string) $data['title']),
                    'status' => CourseStatus::Draft,
                    'created_by' => $actor->getKey(),
                    'thumbnail_path' => $path,
                ])->save();

                $course->subjects()->sync($data['subject_ids']);
                $this->teachers->sync($course, $teacherIds, $actor);

                $this->audit->log('course.create', $course, [
                    'title' => $course->title,
                    'price' => $course->price,
                    'grade_level' => $course->grade_level,
                    'teacher_ids' => $teacherIds,
                ]);

                return $course;
            }));
        } catch (Throwable $e) {
            $this->images->delete($path);

            throw $e;
        }

        return $course;
    }

    /**
     * Cập nhật các trường có trong `$data` (đã validate theo vai trò). `teacher_ids` chỉ có khi người gọi là staff.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Course $course, array $data, ?UploadedFile $thumbnail, User $actor): Course
    {
        $newPath = $thumbnail !== null ? $this->images->storeWebp($thumbnail) : null;
        $oldPath = null;

        try {
            DB::transaction(function () use ($course, $data, $newPath, $actor, &$oldPath): void {
                $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();

                $locked->fill($this->fillable($data));
                $changes = [];

                foreach ($locked->getDirty() as $field => $to) {
                    $changes[$field] = $field === 'description' ? 'changed' : ['from' => $locked->getOriginal($field), 'to' => $to];
                }

                if ($newPath !== null) {
                    $oldPath = $locked->thumbnail_path;
                    $locked->forceFill(['thumbnail_path' => $newPath]);
                    $changes['thumbnail'] = 'changed';
                }

                if ($locked->isDirty()) {
                    $locked->save(); // Eloquent: tính lại search_text
                }

                if (array_key_exists('subject_ids', $data)) {
                    $sync = $locked->subjects()->sync($data['subject_ids']);

                    if ($sync['attached'] !== [] || $sync['detached'] !== []) {
                        $changes['subject_ids'] = ['attached' => $sync['attached'], 'detached' => $sync['detached']];
                    }
                }

                if (array_key_exists('teacher_ids', $data)) {
                    $this->teachers->sync($locked, $data['teacher_ids'], $actor); // tự ghi audit course.teachers
                }

                if (array_key_exists('price', $changes)) {
                    $this->audit->log('course.price_change', $locked, ['price' => $changes['price']]);
                }

                if ($changes !== []) {
                    $this->audit->log('course.update', $locked, ['fields' => array_keys($changes)] + $changes);
                }

                $course->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (Throwable $e) {
            $this->images->delete($newPath);

            throw $e;
        }

        $this->images->delete($oldPath); // chỉ sau khi commit

        return $course->unsetRelations();
    }

    public function publish(Course $course): Course
    {
        return DB::transaction(function () use ($course): Course {
            $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CourseStatus::Published) {
                throw new DomainException('ALREADY_PROCESSED', 'Khóa học đã được xuất bản.', 409);
            }

            $hasLesson = $locked->lessons()->whereHas('chapter')->exists();

            if (! $hasLesson) {
                throw new DomainException('COURSE_NOT_PUBLISHABLE', 'Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.', 422);
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => CourseStatus::Published,
                'published_at' => $locked->published_at ?? now(), // chỉ đặt lần đầu
            ])->save();

            $this->audit->log('course.publish', $locked, ['status' => ['from' => $from->value, 'to' => 'published']]);
            $course->setRawAttributes($locked->getAttributes(), true);

            return $course->unsetRelations();
        });
    }

    public function unpublish(Course $course): Course
    {
        return DB::transaction(function () use ($course): Course {
            $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CourseStatus::Unpublished) {
                throw new DomainException('ALREADY_PROCESSED', 'Khóa học đã ngừng bán.', 409);
            }

            if ($locked->status !== CourseStatus::Published) {
                throw new DomainException('INVALID_COURSE_STATE', 'Chỉ khóa học đang xuất bản mới ngừng bán được.', 409);
            }

            $locked->forceFill(['status' => CourseStatus::Unpublished])->save();

            $this->audit->log('course.unpublish', $locked, ['status' => ['from' => 'published', 'to' => 'unpublished']]);
            $course->setRawAttributes($locked->getAttributes(), true);

            return $course->unsetRelations();
        });
    }

    /**
     * Xoá mềm khóa + chương + bài khi chưa có enrollment nào (BR4, BR5). Kiểm enrollment trong lúc khoá dòng khóa
     * học: luồng tạo enrollment (T14) phải khoá cùng dòng này trước khi ghi.
     */
    public function delete(Course $course): void
    {
        DB::transaction(function () use ($course): void {
            $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return; // đã bị xoá đồng thời: idempotent
            }

            if ($locked->enrollments()->exists()) {
                throw new DomainException(
                    'COURSE_HAS_ENROLLMENTS',
                    'Khóa học đã có học sinh đăng ký nên không thể xoá. Hãy chuyển sang "Ngừng bán": học sinh đã mua vẫn giữ quyền truy cập.',
                    409,
                );
            }

            // T18: khóa còn nằm trong đơn đang chờ thanh toán (pending, chưa hết hạn) không xoá được — xoá mềm sẽ làm
            // IPN về sau không cấp được quyền học. Checkout giữ khoá SHARE `courses` tới khi commit đơn nên phép đọc
            // dưới khoá `courses` này luôn thấy đơn đã commit (không có đơn "chen" giữa).
            $hasPendingOrder = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.course_id', $locked->getKey())
                ->where('orders.status', 'pending')
                ->where('orders.expires_at', '>', now())
                ->exists();

            if ($hasPendingOrder) {
                throw new DomainException(
                    'COURSE_HAS_PENDING_ORDERS',
                    'Khóa học đang nằm trong đơn hàng chờ thanh toán nên không thể xoá lúc này. Hãy chuyển sang "Ngừng bán" hoặc thử lại sau khi đơn hết hạn.',
                    409,
                );
            }

            $locked->lessons()->delete();
            $locked->chapters()->delete();
            $locked->delete();

            $this->audit->log('course.delete', $locked, [
                'title' => $locked->title,
                'status' => $locked->status->value,
            ]);
        });
    }

    public function setManualOrder(Course $course, ?int $order): Course
    {
        return DB::transaction(function () use ($course, $order): Course {
            $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->manual_order;

            if ($from !== $order) {
                $locked->forceFill(['manual_order' => $order])->save();
                $this->audit->log('course.manual_order', $locked, ['manual_order' => ['from' => $from, 'to' => $order]]);
            }

            $course->setRawAttributes($locked->getAttributes(), true);

            return $course->unsetRelations();
        });
    }

    /** Nạp lại khóa kèm chuyên đề, giáo viên và số chương/bài để trả về CourseResource. */
    public function loadDetail(Course $course): Course
    {
        return Course::query()->with(['subjects:id,name,slug', 'teachers:id,name'])
            ->withCount(['chapters', 'lessons'])->findOrFail($course->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        $fill = array_intersect_key($data, array_flip(self::FILLABLE));

        if (array_key_exists('description', $fill)) {
            $fill['description'] = $this->sanitizer->clean((string) $fill['description']);
        }

        return $fill;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 240, '');
        $base = $base !== '' ? $base : 'khoa-hoc';
        $slug = $base;

        for ($i = 2; Course::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * Slug sinh kiểu check-then-insert: va chạm `courses_slug_unique` do tạo đồng thời thì thử lại (sinh hậu tố mới).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withSlugRetry(callable $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $e) {
                $duplicateSlug = ($e->errorInfo[1] ?? null) === 1062 && str_contains((string) ($e->errorInfo[2] ?? ''), 'slug');

                if (! $duplicateSlug || $attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
