<?php

namespace App\Services\Auth;

use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use App\Services\Privacy\ConsentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Đăng ký học sinh (US-001). Tạo tài khoản + bằng chứng đồng ý, rồi gửi OTP xác thực (T04). Thư thông báo phụ huynh gửi sau
 * khi học sinh xác thực OTP lần đầu (T29, `OtpService::verifyAccount`). Bind phiên (T05) nằm ở `LoginService`.
 */
class RegistrationService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly LoginService $login,
        private readonly OtpService $otp,
    ) {}

    /**
     * @param  array<string, mixed>  $data  CHỈ `$request->validated()` (S17); email/phone đã chuẩn hoá.
     */
    public function register(array $data, Request $request): User
    {
        try {
            $user = DB::transaction(function () use ($data, $request): User {
                $user = new User([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'grade_level' => $data['grade_level'],
                    'date_of_birth' => $data['date_of_birth'],
                    // ADR-006: liên hệ phụ huynh tuỳ chọn, lưu với mọi độ tuổi.
                    'parent_phone' => $data['parent_phone'] ?? null,
                    'parent_email' => $data['parent_email'] ?? null,
                    'referral_code_used' => config('features.referral_code')
                        ? ($data['referral_code'] ?? null)
                        : null,
                ]);

                // BR4/S17: vai trò, trạng thái, xác thực do server quyết định, không bao giờ từ client.
                $user->forceFill([
                    'role' => UserRole::Student,
                    'status' => UserStatus::Active,
                    'email_verified_at' => null,
                    'phone_verified_at' => null,
                    // ADR-006: không còn phụ huynh đồng ý; cột giữ cho tương thích API v1 và luôn là not_required.
                    'parent_consent_status' => ParentConsentStatus::NotRequired,
                ])->save();

                $this->consents->recordSelfConsentAtRegistration($user, $request);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Race: 2 request cùng email/SĐT cùng vượt qua rule unique.
            throw self::duplicateToValidation($e);
        }

        // Tài khoản đã commit: lỗi bind phiên (DB) không được biến thành 500 khiến người dùng đăng ký lại
        // và vấp lỗi trùng. Trả 201 không có phiên (FE yêu cầu đăng nhập). Log không chứa PII.
        try {
            $this->login->startSession($request, $user);
        } catch (Throwable $e) {
            Log::error('registration.session_bind_failed', ['exception' => $e::class, 'user_id' => $user->id]);

            return $user;
        }

        // AC1: gửi OTP xác thực qua email. Sự cố gửi (queue/mail) KHÔNG làm hỏng đăng ký — tài khoản
        // đã tạo, học sinh bấm "Gửi lại mã" được. Không log nội dung có mã (chỉ báo lỗi hệ thống).
        try {
            $this->otp->sendVerification($user, 'email');
        } catch (Throwable $e) {
            report($e);
        }

        return $user;
    }

    public static function duplicateToValidation(UniqueConstraintViolationException $e): ValidationException
    {
        // Chỉ xét tên index sau "for key" — KHÔNG xét giá trị trùng (do người dùng kiểm soát).
        $key = preg_match("/for key '([^']+)'/", $e->getMessage(), $m) === 1 ? $m[1] : '';

        return match (true) {
            str_ends_with($key, '.users_phone_unique') || $key === 'users_phone_unique' => ValidationException::withMessages(['phone' => 'Số điện thoại đã được sử dụng.']),
            str_ends_with($key, '.users_email_unique') || $key === 'users_email_unique' => ValidationException::withMessages(['email' => 'Email đã được sử dụng.']),
            // Không đoán field: để handler trả 500 có request_id.
            default => throw $e,
        };
    }
}
