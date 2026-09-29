<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * PUT /auth/contact (api-contract §2.2, data-model §3.1 — S9): đổi email/SĐT
 * huỷ mã OTP cũ + reset `*_verified_at` + gửi mã mới.
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

        $channelsToSend = [];

        if ($emailChanged) {
            $channelsToSend[] = 'email';
        }

        if ($phoneChanged) {
            $channelsToSend[] = 'sms';
        }

        // T04 security review R1 [BLOCKER] + M2 [Medium] — TRƯỚC ĐÂY kiểm
        // trần (`assertCanSend()`) rồi mới đổi liên hệ ở 1 transaction RIÊNG:
        // dưới tải đồng thời, 2 request có thể cùng qua bước kiểm (đọc cùng 1
        // số liệu), rồi 1 trong 2 vẫn đổi được liên hệ dù ngay sau đó gửi mã
        // thất bại vì vượt trần — "đã đổi liên hệ nhưng không gửi được OTP".
        // `OtpService::sendAfterContactChange()` gộp TẤT CẢ (khoá hàng user,
        // kiểm trần, đổi email/SĐT, huỷ mã cũ, tạo mã mới) vào 1 transaction
        // NGUYÊN TỬ: vượt trần thì closure đổi liên hệ KHÔNG được chạy, email/
        // SĐT chắc chắn không đổi, kể cả dưới tải đồng thời.
        try {
            $this->otpService->sendAfterContactChange(
                $user,
                OtpPurpose::VerifyAccount,
                function () use ($user, $newEmail, $newPhone, $emailChanged, $phoneChanged): void {
                    if ($emailChanged) {
                        $user->email = $newEmail;
                        // *_verified_at không nằm trong $fillable (S17) — forceFill.
                        $user->forceFill(['email_verified_at' => null]);
                    }

                    if ($phoneChanged) {
                        $user->phone = $newPhone;
                        $user->forceFill(['phone_verified_at' => null]);
                    }
                },
                $channelsToSend,
            );
        } catch (QueryException $e) {
            throw $this->translateUniqueViolation($e);
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
