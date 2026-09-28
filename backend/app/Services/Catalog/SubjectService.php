<?php

namespace App\Services\Catalog;

use App\Enums\SubjectStatus;
use App\Exceptions\DomainException;
use App\Models\Subject;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * US-011 — CRUD chuyên đề. Đổi tên (`update`) không cần cập nhật thủ công
 * khóa học liên quan (AC5 — chỉ là FK, không denormalize tên chuyên đề ở
 * `courses`/`course_subject`).
 */
class SubjectService
{
    /**
     * Mã lỗi MySQL "Cannot delete or update a parent row: a foreign key
     * constraint fails" (chặn xoá cứng khi đang gán khóa học — US-011 AC3,
     * data-model §3.2 `course_subject.subject_id` FK `restrict`).
     */
    private const MYSQL_FK_CONSTRAINT_VIOLATION = 1451;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, status?: SubjectStatus}  $data
     */
    public function create(array $data): Subject
    {
        $subject = Subject::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'status' => $data['status'] ?? SubjectStatus::Active,
        ]);

        $this->auditLogger->log('subject.create', $subject, [
            'name' => $subject->name,
            'status' => $subject->status->value,
        ]);

        return $subject;
    }

    /**
     * @param  array{name: string}  $data
     */
    public function update(Subject $subject, array $data): Subject
    {
        $before = $subject->name;

        if ($data['name'] !== $subject->name) {
            $subject->slug = $this->uniqueSlug($data['name'], $subject);
        }

        $subject->name = $data['name'];
        $subject->save();

        $this->auditLogger->log('subject.update', $subject, [
            'name' => ['before' => $before, 'after' => $subject->name],
        ]);

        return $subject;
    }

    public function updateStatus(Subject $subject, SubjectStatus $status): Subject
    {
        $before = $subject->status->value;

        $subject->status = $status;
        $subject->save();

        $this->auditLogger->log('subject.status.update', $subject, [
            'status' => ['before' => $before, 'after' => $status->value],
        ]);

        return $subject;
    }

    /**
     * US-011 AC3/AC4 — chặn xoá cứng khi đang gán ≥1 khóa học. Dựa hẳn vào
     * ràng buộc FK `restrict` của `course_subject.subject_id` (T07) thay vì
     * tự đếm bằng truy vấn `course_subject` ở đây — bảng đó thuộc phạm vi
     * T07 (đang phát triển song song), nên không import/khai báo bất kỳ tham
     * chiếu nào tới nó trong T06 (tránh phụ thuộc chéo giữa 2 worktree đang
     * chạy song song). Khi gộp nhánh, hành vi 409 tự động đúng nhờ FK.
     */
    public function delete(Subject $subject): void
    {
        try {
            $subject->delete();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_FK_CONSTRAINT_VIOLATION) {
                throw new DomainException(
                    code: 'SUBJECT_IN_USE',
                    message: 'Chuyên đề đang được gán cho ít nhất 1 khóa học nên không thể xoá. Hãy chuyển sang trạng thái Ẩn.',
                    status: 409,
                );
            }

            throw $e;
        }

        $this->auditLogger->log('subject.delete', $subject, [
            'name' => $subject->name,
        ]);
    }

    private function uniqueSlug(string $name, ?Subject $ignore = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (
            Subject::query()
                ->where('slug', $slug)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
