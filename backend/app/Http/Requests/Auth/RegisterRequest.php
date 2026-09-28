<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\PhoneNumber;
use App\Support\Age;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /auth/register (US-001, api-contract §2.2).
 *
 * S17 — CHỈ các khoá khai ở rules() có thể xuất hiện trong validated():
 * `role`/`status`/`*_verified_at` KHÔNG được khai ở đây, nên không thể lọt
 * qua `$request->validated()` dù client tự thêm vào body.
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
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:20', function ($attribute, $value, $fail): void {
                if (! is_string($value) || ! PhoneNumber::isValidInput($value)) {
                    $fail('Số điện thoại không đúng định dạng Việt Nam.');

                    return;
                }

                $normalized = PhoneNumber::fromInput($value)->value();

                if (User::query()->where('phone', $normalized)->exists()) {
                    $fail('Số điện thoại đã được sử dụng.');
                }
            }],
            'grade_level' => ['required', 'integer', 'between:6,12'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            // Chỉ bắt buộc ≥ 1 trong 2 khi dưới ngưỡng tuổi — kiểm ở withValidator()
            // (phụ thuộc date_of_birth, không diễn tả được bằng rule đơn lẻ).
            'parent_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{8,20}$/'],
            'parent_email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'referral_code' => ['nullable', 'string', 'max:50'],
            'accept_terms' => ['required', 'accepted'],
            'accept_privacy' => ['required', 'accepted'],
            'captcha_token' => ['required', 'string'],
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
            'email.unique' => 'Email đã được sử dụng.',
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
