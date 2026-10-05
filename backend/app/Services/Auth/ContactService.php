<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Otp\OtpService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Đổi email/SĐT (api-contract §2.2, S9): huỷ MỌI mã OTP cũ, reset `*_verified_at` tương ứng,
 * rồi gửi mã mới tới liên hệ mới.
 */
class ContactService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{email?: string|null, phone?: string|null}  $data  CHỈ `validated()` (đã chuẩn hoá).
     * @return CarbonImmutable|null `resend_available_at`; `null` nếu không gửi OTP
     *                              (không đổi gì, hoặc chỉ đổi SĐT khi kênh sms chưa bật)
     */
    public function update(User $user, array $data): ?CarbonImmutable
    {
        $willSendOtp = (isset($data['email']) && $data['email'] !== $user->email)
            || (isset($data['phone']) && $data['phone'] !== $user->phone
                && in_array('sms', (array) config('auth.otp.channels'), true));

        if ($willSendOtp) {
            // Bị chặn bởi trần mã/giờ/ngày thì KHÔNG đổi gì (tránh email đổi rồi mà không có mã).
            $this->otp->assertCanSend($user, OtpPurpose::VerifyAccount, enforceCooldown: false);
        }

        try {
            [$emailChanged, $phoneChanged] = DB::transaction(function () use ($user, $data): array {
                /** @var User $locked */
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $emailChanged = isset($data['email']) && $data['email'] !== $locked->email;
                $phoneChanged = isset($data['phone']) && $data['phone'] !== $locked->phone;

                if (! $emailChanged && ! $phoneChanged) {
                    return [false, false];
                }

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

                return [$emailChanged, $phoneChanged];
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

        // Sửa nhầm email ngay sau đăng ký là luồng chính → không áp cooldown 60s (vẫn áp trần giờ/ngày).
        if ($emailChanged) {
            return $this->otp->issue($user, OtpPurpose::VerifyAccount, 'email', (string) $user->email, enforceCooldown: false);
        }

        if (in_array('sms', (array) config('auth.otp.channels'), true)) {
            return $this->otp->issue($user, OtpPurpose::VerifyAccount, 'sms', (string) $user->phone, enforceCooldown: false);
        }

        return null;
    }
}
