<?php

namespace App\Services\Auth;

use App\Enums\ConsentType;
use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Privacy\ConsentService;
use App\Support\Age;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Đăng ký học sinh (US-001, api-contract §2.2). Duy nhất Service này được tạo
 * user kèm `parent_consent_status` (không nằm trong `User::$fillable` — S17).
 *
 * KHÔNG làm ở đây (ngoài phạm vi T03, xem docs/architecture/tasks.md):
 * - Gửi OTP xác thực (T04).
 * - Gửi email xác nhận phụ huynh khi tài khoản dưới ngưỡng tuổi (T29/US-017).
 * - Bind phiên "1 thiết bị/1 phiên" (T05/ADR-003) — Controller chỉ đăng nhập
 *   đơn giản (`Auth::login` + `session()->regenerate()`).
 */
class RegistrationService
{
    public function __construct(
        private readonly CaptchaVerifier $captcha,
        private readonly ConsentService $consents,
    ) {}

    /**
     * @param  array<string, mixed>  $data  `RegisterRequest::validated()` — KHÔNG bao giờ chứa
     *                                      role/status/*_verified_at (S17).
     */
    public function register(array $data, string $ip, ?string $userAgent): User
    {
        if (! $this->captcha->verify((string) ($data['captcha_token'] ?? ''), $ip)) {
            throw new DomainException(
                code: 'CAPTCHA_FAILED',
                message: 'Xác minh captcha không thành công, vui lòng thử lại.',
                status: 422,
            );
        }

        $dateOfBirth = Carbon::parse((string) $data['date_of_birth']);
        $thresholdYears = (int) config('privacy.parent_consent_age');
        $isMinor = Age::isMinor($dateOfBirth, $thresholdYears);

        $phone = PhoneNumber::fromInput((string) $data['phone'])->value();
        $email = mb_strtolower(trim((string) $data['email']));

        // M4 (review docs/security/review-T03-FW1.md) — kiểm trùng SAU captcha
        // (RegisterRequest cố tình KHÔNG dùng `unique`/`exists` — xem docblock
        // của class). Gộp cả 2 lỗi vào 1 ValidationException (giống hành vi cũ
        // của FormRequest) thay vì dừng ở lỗi đầu tiên.
        $this->assertNotDuplicate($email, $phone);

        $attributes = [
            // Trùng với default của cột trong migration — khai TƯỜNG MINH ở đây
            // (thay vì để DB tự áp default) để model trong bộ nhớ có ngay giá trị
            // đúng sau forceCreate(), không cần refresh() (Model::shouldBeStrict()
            // ném MissingAttributeException nếu truy cập thuộc tính DB-default mà
            // chưa được gán trên instance hiện tại).
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'name' => (string) $data['name'],
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make((string) $data['password']),
            'grade_level' => (int) $data['grade_level'],
            'date_of_birth' => $dateOfBirth->toDateString(),
            'parent_phone' => self::normalizeParentPhoneOrNull($data['parent_phone'] ?? null),
            'parent_email' => self::normalizeEmailOrNull($data['parent_email'] ?? null),
            'referral_code_used' => config('features.referral_code')
                ? self::blankToNull($data['referral_code'] ?? null)
                : null,
            // Não bằng $fillable của User (S17) — chỉ RegistrationService được set.
            'parent_consent_status' => $isMinor ? ParentConsentStatus::Pending : ParentConsentStatus::NotRequired,
        ];

        try {
            $user = DB::transaction(function () use ($attributes, $ip, $userAgent) {
                // forceCreate (không phải create()) — S17: RegistrationService là
                // Service chuyên trách DUY NHẤT được set parent_consent_status lúc
                // tạo tài khoản (giống quy ước staff:create của T02).
                $user = User::query()->forceCreate($attributes);

                // Checkbox đồng ý tách riêng, không tick sẵn (S7) — RegisterRequest
                // bắt buộc accept_terms/accept_privacy trước khi tới đây.
                $this->consents->grant($user, ConsentType::Terms, 'self', 'web_form', $ip, $userAgent);
                $this->consents->grant($user, ConsentType::PrivacyPolicy, 'self', 'web_form', $ip, $userAgent);

                return $user;
            });
        } catch (QueryException $e) {
            throw $this->translateUniqueViolation($e);
        }

        // TODO(T04): gửi OTP xác thực email/SĐT ngay sau khi đăng ký (AC1, BR7).
        // TODO(T29 — US-017): nếu $isMinor, gửi email xác nhận cho phụ huynh.

        return $user;
    }

    /**
     * M4 — kiểm trùng CHỦ ĐỘNG (thay cho `Rule::unique`/`exists` đã bỏ khỏi
     * `RegisterRequest`), chạy SAU khi captcha đã đúng. Gộp lỗi của cả 2 field
     * vào 1 `ValidationException` (giữ nguyên hành vi cũ: cả email lẫn SĐT
     * trùng thì báo cả hai trong cùng 1 response, xem AC2).
     */
    private function assertNotDuplicate(string $email, string $phone): void
    {
        $errors = [];

        if (User::query()->where('email', $email)->exists()) {
            $errors['email'] = ['Email đã được sử dụng.'];
        }

        if (User::query()->where('phone', $phone)->exists()) {
            $errors['phone'] = ['Số điện thoại đã được sử dụng.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * S17/DBA #? — bắt lỗi trùng khoá xảy ra do race condition (2 request đăng
     * ký cùng email/SĐT gần như đồng thời, cả hai đều qua được kiểm tra
     * `assertNotDuplicate()` trước khi 1 trong 2 insert trước) và trả về 422
     * đúng field thay vì để lộ 500 (data-model §4).
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

    private static function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeEmailOrNull(mixed $value): ?string
    {
        $trimmed = self::blankToNull($value);

        return $trimmed === null ? null : mb_strtolower($trimmed);
    }

    /**
     * L4 (review docs/security/review-T03-FW1.md) — lưu `parent_phone` ở dạng
     * SẠCH (chỉ chữ số, giữ `+` đầu nếu có) thay vì nguyên văn người dùng gõ:
     * trước đây `((((((((`  qua được validate (khớp regex định dạng) và được
     * lưu y nguyên. Không dùng `PhoneNumber` (chỉ nhận di động VN) vì phụ
     * huynh có thể dùng số cố định — `RegisterRequest` đã kiểm đủ số CHỮ SỐ.
     */
    private static function normalizeParentPhoneOrNull(mixed $value): ?string
    {
        $trimmed = self::blankToNull($value);

        if ($trimmed === null) {
            return null;
        }

        $hasLeadingPlus = str_starts_with($trimmed, '+');
        $digits = (string) preg_replace('/\D/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        return $hasLeadingPlus ? '+'.$digits : $digits;
    }
}
