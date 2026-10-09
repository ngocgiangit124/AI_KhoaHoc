<?php

namespace App\Services\Privacy;

use App\Exceptions\DataExportLimitException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\CurrentPasswordGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Xuất dữ liệu cá nhân của CHÍNH học sinh (US-018, api-contract §2.8.4, ADR-006 §5). Đồng bộ, không lưu file.
 *
 * Hạn mức: tối đa `privacy.data_export_daily_limit` lần THÀNH CÔNG mỗi ngày lịch Asia/Ho_Chi_Minh. Nguồn đếm là
 * `audit_logs` (`actor_id` + `action`), kiểm 2 lần: sơ bộ (rẻ, trước khi dựng file) rồi lại dưới khoá dòng `users` cùng
 * transaction với việc ghi audit, nên 2 request song song không vượt trần. Lần lỗi (sai mật khẩu, lỗi dựng file) không ghi
 * audit nên không bị tính.
 */
class DataExportService
{
    public const FORMAT_VERSION = 1;

    public const AUDIT_ACTION = 'privacy.data_export';

    /** Ngưỡng log `warning` để xem lại phương án chạy nền (api-contract §2.8.4). */
    private const SLOW_MS = 5000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentPasswordGuard $currentPassword,
    ) {}

    /**
     * @return array{limit_per_day: int, used_today: int, remaining: int, resets_at: string, requires_password: bool}
     */
    public function status(User $user): array
    {
        $limit = $this->limit();
        $used = $this->usedToday($user);

        return [
            'limit_per_day' => $limit,
            'used_today' => $used,
            'remaining' => max(0, $limit - $used),
            'resets_at' => $this->resetsAt()->toIso8601String(),
            'requires_password' => true,
        ];
    }

    /**
     * Kiểm mật khẩu → kiểm sơ bộ hạn mức → dựng → (khoá users, đếm lại, ghi audit) → trả chuỗi JSON.
     *
     * @return array{json: string, filename: string, bytes: int}
     *
     * @throws DataExportLimitException
     */
    public function export(User $user, #[\SensitiveParameter] string $password): array
    {
        $this->currentPassword->assert($user, $password, 'privacy.data_export_failed');

        $this->assertUnderLimit($this->usedToday($user));

        $startedAt = hrtime(true);

        try {
            $json = json_encode(
                $this->build($user),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT,
            );
        } catch (Throwable $e) {
            // Lần lỗi không bị tính: chưa ghi audit. Không log nội dung (PII).
            Log::channel($this->logChannel())->error('privacy.data_export_failed', ['user_id' => $user->getKey(), 'exception' => $e::class]);

            throw $e;
        }

        $bytes = strlen($json);

        DB::transaction(function () use ($user, $bytes): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->assertUnderLimit($this->usedToday($user));

            $this->audit->log(self::AUDIT_ACTION, $user, ['format_version' => self::FORMAT_VERSION, 'bytes' => $bytes]);
        });

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $context = ['user_id' => $user->getKey(), 'duration_ms' => $durationMs, 'bytes' => $bytes];
        Log::channel($this->logChannel())->{$durationMs > self::SLOW_MS ? 'warning' : 'info'}('privacy.data_export', $context);

        return [
            'json' => $json,
            'filename' => 'vitaminvui-du-lieu-ca-nhan-'.$this->nowVn()->format('Ymd').'.json',
            'bytes' => $bytes,
        ];
    }

    /**
     * Schema §2.8.4. MỌI truy vấn lọc theo `$user->id`; không nhận tham số id nào khác.
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $uid = (int) $user->getKey();
        $fresh = User::query()->findOrFail($uid);

        return [
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => $this->iso(now()),
            'policy_version' => (string) config('privacy.policy_version'),
            'account' => [
                'id' => $uid,
                'name' => $fresh->name,
                'email' => $fresh->email,
                'phone' => $fresh->phone,
                'date_of_birth' => $fresh->date_of_birth?->format('Y-m-d'),
                'grade_level' => $fresh->grade_level,
                'role' => $fresh->role->value,
                'status' => $fresh->status->value,
                'email_verified_at' => $this->iso($fresh->email_verified_at),
                'phone_verified_at' => $this->iso($fresh->phone_verified_at),
                'created_at' => $this->iso($fresh->created_at),
                'last_login_at' => $this->iso($fresh->last_login_at),
                'referral_code_used' => $fresh->referral_code_used,
            ],
            'parent_contact' => [
                'email' => $fresh->parent_email,
                'phone' => $fresh->parent_phone,
                'notices_opted_out_at' => $this->iso($fresh->parent_notice_opt_out_at),
            ],
            'consents' => $this->consents($uid),
            'enrollments' => $this->enrollments($uid),
            'orders' => $this->orders($uid),
            'learning_progress' => $this->learningProgress($uid),
            'quiz_attempts' => $this->quizAttempts($uid),
            'cart' => $this->cart($uid),
            'parent_notices' => $this->parentNotices($fresh),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function consents(int $uid): array
    {
        return DB::table('consents')
            ->where('user_id', $uid)
            ->orderBy('granted_at')->orderBy('id')
            ->get()
            ->map(fn (object $r) => [
                'type' => $r->type,
                'policy_version' => $r->policy_version,
                'granted_by' => $r->granted_by,
                'channel' => $r->channel,
                'granted_at' => $this->iso($r->granted_at),
                'revoked_at' => $this->iso($r->revoked_at),
                'ip' => $r->ip,
                'user_agent' => $r->user_agent,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function enrollments(int $uid): array
    {
        return DB::table('enrollments')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('enrollments.user_id', $uid)
            ->orderBy('enrollments.id')
            ->get(['enrollments.*', 'courses.title as course_title'])
            ->map(fn (object $r) => [
                'course' => ['id' => (int) $r->course_id, 'title' => $r->course_title],
                'status' => $r->status,
                'source' => $r->source,
                'requested_at' => $this->iso($r->requested_at),
                'activated_at' => $this->iso($r->activated_at),
                'revoked_at' => $this->iso($r->revoked_at),
                'rejection_reason' => $r->rejection_reason,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function orders(int $uid): array
    {
        $orders = DB::table('orders')->where('user_id', $uid)->orderBy('id')->get();

        if ($orders->isEmpty()) {
            return [];
        }

        // Một truy vấn cho mọi dòng đơn (không N+1). Tiêu đề lấy từ `courses` (kể cả đã xoá mềm), dự phòng bản chụp lúc đặt đơn.
        $items = DB::table('order_items')
            ->join('courses', 'courses.id', '=', 'order_items.course_id')
            ->whereIn('order_items.order_id', $orders->pluck('id')->all())
            ->orderBy('order_items.id')
            ->get(['order_items.*', DB::raw('COALESCE(courses.title, order_items.course_title) AS shown_title')])
            ->groupBy('order_id');

        $result = [];

        foreach ($orders as $o) {
            $lines = [];

            foreach ($items->get($o->id, collect()) as $i) {
                $lines[] = [
                    'course' => ['id' => (int) $i->course_id, 'title' => $i->shown_title],
                    'price' => (int) $i->unit_price,
                    'discount_amount' => (int) $i->discount_amount,
                    'final_amount' => (int) $i->final_amount,
                ];
            }

            $result[] = [
                'code' => $o->code,
                'status' => $o->status,
                'subtotal_amount' => (int) $o->subtotal_amount,
                'discount_amount' => (int) $o->discount_amount,
                'total_amount' => (int) $o->total_amount,
                'coupon_code' => $o->coupon_code,
                'payment_method' => $o->payment_method,
                // US-022: ghi chú do chính học sinh nhập (không gồm ghi chú nội bộ `order_notes`).
                'customer_note' => $o->customer_note,
                'created_at' => $this->iso($o->created_at),
                'paid_at' => $this->iso($o->paid_at),
                'cancelled_at' => $this->iso($o->cancelled_at),
                'refunded_at' => $this->iso($o->refunded_at),
                'items' => $lines,
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function learningProgress(int $uid): array
    {
        $rows = [];

        DB::table('lesson_progress')
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->leftJoin('courses', 'courses.id', '=', 'lesson_progress.course_id')
            ->where('lesson_progress.user_id', $uid)
            ->select(['lesson_progress.*', 'lessons.title as lesson_title', 'courses.title as course_title'])
            ->lazyById(1000, 'lesson_progress.id', 'id')
            ->each(function (object $r) use (&$rows): void {
                $rows[] = [
                    'course' => ['id' => (int) $r->course_id, 'title' => $r->course_title],
                    'lesson' => ['id' => (int) $r->lesson_id, 'title' => $r->lesson_title],
                    'status' => $r->status,
                    'watched_seconds' => (int) $r->watched_seconds,
                    'last_position_seconds' => (int) $r->last_position_seconds,
                    'completed_at' => $this->iso($r->completed_at),
                    'last_accessed_at' => $this->iso($r->last_accessed_at),
                ];
            });

        return $rows;
    }

    /**
     * Chỉ điểm tổng hợp: KHÔNG có `answers`/`result`/`question_ids` (đáp án từng câu).
     *
     * @return list<array<string, mixed>>
     */
    private function quizAttempts(int $uid): array
    {
        $rows = [];

        DB::table('quiz_attempts')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('courses', 'courses.id', '=', 'quiz_attempts.course_id')
            ->where('quiz_attempts.user_id', $uid)
            ->select([
                'quiz_attempts.id', 'quiz_attempts.quiz_id', 'quiz_attempts.course_id', 'quiz_attempts.started_at',
                'quiz_attempts.submitted_at', 'quiz_attempts.auto_submitted', 'quiz_attempts.total_questions',
                'quiz_attempts.correct_count', 'quiz_attempts.score',
                'quizzes.title as quiz_title', 'courses.title as course_title',
            ])
            ->lazyById(500, 'quiz_attempts.id', 'id')
            ->each(function (object $r) use (&$rows): void {
                $rows[] = [
                    'quiz' => ['id' => (int) $r->quiz_id, 'title' => $r->quiz_title],
                    'course' => ['id' => (int) $r->course_id, 'title' => $r->course_title],
                    'status' => $r->submitted_at !== null ? 'submitted' : 'in_progress',
                    'started_at' => $this->iso($r->started_at),
                    'submitted_at' => $this->iso($r->submitted_at),
                    'auto_submitted' => (bool) $r->auto_submitted,
                    'total_questions' => (int) $r->total_questions,
                    'correct_count' => $r->correct_count !== null ? (int) $r->correct_count : null,
                    'score' => $r->score !== null ? (float) $r->score : null,
                ];
            });

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function cart(int $uid): array
    {
        return DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('courses', 'courses.id', '=', 'cart_items.course_id')
            ->where('carts.user_id', $uid)
            ->orderBy('cart_items.id')
            ->get(['cart_items.course_id', 'cart_items.created_at', 'courses.title as course_title'])
            ->map(fn (object $r) => [
                'course' => ['id' => (int) $r->course_id, 'title' => $r->course_title],
                'added_at' => $this->iso($r->created_at),
            ])->all();
    }

    /**
     * Danh sách thư thông báo đã gửi cho phụ huynh (audit `parent_notice.sent`, còn trong hạn lưu). Chỉ loại thư + thời điểm.
     *
     * @return list<array<string, mixed>>
     */
    private function parentNotices(User $user): array
    {
        return AuditLog::query()
            ->where('action', 'parent_notice.sent')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->orderBy('id')
            ->get(['changes', 'created_at'])
            ->map(fn (AuditLog $l) => [
                'kind' => data_get($l, 'changes.kind'),
                'sent_at' => $this->iso($l->created_at),
            ])->all();
    }

    private function assertUnderLimit(int $used): void
    {
        if ($used >= $this->limit()) {
            $resetsAt = $this->resetsAt();

            throw new DataExportLimitException(
                $this->limit(),
                $resetsAt->toIso8601String(),
                (int) ceil($this->nowVn()->diffInSeconds($resetsAt, true)),
            );
        }
    }

    private function usedToday(User $user): int
    {
        return AuditLog::query()
            ->where('actor_id', $user->getKey())
            ->where('action', self::AUDIT_ACTION)
            // Ranh giới ngày theo giờ VN, đổi về múi giờ app (UTC) để so với cột `created_at`.
            ->where('created_at', '>=', $this->nowVn()->startOfDay()->setTimezone((string) config('app.timezone')))
            ->count();
    }

    private function limit(): int
    {
        return max(1, (int) config('privacy.data_export_daily_limit'));
    }

    private function nowVn(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('privacy.age_timezone'));
    }

    private function resetsAt(): CarbonImmutable
    {
        return $this->nowVn()->addDay()->startOfDay();
    }

    private function logChannel(): string
    {
        return array_key_exists('privacy', (array) config('logging.channels')) ? 'privacy' : (string) config('logging.default');
    }

    /** ISO 8601 theo giờ VN (+07:00). Chuỗi từ DB được hiểu là múi giờ app (Laravel lưu theo `app.timezone`). */
    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $at = $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, (string) config('app.timezone'));

        return $at->setTimezone((string) config('privacy.age_timezone'))->toIso8601String();
    }
}
