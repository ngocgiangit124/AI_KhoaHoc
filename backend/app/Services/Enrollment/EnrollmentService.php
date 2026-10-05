<?php

namespace App\Services\Enrollment;

use App\Enums\CourseStatus;
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
 * Nơi DUY NHẤT đổi `enrollments.status` và `courses.enrollments_count` (api-contract §3, S17).
 *
 * Chống trùng ở tầng DB: unique (user_id, course_id, live_flag) — hai request đồng thời cho cùng
 * (học sinh, khóa) thì một bên dính 1062, service dịch thành mã lỗi nghiệp vụ (không 500).
 * `live_flag` là generated column: không bao giờ ghi/clone cả dòng.
 */
class EnrollmentService
{
    /** InnoDB có thể chọn 1 bên làm nạn nhân deadlock (gap lock khi 2 bên cùng chèn): thử lại trong transaction ngoài cùng. */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * US-012 BR1/BR2/BR5: học sinh xin học khóa miễn phí → `pending_approval` (chờ duyệt).
     *
     * @throws DomainException NOT_FOUND 404 (khóa không published) · COURSE_NOT_FREE 422 ·
     *                         ALREADY_OWNED / ENROLLMENT_PENDING 409
     */
    public function requestFree(User $student, Course $course): Enrollment
    {
        // Kiểm nhanh trên model đã nạp (tránh mở transaction vô ích); kiểm lại CÓ KHOÁ trong transaction bên dưới.
        $this->assertRequestable($course);

        $this->assertNoLiveEnrollment($student->getKey(), $course->getKey());

        try {
            return DB::transaction(function () use ($student, $course): Enrollment {
                // Khoá `courses` TRƯỚC `enrollments` (cùng thứ tự với CourseService::delete/publish, approve): xoá/ngừng
                // bán xen giữa không còn lọt qua được. Kiểm lại trạng thái trên dòng đã khoá.
                $this->assertRequestable($this->lockCourse($course->getKey()));

                $enrollment = new Enrollment(['user_id' => $student->getKey(), 'course_id' => $course->getKey()]);
                $enrollment->forceFill([
                    'status' => EnrollmentStatus::PendingApproval,
                    'source' => EnrollmentSource::FreeApproval,
                    'requested_at' => now(),
                ])->save();

                $this->audit->log('enrollment.request', $enrollment, ['course_id' => $course->getKey()]);

                return $enrollment;
            }, self::DEADLOCK_ATTEMPTS);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // Đã có yêu cầu/quyền học "sống" (thường do double click hoặc 2 tab): báo đúng mã.
                $this->assertNoLiveEnrollment($student->getKey(), $course->getKey());
                throw new DomainException('ENROLLMENT_PENDING', 'Bạn đã gửi yêu cầu, vui lòng chờ duyệt.', 409);
            }

            throw $e;
        }
    }

    private function assertRequestable(?Course $course): void
    {
        if ($course === null || $course->status !== CourseStatus::Published || $course->trashed()) {
            throw new DomainException('NOT_FOUND', 'Không tìm thấy khóa học.', 404);
        }

        if ($course->price !== 0) {
            throw new DomainException('COURSE_NOT_FREE', 'Khóa học này có phí, vui lòng thêm vào giỏ hàng để mua.', 422);
        }
    }

    /** Khoá dòng khóa học (kể cả đã xoá mềm) để đọc trạng thái/giá mới nhất. */
    private function lockCourse(int $courseId): ?Course
    {
        return Course::withTrashed()->whereKey($courseId)->lockForUpdate()->first();
    }

    /**
     * Duyệt (AC2). Khóa dòng, chỉ xử lý nếu còn `pending_approval` (double click → 409 ALREADY_PROCESSED).
     */
    public function approve(Enrollment $enrollment, User $actor, ?string $note = null): Enrollment
    {
        $result = $this->decide($enrollment, function (Enrollment $locked) use ($actor, $note): void {
            // Khóa đã chuyển sang có phí khi yêu cầu còn chờ: không cấp quyền miễn phí (rollback, giữ pending để
            // người duyệt từ chối hoặc học sinh mua). Khóa `courses` đã được khoá ở `decide()`.
            $course = Course::withTrashed()->whereKey($locked->course_id)->first();
            if ($course === null || $course->trashed()) {
                throw new DomainException('COURSE_UNAVAILABLE', 'Khóa học không còn tồn tại nên không thể duyệt. Hãy từ chối yêu cầu.', 409);
            }

            if ($course->price !== 0) {
                throw new DomainException('COURSE_NOT_FREE', 'Khóa học đã chuyển sang có phí, không thể duyệt đăng ký miễn phí. Hãy từ chối yêu cầu hoặc để học sinh mua khóa.', 422);
            }

            $locked->forceFill([
                'status' => EnrollmentStatus::Active,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'activated_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->adjustCount($locked->course_id, +1);
            $this->audit->log('enrollment.approve', $locked, array_filter([
                'course_id' => $locked->course_id,
                'user_id' => $locked->user_id,
                'note' => $note,
            ], fn ($v) => $v !== null));
        });

        $this->notifyDecision($result, true);

        return $result;
    }

    /**
     * Từ chối (AC3); học sinh được gửi yêu cầu mới sau đó (AC5) vì `rejected` không còn "sống".
     */
    public function reject(Enrollment $enrollment, User $actor, ?string $reason = null): Enrollment
    {
        $result = $this->decide($enrollment, function (Enrollment $locked) use ($reason): void {
            $locked->forceFill([
                'status' => EnrollmentStatus::Rejected,
                // Không ghi approved_by/approved_at: người và thời điểm từ chối nằm ở audit `enrollment.reject`.
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->log('enrollment.reject', $locked, array_filter([
                'course_id' => $locked->course_id,
                'user_id' => $locked->user_id,
                'reason' => $reason,
            ], fn ($v) => $v !== null));
        });

        $this->notifyDecision($result, false);

        return $result;
    }

    /**
     * US-012 AC2/AC3: email báo kết quả (queue, tiếng Việt). `DB::afterCommit` — nếu caller bọc thêm transaction
     * thì chỉ gửi khi transaction ngoài cùng commit (rollback = không gửi). Học sinh không có email đã xác thực
     * (chỉ có SĐT) thì bỏ qua. Lỗi đẩy queue/gửi mail KHÔNG làm hỏng việc duyệt đã commit: chỉ ghi log (không PII).
     */
    private function notifyDecision(Enrollment $enrollment, bool $approved): void
    {
        $enrollmentId = $enrollment->getKey();

        DB::afterCommit(function () use ($enrollmentId, $approved): void {
            try {
                $enrollment = Enrollment::query()->with(['user', 'course' => fn ($q) => $q->withTrashed()])->find($enrollmentId);
                $student = $enrollment?->user;
                $course = $enrollment?->course;

                if ($student === null || $course === null || $student->email === null || $student->email_verified_at === null) {
                    return;
                }

                $url = rtrim((string) config('app.frontend_url'), '/').'/khoa-hoc/'.$course->slug;

                Mail::to($student->email)->queue(new EnrollmentDecisionMail(
                    approved: $approved,
                    studentName: (string) $student->name,
                    courseTitle: (string) $course->title,
                    courseUrl: $url,
                    reason: $approved ? null : $enrollment->rejection_reason,
                ));
            } catch (Throwable $e) {
                Log::warning('Không gửi được email kết quả duyệt đăng ký.', ['enrollment_id' => $enrollmentId, 'exception' => $e::class]);
            }
        });
    }

    /**
     * Thu hồi quyền học (hoàn tiền/vi phạm). `$reason` là mã ngắn (≤ 50 ký tự, vd `refund`, `admin`).
     * Chỉ thu hồi được enrollment `active`; đã `revoked` → 409 ALREADY_PROCESSED.
     * Caller (T24 hoàn tiền) kiểm quyền; `$actor = null` khi do hệ thống.
     */
    public function revoke(Enrollment $enrollment, string $reason, ?User $actor = null): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $reason, $actor): Enrollment {
            $this->lockCourse((int) $enrollment->course_id);
            $locked = $this->lock($enrollment);

            if ($locked->status !== EnrollmentStatus::Active) {
                throw $this->alreadyProcessed();
            }

            $locked->forceFill([
                'status' => EnrollmentStatus::Revoked,
                'revoked_at' => now(),
                'revoked_reason' => mb_substr($reason, 0, 50),
            ])->save();

            $this->adjustCount($locked->course_id, -1);
            $this->audit->log('enrollment.revoke', $locked, [
                'course_id' => $locked->course_id,
                'user_id' => $locked->user_id,
                'reason' => mb_substr($reason, 0, 50),
                'actor' => $actor?->getKey(),
            ]);

            $enrollment->setRawAttributes($locked->getAttributes(), true);

            return $enrollment;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Cấp quyền học sau khi đơn được thanh toán — `OrderFulfillmentService::markPaid` (T19) gọi trong
     * transaction của nó. Idempotent theo (user, course): gọi lại (IPN trùng) không tạo dòng mới.
     *
     * - Chưa có dòng "sống": tạo `active`, source `purchase`, gắn `order_id`.
     * - Đang `pending_approval` (xin học miễn phí rồi khóa đổi sang có phí): nâng thành `active`/`purchase`.
     * - Đã `active`: giữ nguyên, trả dòng hiện có (`wasRecentlyCreated=false`); caller tự quyết định
     *   (vd đã sở hữu từ đơn khác → cần hoàn tiền/ghi log).
     *
     * Dịch 1062 (2 IPN đồng thời) bằng cách đọc lại dòng thắng cuộc.
     *
     * @throws DomainException COURSE_UNAVAILABLE 409: khóa đã bị xoá mềm (tiền đã thu nhưng không cấp được quyền) —
     *                         T19 bắt mã này để chuyển đơn sang `needs_review`/hoàn tiền, KHÔNG nuốt lỗi. Khóa chỉ
     *                         "ngừng bán" (unpublished) vẫn được cấp quyền: người đã trả tiền giữ quyền truy cập.
     *
     * LƯU Ý cho T19: retry deadlock/1062 (`DEADLOCK_ATTEMPTS`) CHỈ có tác dụng khi hàm này là transaction ngoài
     * cùng. Gọi bên trong transaction của caller thì MySQL đã huỷ cả transaction khi deadlock (savepoint không
     * cứu được) nên caller phải tự bọc retry deadlock ở mức ngoài cùng. 1062 vẫn được dịch đúng ở cả hai trường
     * hợp (không làm hỏng transaction ngoài). Đừng dựa vào `wasRecentlyCreated`: hãy so `order_id` của dòng trả
     * về với đơn đang xử lý.
     */
    public function grantPurchase(User $student, Course $course, ?int $orderId = null): Enrollment
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): Enrollment => $this->grantPurchaseOnce($student, $course, $orderId), self::DEADLOCK_ATTEMPTS);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $attempt >= self::DEADLOCK_ATTEMPTS) {
                    throw $e;
                }
                // Có dòng "sống" vừa được tạo bởi tiến trình khác: vòng sau sẽ thấy và dùng lại.
            }
        }
    }

    private function grantPurchaseOnce(User $student, Course $course, ?int $orderId): Enrollment
    {
        $lockedCourse = $this->lockCourse($course->getKey());

        if ($lockedCourse === null || $lockedCourse->trashed()) {
            throw new DomainException('COURSE_UNAVAILABLE', 'Khóa học không còn tồn tại nên không thể cấp quyền học.', 409, ['course_id' => $course->getKey()]);
        }

        $live = Enrollment::query()
            ->where('user_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->whereIn('status', [EnrollmentStatus::PendingApproval->value, EnrollmentStatus::Active->value])
            ->lockForUpdate()
            ->first();

        if ($live?->status === EnrollmentStatus::Active) {
            return $live;
        }

        if ($live !== null) {
            $live->forceFill([
                'status' => EnrollmentStatus::Active,
                'source' => EnrollmentSource::Purchase,
                'order_id' => $orderId,
                'activated_at' => now(),
            ])->save();
            $enrollment = $live;
        } else {
            $enrollment = new Enrollment(['user_id' => $student->getKey(), 'course_id' => $course->getKey()]);
            $enrollment->forceFill([
                'status' => EnrollmentStatus::Active,
                'source' => EnrollmentSource::Purchase,
                'order_id' => $orderId,
                'activated_at' => now(),
            ])->save();
        }

        $this->adjustCount($course->getKey(), +1);
        $this->audit->log('enrollment.grant', $enrollment, [
            'course_id' => $course->getKey(),
            'user_id' => $student->getKey(),
            'order_id' => $orderId,
        ]);

        return $enrollment;
    }

    /** @param  callable(Enrollment): void  $apply */
    private function decide(Enrollment $enrollment, callable $apply): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $apply): Enrollment {
            // Thứ tự khoá chuẩn: courses → enrollments (`course_id` không đổi nên đọc được trước khi khoá).
            $this->lockCourse((int) $enrollment->course_id);
            $locked = $this->lock($enrollment);

            if ($locked->status !== EnrollmentStatus::PendingApproval) {
                throw $this->alreadyProcessed();
            }

            $apply($locked);
            $enrollment->setRawAttributes($locked->getAttributes(), true);

            return $enrollment;
        }, self::DEADLOCK_ATTEMPTS);
    }

    private function lock(Enrollment $enrollment): Enrollment
    {
        return Enrollment::query()->whereKey($enrollment->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertNoLiveEnrollment(int $userId, int $courseId): void
    {
        $status = Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereIn('status', [EnrollmentStatus::PendingApproval->value, EnrollmentStatus::Active->value])
            ->toBase() // chuỗi thô: Eloquent `value()` áp cast enum
            ->value('status');

        if ($status === EnrollmentStatus::Active->value) {
            throw new DomainException('ALREADY_OWNED', 'Bạn đã có quyền học khóa này.', 409);
        }

        if ($status === EnrollmentStatus::PendingApproval->value) {
            throw new DomainException('ENROLLMENT_PENDING', 'Bạn đã gửi yêu cầu, vui lòng chờ duyệt.', 409);
        }
    }

    /** Cập nhật denormalize trong cùng transaction (data-model §3.2); không âm. */
    private function adjustCount(int $courseId, int $delta): void
    {
        $query = Course::withTrashed()->whereKey($courseId);

        if ($delta > 0) {
            $query->increment('enrollments_count', $delta);
        } else {
            $query->where('enrollments_count', '>', 0)->decrement('enrollments_count', -$delta);
        }
    }

    private function alreadyProcessed(): DomainException
    {
        return new DomainException('ALREADY_PROCESSED', 'Yêu cầu này đã được xử lý.', 409);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
