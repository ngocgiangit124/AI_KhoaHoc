<?php

namespace App\Services\Auth;

use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Privacy\ConsentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Đăng ký học sinh (US-001). Chỉ tạo tài khoản + bằng chứng đồng ý + (nếu cần)
 * đánh dấu chờ phụ huynh. Gửi OTP (T04), bind phiên (T05) và email phụ huynh
 * (T29) KHÔNG nằm ở đây.
 */
class RegistrationService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly LoginService $login,
    ) {}

    /**
     * Tuổi tính theo ngày hiện tại ở múi giờ Việt Nam.
     */
    public static function isBelowConsentAge(string $dateOfBirth): bool
    {
        $timezone = (string) config('privacy.age_timezone');
        $dob = CarbonImmutable::createFromFormat('Y-m-d', $dateOfBirth, $timezone);

        return $dob !== null
            && $dob->age < (int) config('privacy.parent_consent_age');
    }

    /**
     * @param  array<string, mixed>  $data  CHỈ `$request->validated()` (S17); email/phone đã chuẩn hoá.
     */
    public function register(array $data, Request $request): User
    {
        $minor = self::isBelowConsentAge((string) $data['date_of_birth']);

        try {
            $user = DB::transaction(function () use ($data, $request, $minor): User {
                $user = new User([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'grade_level' => $data['grade_level'],
                    'date_of_birth' => $data['date_of_birth'],
                    'parent_phone' => $minor ? ($data['parent_phone'] ?? null) : null,
                    'parent_email' => $minor ? ($data['parent_email'] ?? null) : null,
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
                    'parent_consent_status' => $minor
                        ? ParentConsentStatus::Pending
                        : ParentConsentStatus::NotRequired,
                ])->save();

                $this->consents->recordSelfConsentAtRegistration($user, $request);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Race: 2 request cùng email/SĐT cùng vượt qua rule unique.
            throw $this->duplicateToValidation($e);
        }

        $this->login->startSession($request, $user);

        return $user;
    }

    private function duplicateToValidation(UniqueConstraintViolationException $e): ValidationException
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
