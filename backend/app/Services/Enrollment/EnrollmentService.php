<?php

namespace App\Services\Enrollment;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Mail\EnrollmentDecisionMail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * US-012 — nơi DUY NHẤT đổi `enrollments.status` (api-contract §3 "Trách
 * nhiệm dễ đoán sai"). `requestFree`/`approve`/`reject` phục vụ luồng đăng ký
 * miễn phí (T14, route ở `routes/api.php` + `routes/admin.php`). `revoke`
 * được viết sẵn cho `RefundService` (T20 — hoàn tiền khóa TRẢ PHÍ) gọi sau
 * này; api-contract CHƯA liệt kê endpoint HTTP nào cho `revoke` ở giai đoạn
 * này nên KHÔNG có route nào gọi tới ngoài test đơn vị/Service khác.
 */
class EnrollmentService
{
    /** Mã lỗi MySQL "Duplicate entry" — trùng UNIQUE `live_flag` (BR5). */
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * US-012 BR2/BR5 — tạo yêu cầu `pending_approval`. `$course` phải
     * `published` + miễn phí (`EnrollmentPolicy::requestFree` đã kiểm TRƯỚC
     * khi gọi hàm này — Controller chịu trách nhiệm gọi `authorize()`).
     *
     * Chống race 2 request gửi cùng lúc (data-model §7 "Gửi trùng yêu cầu học
     * miễn phí"): dựa vào UNIQUE `(user_id, course_id, live_flag)` ở tầng DB
     * thay vì tự `lockForUpdate()` trước — không có hàng nào để khoá khi đây
     * là yêu cầu ĐẦU TIÊN (dòng `rejected`/`revoked` cũ có `live_flag = NULL`
     * nên không cản trở gửi lại — US-012 AC5). Kiểm tồn tại trước (đường đi
     * thường gặp, thông báo rõ ràng), rồi vẫn bọc `try/catch` quanh `save()`
     * để bắt đúng lỗi 1062 khi 2 request thật sự đụng nhau.
     *
     * @throws DomainException `ENROLLMENT_PENDING`/`ALREADY_OWNED` (409)
     */
    public function requestFree(Course $course, User $student): Enrollment
    {
        $existing = $this->findLiveEnrollment($student, $course);

        if ($existing !== null) {
            throw $this->alreadyLiveException($existing);
        }

        $enrollment = new Enrollment([
            'user_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'source' => EnrollmentSource::FreeApproval,
            'requested_at' => now(),
        ]);
        // `status` KHÔNG nằm trong $fillable (S17) — gán trực tiếp, giống
        // `SubjectService::create()`.
        $enrollment->status = EnrollmentStatus::PendingApproval;

        try {
            $enrollment->save();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== self::MYSQL_DUPLICATE_ENTRY) {
                throw $e;
            }

            // Race thật: request kia vừa INSERT xong giữa lúc `findLiveEnrollment()`
            // ở trên chạy và `save()` này chạy. Đọc lại để trả đúng mã lỗi.
            $raced = $this->findLiveEnrollment($student, $course);

            throw $raced !== null ? $this->alreadyLiveException($raced) : $e;
        }

        return $enrollment;
    }

    /**
     * US-012 AC2 — duyệt. UPDATE có điều kiện `WHERE status='pending_approval'`
     * (data-model §7 "Gửi trùng yêu cầu học miễn phí / double click duyệt")
     * tự chặn double-click/duyệt 2 lần mà không cần khoá tường minh trước;
     * tăng `courses.enrollments_count` trong CÙNG transaction với UPDATE
     * enrollment (2 bảng — `DB::transaction($fn, 3)` theo quy ước data-model
     * §7). Gửi mail SAU KHI transaction đã commit (bài học T04: không gọi
     * service/side-effect bên ngoài trong lúc còn giữ transaction).
     *
     * @throws DomainException `ALREADY_PROCESSED` (409)
     */
    public function approve(Enrollment $enrollment, User $approver): Enrollment
    {
        DB::transaction(function () use ($enrollment, $approver): void {
            $now = now();

            $affected = Enrollment::query()
                ->whereKey($enrollment->getKey())
                ->where('status', EnrollmentStatus::PendingApproval->value)
                ->update([
                    'status' => EnrollmentStatus::Active->value,
                    'approved_by' => $approver->getKey(),
                    'approved_at' => $now,
                    'activated_at' => $now,
                ]);

            if ($affected === 0) {
                throw self::alreadyProcessedException();
            }

            Course::query()->whereKey($enrollment->course_id)->increment('enrollments_count');
        }, 3);

        $enrollment->refresh();

        $this->afterCommit('approve', function () use ($enrollment): void {
            $this->auditLogger->log('enrollment.approve', $enrollment, [
                'course_id' => $enrollment->course_id,
            ]);
        });
        $this->afterCommit('approve_mail', fn () => $this->sendDecisionMailIfEnabled($enrollment, approved: true, reason: null));

        return $enrollment;
    }

    /**
     * US-012 AC3 — từ chối. Không đổi `enrollments_count` (khóa chưa từng
     * `active`) nên chỉ cần 1 UPDATE có điều kiện, không cần transaction đa
     * bảng. `approved_by`/`approved_at` giữ NULL (2 cột này dành riêng cho
     * DUYỆT theo data-model §3.3) — ai/khi nào từ chối đã có trong
     * `audit_logs`.
     *
     * @throws DomainException `ALREADY_PROCESSED` (409)
     */
    public function reject(Enrollment $enrollment, User $actor, ?string $reason): Enrollment
    {
        $affected = Enrollment::query()
            ->whereKey($enrollment->getKey())
            ->where('status', EnrollmentStatus::PendingApproval->value)
            ->update([
                'status' => EnrollmentStatus::Rejected->value,
                'rejection_reason' => $reason,
            ]);

        if ($affected === 0) {
            throw self::alreadyProcessedException();
        }

        $enrollment->refresh();

        $this->afterCommit('reject', function () use ($enrollment, $reason): void {
            $this->auditLogger->log('enrollment.reject', $enrollment, [
                'course_id' => $enrollment->course_id,
                'reason' => $reason,
            ]);
        });
        $this->afterCommit('reject_mail', fn () => $this->sendDecisionMailIfEnabled($enrollment, approved: false, reason: $reason));

        return $enrollment;
    }

    /**
     * Thu hồi quyền học (US-010 hoàn tiền — `RefundService`, T20, sẽ gọi qua
     * đây để giữ đúng nguyên tắc "`EnrollmentService` là nơi DUY NHẤT đổi
     * `enrollments.status`"). CHƯA có route HTTP nào của T14 gọi hàm này.
     *
     * @param  string  $reason  Vd `refund` (data-model §3.3 `revoked_reason`, ≤ 50 ký tự).
     *
     * @throws DomainException `ALREADY_PROCESSED` (409)
     */
    public function revoke(Enrollment $enrollment, string $reason): Enrollment
    {
        DB::transaction(function () use ($enrollment, $reason): void {
            $affected = Enrollment::query()
                ->whereKey($enrollment->getKey())
                ->where('status', EnrollmentStatus::Active->value)
                ->update([
                    'status' => EnrollmentStatus::Revoked->value,
                    'revoked_at' => now(),
                    'revoked_reason' => $reason,
                ]);

            if ($affected === 0) {
                throw self::alreadyProcessedException();
            }

            Course::query()->whereKey($enrollment->course_id)->decrement('enrollments_count');
        }, 3);

        $enrollment->refresh();

        $this->afterCommit('revoke', function () use ($enrollment, $reason): void {
            $this->auditLogger->log('enrollment.revoke', $enrollment, [
                'course_id' => $enrollment->course_id,
                'reason' => $reason,
            ]);
        });

        return $enrollment;
    }

    protected function findLiveEnrollment(User $student, Course $course): ?Enrollment
    {
        return Enrollment::query()
            ->where('user_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->whereNotNull('live_flag')
            ->first();
    }

    /**
     * Việc phụ SAU khi trạng thái đã đổi (audit, mail): lỗi ở đây (queue/Redis
     * sập) không được làm API trả 500 — trạng thái đã commit, người duyệt bấm
     * lại chỉ nhận 409. Chỉ report + log cảnh báo (không kèm PII/lý do).
     *
     * @param  callable(): void  $task
     */
    private function afterCommit(string $name, callable $task): void
    {
        try {
            $task();
        } catch (Throwable $e) {
            report($e);
            Log::warning('enrollment.after_commit_failed', ['task' => $name]);
        }
    }

    private function alreadyLiveException(Enrollment $existing): DomainException
    {
        if ($existing->status === EnrollmentStatus::Active) {
            return new DomainException(
                code: 'ALREADY_OWNED',
                message: 'Bạn đã có quyền học khóa học này.',
                status: 409,
            );
        }

        // `pending_approval` — BR5/AC4.
        return new DomainException(
            code: 'ENROLLMENT_PENDING',
            message: 'Bạn đã gửi yêu cầu, vui lòng chờ duyệt.',
            status: 409,
        );
    }

    private static function alreadyProcessedException(): DomainException
    {
        return new DomainException(
            code: 'ALREADY_PROCESSED',
            message: 'Yêu cầu này đã được xử lý trước đó.',
            status: 409,
        );
    }

    /**
     * README §3.3 — "Mail thông báo theo flag `enrollment_decision_mail`
     * (mặc định tắt — chờ PO)".
     */
    private function sendDecisionMailIfEnabled(Enrollment $enrollment, bool $approved, ?string $reason): void
    {
        if (! (bool) config('features.enrollment_decision_mail')) {
            return;
        }

        $enrollment->loadMissing(['user', 'course']);

        Mail::to($enrollment->user->email)->queue(new EnrollmentDecisionMail(
            recipientName: $enrollment->user->name,
            courseTitle: $enrollment->course->title,
            approved: $approved,
            reason: $reason,
        ));
    }
}
