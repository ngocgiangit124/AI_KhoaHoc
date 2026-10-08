<?php

namespace App\Http\Requests\Privacy;

use App\Services\Privacy\ParentContactInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /me/parent-contact (api-contract §2.8.2). Thiếu key = giữ nguyên; `null`/`""` = xoá.
 */
class UpdateParentContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ParentContactInput::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'current_password' => ['required', 'string', 'max:128'],
            'parent_email' => [...ParentContactInput::emailRules(null), $this->differentFrom($user?->email, 'Email phụ huynh phải khác email của bạn.', true)],
            'parent_phone' => [...ParentContactInput::phoneRules(null), $this->differentFrom($user?->phone, 'Số điện thoại phụ huynh phải khác số của bạn.', false)],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('parent_email') && ! $this->has('parent_phone')) {
                    $validator->errors()->add('parent_email', 'Vui lòng nhập thông tin cần cập nhật.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
        ] + ParentContactInput::messages();
    }

    private function differentFrom(?string $own, string $message, bool $caseInsensitive): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($own, $message, $caseInsensitive): void {
            if (! is_string($value) || $own === null || $own === '') {
                return;
            }

            if (($caseInsensitive ? mb_strtolower($value) : $value) === ($caseInsensitive ? mb_strtolower($own) : $own)) {
                $fail($message);
            }
        };
    }
}
