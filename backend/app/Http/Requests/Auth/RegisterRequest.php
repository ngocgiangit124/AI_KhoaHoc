<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\PhoneNumber;
use App\Support\Age;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Password;

/**
 * POST /auth/register (US-001, api-contract §2.2).
 *
 * S17 — CHỈ các khoá khai ở rules() có thể xuất hiện trong validated():
 * `role`/`status`/`*_verified_at` KHÔNG được khai ở đây, nên không thể lọt
 * qua `$request->validated()` dù client tự thêm vào body.
 *
 * M4 (review docs/security/review-T03-FW1.md) — CỐ Ý KHÔNG kiểm `unique`/
 * `exists` (DB) ở đây: form này validate TRƯỚC KHI Service kiểm captcha, nên
 * một request captcha sai + email/SĐT đã tồn tại sẽ được 422 kèm
 * `errors.email`/`errors.phone` ngay từ FormRequest — dò được tài khoản có
 * tồn tại hay không mà KHÔNG cần giải captcha. Việc kiểm trùng (đúng field,
 * đúng thông điệp như trước) chuyển vào `RegistrationService::register()`,
 * chạy SAU bước kiểm captcha (xem `RegistrationService`).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            // M2 (review docs/security/review-T03-FW1.md) — `ascii`: cùng rủi
            // ro với `login` (biến thể Unicode "trông giống" theo collation
            // MySQL) — email hợp lệ luôn thuần ASCII nên không mất khả năng
            // đăng ký hợp lệ nào.
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'ascii'],
            'phone' => ['required', 'string', 'max:20', function ($attribute, $value, $fail): void {
                if (! is_string($value) || ! PhoneNumber::isValidInput($value)) {
                    $fail('Số điện thoại không đúng định dạng Việt Nam.');
                }
            }],
            'grade_level' => ['required', 'integer', 'between:6,12'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            // Chỉ bắt buộc ≥ 1 trong 2 khi dưới ngưỡng tuổi — kiểm ở withValidator()
            // (phụ thuộc date_of_birth, không diễn tả được bằng rule đơn lẻ).
            // L4 (review bảo mật) — yêu cầu đủ SỐ CHỮ SỐ thật (không chỉ ký tự
            // định dạng): "((((((((" khớp regex cũ nhưng không phải SĐT nào cả.
            'parent_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{8,20}$/', function ($attribute, $value, $fail): void {
                if (! is_string($value) || $value === '') {
                    return;
                }
                $digitCount = strlen((string) preg_replace('/\D/', '', $value));
                if ($digitCount < 8 || $digitCount > 11) {
                    $fail('Số điện thoại phụ huynh không đúng định dạng.');
                }
            }],
            'parent_email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'referral_code' => ['nullable', 'string', 'max:50'],
            'accept_terms' => ['required', 'accepted'],
            'accept_privacy' => ['required', 'accepted'],
            // L5 (review bảo mật) — token Turnstile thật ≤ 2048 ký tự.
            'captcha_token' => ['required', 'string', 'max:2048'],
            // Chưa dùng ở T03 (single-session/bind phiên là T05/ADR-003) — chấp
            // nhận và bỏ qua để không phá hợp đồng khi frontend đã gửi kèm.
            'device_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Bạn cần đồng ý với điều khoản sử dụng.',
            'accept_privacy.accepted' => 'Bạn cần đồng ý với chính sách quyền riêng tư.',
        ];
    }

    /**
     * BR8/AC10 — dưới ngưỡng tuổi (`privacy.parent_consent_age`) lúc đăng ký
     * thì bắt buộc ≥ 1 trong 2: `parent_phone`/`parent_email`.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if ($validator->errors()->has('date_of_birth')) {
                return;
            }

            $dateOfBirth = $this->input('date_of_birth');

            if (! is_string($dateOfBirth) || $dateOfBirth === '') {
                return;
            }

            $thresholdYears = (int) config('privacy.parent_consent_age');

            if (! Age::isMinor(Carbon::parse($dateOfBirth), $thresholdYears)) {
                return;
            }

            $hasParentPhone = filled($this->input('parent_phone'));
            $hasParentEmail = filled($this->input('parent_email'));

            if (! $hasParentPhone && ! $hasParentEmail) {
                $validator->errors()->add(
                    'parent_phone',
                    "Học sinh dưới {$thresholdYears} tuổi cần bổ sung số điện thoại hoặc email phụ huynh."
                );
            }
        });
    }
}
