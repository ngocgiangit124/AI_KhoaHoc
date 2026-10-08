<?php

namespace App\Services\Privacy;

use App\Enums\OrderStatus;
use App\Enums\OtpPurpose;
use App\Enums\PaymentAttemptStatus;
use App\Exceptions\DomainException;
use App\Jobs\FinalizeAccountDeletionJob;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ContactService;
use App\Services\Auth\Otp\OtpService;
use App\Services\Auth\StudentSessionService;
use App\Services\Content\ImageUploadService;
use App\Services\Teachers\TeacherProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Xoá tài khoản học sinh = ẩn danh hoá (US-018, api-contract §2.8.5, ADR-006 §6). Không xoá dòng `users`.
 *
 * Pha A (class này, MỘT transaction do `OtpService::consume` mở và đã khoá `users` FOR UPDATE): xoá PII theo bảng ở
 * tasks.md T34.3, thu hồi `consents` + xoá ip/ua, xoá `otp_codes`, audit. Thứ tự khoá chuẩn: users → teacher_profiles →
 * consents → otp_codes. Sau commit: huỷ mọi phiên. Pha B: `FinalizeAccountDeletionJob` (đơn pending, yêu cầu học, giỏ).
 */
class AccountAnonymizer
{
    public const DELETED_NAME = 'Tài khoản đã xoá';

    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
        private readonly StudentSessionService $sessions,
        private readonly TeacherProfileService $teacherProfiles,
        private readonly ImageUploadService $images,
    ) {}

    /**
     * @throws DomainException ACCOUNT_NOT_VERIFIED 403 · ACCOUNT_HAS_PENDING_PAYMENT 409 (`retry_after_at`)
     */
    public function assertDeletable(User $user): void
    {
        if ($user->email === null || $user->email === '' || $user->email_verified_at === null) {
            throw new DomainException('ACCOUNT_NOT_VERIFIED', 'Bạn cần xác thực email trước khi xoá tài khoản.', 403);
        }

        $retryAt = $this->livePaymentUntil((int) $user->getKey());

        if ($retryAt !== null) {
            throw new DomainException(
                'ACCOUNT_HAS_PENDING_PAYMENT',
                'Bạn đang có đơn hàng chờ thanh toán. Vui lòng thử lại sau khi đơn hết hạn hoặc hoàn tất.',
                409,
                ['retry_after_at' => $retryAt->setTimezone((string) config('privacy.age_timezone'))->toIso8601String()],
            );
        }
    }

    /**
     * @return array{resend_available_at: CarbonImmutable, destination_masked: string}
     */
    public function sendOtp(User $user): array
    {
        $this->assertDeletable($user);

        $email = (string) $user->email;
        $resendAt = $this->otp->issue($user, OtpPurpose::DeleteAccount, 'email', $email);

        $this->audit->log('privacy.account_delete_otp_sent', $user);

        return ['resend_available_at' => $resendAt, 'destination_masked' => ContactService::maskEmail($email)];
    }

    /**
     * Kiểm mã rồi ẩn danh hoá trong CÙNG transaction với việc tiêu thụ mã (2 request song song cùng mã: đúng 1 thắng).
     * Sau commit huỷ mọi phiên (tombstone `account_deleted`). Việc đăng xuất phiên HTTP hiện tại do controller làm.
     */
    public function confirm(User $user, #[\SensitiveParameter] string $code): void
    {
        $this->assertDeletable($user);

        $this->otp->consume(
            $user,
            OtpPurpose::DeleteAccount,
            $code,
            // Mã gắn với email nó được gửi tới: đổi email (hoặc đã ẩn danh) sau khi gửi mã thì mã vô hiệu.
            static fn (OtpCode $otp, User $u): bool => $u->anonymized_at === null
                && $u->email !== null && $u->email_verified_at !== null && hash_equals($otp->destination, $u->email),
            fn (OtpCode $otp, User $locked) => $this->anonymizeLocked($locked),
        );

        $this->sessions->revoke($user, StudentSessionService::REASON_ACCOUNT_DELETED);
    }

    /**
     * Gọi TRONG transaction, `$locked` đã khoá FOR UPDATE.
     */
    private function anonymizeLocked(User $locked): void
    {
        $userId = (int) $locked->getKey();

        // Kiểm lại dưới khoá (đơn có link sống xuất hiện giữa lúc gửi mã và lúc xác nhận): rollback cả việc tiêu mã.
        $this->assertDeletable($locked);

        // 1. Hồ sơ giáo viên (nếu có): transaction lồng thành savepoint.
        $this->teacherProfiles->erase($locked);

        // 2. Xoá PII khỏi `users`. Giữ id, role, status, grade_level, *_verified_at, created_at, last_login_at.
        // Hai cột cũ của GV (T36-1 sẽ xoá): gán riêng, không qua forceFill (test kiến trúc cấm forceFill khoá hồ sơ ngoài TeacherProfileService).
        $legacyAvatar = is_string($locked->avatar_path) ? $locked->avatar_path : null;
        $locked->bio = null;
        $locked->avatar_path = null;
        $locked->forceFill([
            'name' => self::DELETED_NAME,
            'email' => null,
            'phone' => null,
            'date_of_birth' => null,
            'parent_email' => null,
            'parent_phone' => null,
            'parent_notice_opt_out_at' => null,
            'referral_code_used' => null,
            'remember_token' => null,
            'current_device_id' => null,
            'password' => Hash::make(Str::random(64)),
            'anonymized_at' => now(),
        ])->save();

        // Ảnh ở cột cũ `users.avatar_path` (T34 S4): xoá file sau commit.
        DB::afterCommit(fn () => $this->images->delete($legacyAvatar));

        // 3. consents: thu hồi dòng còn hiệu lực, xoá ip/ua/đích đã che của MỌI dòng (giữ loại/phiên bản/thời điểm).
        DB::update(
            'UPDATE consents SET revoked_at = COALESCE(revoked_at, ?), ip = NULL, user_agent = NULL, destination_masked = NULL WHERE user_id = ?',
            [now(), $userId],
        );

        // 4. otp_codes: cột `destination` chứa email.
        OtpCode::query()->where('user_id', $userId)->delete();

        // 5. Audit: actor = chính học sinh, không có giá trị cũ (PII).
        $this->audit->log('privacy.account_anonymized', $locked);

        // 6. Pha B chỉ chạy khi pha A đã commit.
        DB::afterCommit(fn () => FinalizeAccountDeletionJob::dispatch($userId));
    }

    /**
     * `expires_at` muộn nhất của các link thanh toán còn sống (đơn `pending` + attempt `pending` chưa hết hạn), hoặc null.
     */
    private function livePaymentUntil(int $userId): ?CarbonImmutable
    {
        $max = DB::table('orders')
            ->join('payment_attempts', 'payment_attempts.order_id', '=', 'orders.id')
            ->where('orders.user_id', $userId)
            ->where('orders.status', OrderStatus::Pending->value)
            ->where('payment_attempts.status', PaymentAttemptStatus::Pending->value)
            ->where('payment_attempts.expires_at', '>', now())
            ->max('payment_attempts.expires_at');

        return $max === null ? null : CarbonImmutable::parse((string) $max, (string) config('app.timezone'));
    }
}
