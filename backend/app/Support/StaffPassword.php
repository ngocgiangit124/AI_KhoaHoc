<?php

namespace App\Support;

use App\Rules\NotCommonPassword;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

/**
 * Chính sách mật khẩu staff (M1 review bảo mật cụm 1): tối thiểu 12 ký tự, không nằm trong danh sách phổ biến cục bộ
 * và không chứa phần trước `@` của email (khi đủ dài để có nghĩa). Mật khẩu hệ thống sinh
 * (`StaffAccountService::PASSWORD_LENGTH` = 20) phải thoả quy tắc này.
 */
final class StaffPassword
{
    public const MIN_LENGTH = 12;

    /**
     * @return list<mixed>
     */
    public static function rules(?string $email = null): array
    {
        $rules = [Password::min(self::MIN_LENGTH)->rules([new NotCommonPassword])];

        $local = $email !== null ? mb_strtolower(strstr($email, '@', true) ?: '') : '';

        if (mb_strlen($local) >= 4) {
            $rules[] = new class($local) implements ValidationRule
            {
                public function __construct(private readonly string $local) {}

                public function validate(string $attribute, mixed $value, \Closure $fail): void
                {
                    if (is_string($value) && str_contains(mb_strtolower($value), $this->local)) {
                        $fail('Mật khẩu không được chứa tên đăng nhập (phần trước @ của email).');
                    }
                }
            };
        }

        return $rules;
    }
}
