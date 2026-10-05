<?php

namespace App\Services\Subjects;

use App\Enums\SubjectStatus;
use App\Exceptions\DomainException;
use App\Models\Subject;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Nghiệp vụ chuyên đề (US-011). `status`/`slug` chỉ đổi ở đây (S17).
 */
class SubjectService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name): Subject
    {
        // Slug sinh kiểu check-then-insert: va chạm slug (tên khác nhau nhưng cùng slug) thì sinh lại hậu tố.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($name): Subject {
                    $subject = new Subject(['name' => $name]);
                    $subject->forceFill(['slug' => $this->uniqueSlug($name), 'status' => SubjectStatus::Active])->save();

                    $this->audit->log('subject.create', $subject, ['name' => $subject->name]);

                    return $subject;
                });
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e) && $this->violatesSlug($e) && $attempt < 5) {
                    continue;
                }

                throw $this->translateDuplicate($e);
            }
        }
    }

    /**
     * Đổi tên không đổi slug (giữ URL/bộ lọc ổn định); khóa học đã gán thấy tên mới ngay vì chỉ lưu `subject_id`.
     */
    public function rename(Subject $subject, string $name): Subject
    {
        try {
            DB::transaction(function () use ($subject, $name): void {
                $locked = Subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                $old = $locked->name;

                $locked->update(['name' => $name]);

                if ($old !== $locked->name) {
                    $this->audit->log('subject.update', $locked, ['name' => ['from' => $old, 'to' => $locked->name]]);
                }

                $subject->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (QueryException $e) {
            throw $this->translateDuplicate($e);
        }

        return $subject;
    }

    public function setStatus(Subject $subject, SubjectStatus $status): Subject
    {
        DB::transaction(function () use ($subject, $status): void {
            $locked = Subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $old = $locked->status;

            $locked->forceFill(['status' => $status])->save();

            if ($old !== $status) {
                $this->audit->log('subject.status', $locked, ['status' => ['from' => $old->value, 'to' => $status->value]]);
            }

            $subject->setRawAttributes($locked->getAttributes(), true);
        });

        return $subject;
    }

    /**
     * Xoá cứng chỉ khi chưa gán khóa học nào (AC3/AC4). Khóa dòng chuyên đề rồi kiểm lại trong transaction; FK
     * `course_subject.subject_id` (restrict) là chốt chặn cuối nếu có gán đồng thời.
     */
    public function delete(Subject $subject): void
    {
        try {
            DB::transaction(function () use ($subject): void {
                $locked = Subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();

                // Đã bị xoá đồng thời: coi như xong (idempotent, 204).
                if ($locked === null) {
                    return;
                }

                // Đếm cả khóa đã xoá mềm: liên kết còn trong course_subject vẫn chặn xoá cứng.
                if ($this->hasCourses($locked->getKey())) {
                    throw $this->inUse();
                }

                $locked->delete();
                $this->audit->log('subject.delete', $locked, ['name' => $locked->name]);
            });
        } catch (QueryException $e) {
            if ($this->isForeignKeyViolation($e)) {
                throw $this->inUse();
            }

            throw $e;
        }
    }

    protected function hasCourses(int $subjectId): bool
    {
        return DB::table('course_subject')->where('subject_id', $subjectId)->exists();
    }

    private function inUse(): DomainException
    {
        return new DomainException(
            'SUBJECT_IN_USE',
            'Chuyên đề đang được gán cho khóa học nên không thể xoá. Hãy chuyển sang trạng thái Ẩn thay vì xoá.',
            409,
        );
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'chuyen-de';
        $base = Str::limit($base, 110, '');
        $slug = $base;
        $i = 2;

        while (Subject::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Tạo đồng thời cùng tên: unique index thắng → báo lỗi y như validate (không 500). */
    private function translateDuplicate(QueryException $e): \Throwable
    {
        if ($this->isUniqueViolation($e) && ! $this->violatesSlug($e)) {
            return ValidationException::withMessages(['name' => 'Chuyên đề đã tồn tại.']);
        }

        return $e;
    }

    private function violatesSlug(QueryException $e): bool
    {
        return str_contains((string) ($e->errorInfo[2] ?? ''), 'subjects_slug_unique');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    private function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452], true);
    }
}
