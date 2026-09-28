<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PUT /auth/contact (api-contract §2.2, data-model §3.1 — S9): đổi email/SĐT
 * huỷ mã OTP cũ của kênh tương ứng + reset `*_verified_at` + gửi mã mới.
 *
 * Controller mỏng — logic đặt ở đây thay vì `OtpService` vì phần "cập nhật
 * user + kiểm trùng" không phải nghiệp vụ OTP.
 */
class ContactService
{
    public function __construct(private readonly OtpService $otpService) {}

    /**
     * @param  array{email?: string|null, phone?: string|null}  $validated  `UpdateContactRequest::validated()`.
     */
    public function update(User $user, array $validated): User
    {
        $newEmail = self::normalizedOrNull($validated['email'] ?? null, isEmail: true);
        $newPhone = self::normalizedOrNull($validated['phone'] ?? null, isEmail: false);

        $emailChanged = $newEmail !== null && $newEmail !== $user->email;
        $phoneChanged = $newPhone !== null && $newPhone !== $user->phone;

        if (! $emailChanged && ! $phoneChanged) {
            return $user;
        }

        // T04 review R1 [BLOCKER] — kiểm trần gửi OTP TRƯỚC khi đổi bất kỳ
        // thông tin liên hệ nào (fail-closed). Quyết định xử lý khi vượt trần:
        // TỪ CHỐI 429 ngay, KHÔNG đổi email/SĐT — tránh trạng thái nửa vời
        // "đã đổi liên hệ nhưng không gửi được OTP xác thực cho giá trị mới"
        // (người dùng không biết vì sao chưa nhận được mã), và không mở thêm
        // đường nào để dùng endpoint này đổi liên hệ qua lại không giới hạn dù
        // có gửi được OTP hay không. Không kiểm cho kênh chưa được bật (vd
        // 'sms' ở production) — `assertCanSend()` tự bỏ qua, khớp hành vi của
        // `sendIfChannelEnabled()` bên dưới.
        if ($emailChanged) {
            $this->otpService->assertCanSend($user, 'email');
        }

        if ($phoneChanged) {
            $this->otpService->assertCanSend($user, 'sms');
        }

        try {
            DB::transaction(function () use ($user, $newEmail, $newPhone, $emailChanged, $phoneChanged): void {
                if ($emailChanged) {
                    $user->email = $newEmail;
                    // *_verified_at không nằm trong $fillable (S17) — forceFill.
                    $user->forceFill(['email_verified_at' => null]);
                }

                if ($phoneChanged) {
                    $user->phone = $newPhone;
                    $user->forceFill(['phone_verified_at' => null]);
                }

                $user->save();
            });
        } catch (QueryException $e) {
            throw $this->translateUniqueViolation($e);
        }

        // Kênh không được bật (vd 'sms' ở production) thì bỏ qua việc gửi —
        // *_verified_at vẫn được reset ở trên, phản ánh đúng trạng thái "chưa
        // xác thực" của thông tin liên hệ mới.
        if ($emailChanged) {
            $this->otpService->sendIfChannelEnabled($user, OtpPurpose::VerifyAccount, 'email');
        }

        if ($phoneChanged) {
            $this->otpService->sendIfChannelEnabled($user, OtpPurpose::VerifyAccount, 'sms');
        }

        return $user;
    }

    private static function normalizedOrNull(mixed $value, bool $isEmail): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $isEmail
            ? mb_strtolower(trim($value))
            : PhoneNumber::fromInput($value)->value();
    }

    /**
     * Cùng mẫu với `RegistrationService::translateUniqueViolation()` — bắt lỗi
     * trùng khoá do race condition (2 request đổi liên hệ cùng lúc).
     */
    private function translateUniqueViolation(QueryException $e): ValidationException
    {
        if ($e->getCode() !== '23000') {
            throw $e;
        }

        $message = mb_strtolower($e->getMessage());

        if (str_contains($message, 'users_phone_unique')) {
            return ValidationException::withMessages(['phone' => ['Số điện thoại đã được sử dụng.']]);
        }

        if (str_contains($message, 'users_email_unique')) {
            return ValidationException::withMessages(['email' => ['Email đã được sử dụng.']]);
        }

        throw $e;
    }
}
