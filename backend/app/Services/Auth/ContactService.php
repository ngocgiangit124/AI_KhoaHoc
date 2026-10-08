<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Mail\ContactChangedMail;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Otp\OtpService;
use App\Support\LockedUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Đổi email/SĐT (api-contract §2.2, S9): huỷ MỌI mã OTP cũ, reset `*_verified_at` tương ứng,
 * rồi gửi mã mới tới liên hệ mới.
 */
class ContactService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
        private readonly CurrentPasswordGuard $currentPassword,
        private readonly StudentSessionService $sessions,
    ) {}

    /**
     * Che email cho thư thông báo: giữ ký tự đầu của phần local và toàn bộ tên miền (`a***@example.com`).
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * Bắt buộc `current_password` (H1), KỂ CẢ tài khoản chưa xác thực: người vừa đăng ký biết mật khẩu của mình,
     * nên không có lý do miễn; miễn sẽ mở lại đường chiếm tài khoản bằng phiên bị đánh cắp.
     *
     * Đổi email: gửi thông báo tới email CŨ nếu nó đã xác thực, và huỷ mọi phiên khác (bind lại phiên hiện tại).
     *
     * @param  array{email?: string|null, phone?: string|null, current_password: string}  $data  CHỈ `validated()` (đã chuẩn hoá).
     * @return CarbonImmutable|null `resend_available_at`; `null` nếu không gửi OTP
     *                              (không đổi gì, hoặc chỉ đổi SĐT khi kênh sms chưa bật)
     */
    public function update(User $user, array $data, Request $request): ?CarbonImmutable
    {
        $this->currentPassword->assert($user, (string) $data['current_password'], 'account.contact_change_failed');

        $willSendOtp = (isset($data['email']) && $data['email'] !== $user->email)
            || (isset($data['phone']) && $data['phone'] !== $user->phone
                && in_array('sms', (array) config('auth.otp.channels'), true));

        if ($willSendOtp) {
            // Bị chặn bởi trần mã/giờ/ngày thì KHÔNG đổi gì (tránh email đổi rồi mà không có mã).
            $this->otp->assertCanSend($user, OtpPurpose::VerifyAccount, enforceCooldown: false);
        }

        try {
            [$emailChanged, $phoneChanged, $previousEmail] = DB::transaction(function () use ($user, $data): array {
                $locked = LockedUser::lockActive($user->getKey());

                $emailChanged = isset($data['email']) && $data['email'] !== $locked->email;
                $phoneChanged = isset($data['phone']) && $data['phone'] !== $locked->phone;

                if (! $emailChanged && ! $phoneChanged) {
                    return [false, false, null];
                }

                // Chỉ tin email cũ khi nó đã xác thực (người nhận thông báo phải là chủ thật).
                $previousEmail = $emailChanged && $locked->email_verified_at !== null ? $locked->email : null;

                if ($emailChanged) {
                    $locked->email = $data['email'];
                    $locked->forceFill(['email_verified_at' => null]);
                }

                if ($phoneChanged) {
                    $locked->phone = $data['phone'];
                    $locked->forceFill(['phone_verified_at' => null]);
                }

                $locked->save();
                $this->otp->invalidateForChannels($locked, array_values(array_filter([
                    $emailChanged ? 'email' : null,
                    $phoneChanged ? 'sms' : null,
                ])));

                return [$emailChanged, $phoneChanged, $previousEmail];
            });
        } catch (UniqueConstraintViolationException $e) {
            throw RegistrationService::duplicateToValidation($e);
        }

        $user->refresh();

        if (! $emailChanged && ! $phoneChanged) {
            return null;
        }

        // Audit không chứa giá trị email/SĐT (PII), chỉ ghi field nào đổi.
        $this->audit->log('account.contact_changed', $user, ['fields' => array_values(array_filter([
            $emailChanged ? 'email' : null,
            $phoneChanged ? 'phone' : null,
        ]))]);

        if ($emailChanged) {
            $this->notifyPreviousEmail($user, $previousEmail);
            $this->revokeOtherSessions($user, $request);
        }

        // Sửa nhầm email ngay sau đăng ký là luồng chính → không áp cooldown 60s (vẫn áp trần giờ/ngày).
        if ($emailChanged) {
            return $this->otp->issue($user, OtpPurpose::VerifyAccount, 'email', (string) $user->email, enforceCooldown: false);
        }

        if (in_array('sms', (array) config('auth.otp.channels'), true)) {
            return $this->otp->issue($user, OtpPurpose::VerifyAccount, 'sms', (string) $user->phone, enforceCooldown: false);
        }

        return null;
    }

    /** Lỗi gửi thư không được làm hỏng thao tác đã commit; chỉ ghi log (không có email/PII). */
    private function notifyPreviousEmail(User $user, ?string $previousEmail): void
    {
        if ($previousEmail === null) {
            return;
        }

        try {
            Mail::to($previousEmail)->send(new ContactChangedMail(
                $user->name,
                self::maskEmail((string) $user->email),
                now()->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y').' (giờ Việt Nam)',
                (string) config('ops.support_email'),
            ));
        } catch (Throwable $e) {
            Log::error('contact_changed.notify_failed', ['exception' => $e::class]);
        }
    }

    /**
     * Huỷ phiên khác (tombstone `contact_changed` → 401 SESSION_REVOKED) rồi bind lại phiên hiện tại, cùng cách
     * `PasswordService::change()`. Bind lỗi: mọi phiên đã bị huỷ (fail-safe), request vẫn 200.
     */
    private function revokeOtherSessions(User $user, Request $request): void
    {
        $deviceId = $user->current_device_id;

        try {
            $this->sessions->revoke($user, StudentSessionService::REASON_CONTACT_CHANGED);
            $request->session()->regenerate();
            $this->sessions->bind($user, $request, $deviceId);
        } catch (Throwable $e) {
            Log::warning('contact_change.rebind_failed', ['exception' => $e::class]);
        }
    }
}
