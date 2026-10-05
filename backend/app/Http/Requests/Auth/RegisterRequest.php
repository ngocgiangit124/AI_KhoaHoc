<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\DomainException;
use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Auth\PhoneNumber;
use App\Services\Auth\RegistrationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * api-contract §2.2 — POST /auth/register. `role`/`status`/`*_verified_at` không có
 * trong rules nên không bao giờ nằm trong `validated()` (S17).
 */
class RegisterRequest extends FormRequest
{
    /** Chặn khoảng trắng/tab, comment RFC `(c)`, dấu phân tách lạ mà `email:rfc` vẫn nhận (BUG-1). */
    public const EMAIL_SAFE_PATTERN = '/^[^\s()<>,;:"\\\\\\[\\]@]+@[^\s()<>,;:"\\\\\\[\\]@]+$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Captcha kiểm TRƯỚC mọi rule (kể cả unique) để bot không dò email/SĐT tồn tại
     * mà chưa qua captcha (S20, S10). Chuẩn hoá email/SĐT để unique/throttle thấy đúng 1 dạng.
     */
    protected function prepareForValidation(): void
    {
        // Không có session hợp lệ (Origin lạ/curl) thì dừng ngay, không tốn 1 lệnh gọi Cloudflare (R4).
        if (! $this->hasSession()) {
            throw new DomainException('ORIGIN_NOT_ALLOWED', 'Nguồn gọi không được phép.', 400);
        }

        $token = $this->input('captcha_token');

        if (! app(CaptchaVerifier::class)->verify(is_string($token) ? $token : null, $this->ip())) {
            throw new DomainException(
                code: 'CAPTCHA_FAILED',
                message: 'Xác minh captcha không thành công. Vui lòng thử lại.',
                status: 422,
            );
        }

        $normalized = [];

        foreach (['name', 'email', 'referral_code'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (isset($normalized['email'])) {
            $normalized['email'] = mb_strtolower($normalized['email']);
        }

        $phone = PhoneNumber::normalize($this->input('phone'));
        if ($phone !== null) {
            $normalized['phone'] = $phone;
        }

        $parentPhone = PhoneNumber::normalize($this->input('parent_phone'));
        if ($parentPhone !== null) {
            $normalized['parent_phone'] = $parentPhone;
        }

        if (is_string($this->input('parent_email'))) {
            $normalized['parent_email'] = mb_strtolower(trim($this->input('parent_email')));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsParent = $this->isMinor();

        return [
            'name' => ['required', 'string', 'max:150', 'regex:/^[^\p{C}]+$/u'],
            'date_of_birth' => [
                'required',
                'date_format:Y-m-d',
                'before:today',
                'after:'.CarbonImmutable::now()->subYears(120)->toDateString(),
            ],
            'email' => ['required', 'string', 'email:rfc,strict', 'regex:'.self::EMAIL_SAFE_PATTERN, 'max:254', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:/^0[35789]\d{8}$/', Rule::unique('users', 'phone')],
            'grade_level' => ['required', 'integer', 'between:6,12'],
            'password' => ['required', 'string', Password::defaults(), 'max:128'],
            // AC5: lỗi xác nhận nằm ở field password_confirmation (không dùng `confirmed`).
            'password_confirmation' => ['required', 'string', 'same:password'],
            'parent_phone' => [
                $needsParent ? 'required_without:parent_email' : 'nullable',
                'nullable',
                'string',
                'regex:/^0[35789]\d{8}$/',
            ],
            'parent_email' => [
                $needsParent ? 'required_without:parent_phone' : 'nullable',
                'nullable',
                'string',
                'email:rfc,strict',
                'regex:'.self::EMAIL_SAFE_PATTERN,
                'max:254',
            ],
            'referral_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'captcha_token' => ['nullable', 'string', 'max:2048'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ tên.',
            'name.regex' => 'Họ tên chứa ký tự không hợp lệ.',
            'date_of_birth.required' => 'Vui lòng nhập ngày sinh.',
            'date_of_birth.date_format' => 'Ngày sinh không đúng định dạng.',
            'date_of_birth.before' => 'Ngày sinh không hợp lệ.',
            'date_of_birth.after' => 'Ngày sinh không hợp lệ.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không đúng định dạng.',
            'email.unique' => 'Email đã được sử dụng.',
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.regex' => 'Số điện thoại không đúng định dạng.',
            'phone.unique' => 'Số điện thoại đã được sử dụng.',
            'grade_level.required' => 'Vui lòng chọn lớp đang học.',
            'grade_level.integer' => 'Lớp học không hợp lệ.',
            'grade_level.between' => 'Lớp học phải từ 6 đến 12.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'password.min' => 'Mật khẩu tối thiểu 8 ký tự.',
            'password_confirmation.required' => 'Vui lòng nhập lại mật khẩu.',
            'password_confirmation.same' => 'Xác nhận mật khẩu không khớp.',
            'parent_phone.required_without' => 'Vui lòng nhập số điện thoại hoặc email phụ huynh.',
            'parent_phone.regex' => 'Số điện thoại phụ huynh không đúng định dạng.',
            'parent_email.required_without' => 'Vui lòng nhập số điện thoại hoặc email phụ huynh.',
            'parent_email.email' => 'Email phụ huynh không đúng định dạng.',
            'accept_terms.accepted' => 'Bạn cần đồng ý với Điều khoản sử dụng.',
            'accept_privacy.accepted' => 'Bạn cần đồng ý với Chính sách bảo mật.',
        ];
    }

    private function isMinor(): bool
    {
        $dob = $this->input('date_of_birth');

        if (! is_string($dob) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) !== 1 || ! $this->isRealDate($dob)) {
            return false;
        }

        return RegistrationService::isBelowConsentAge($dob);
    }

    private function isRealDate(string $date): bool
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }
}
