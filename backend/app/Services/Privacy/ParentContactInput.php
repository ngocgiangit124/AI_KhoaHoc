<?php

namespace App\Services\Privacy;

use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\PhoneNumber;

/**
 * Chuẩn hoá + rule dùng chung cho liên hệ phụ huynh ở đăng ký và `PUT /me/parent-contact` (api-contract §2.2, §2.8.2).
 */
final class ParentContactInput
{
    /**
     * Chuẩn hoá giá trị thô: chuỗi trống/chỉ khoảng trắng → null, email lowercase + trim, SĐT về `0xxxxxxxxx`.
     * Giá trị không chuẩn hoá được (SĐT rác) giữ nguyên để rule `regex` báo lỗi.
     *
     * @param  array<array-key, mixed>  $input
     * @return array{parent_email?: mixed, parent_phone?: mixed} chỉ gồm key có trong `$input`
     */
    public static function normalize(array $input): array
    {
        $out = [];

        if (array_key_exists('parent_email', $input)) {
            $email = $input['parent_email'];
            $out['parent_email'] = is_string($email)
                ? (trim($email) === '' ? null : mb_strtolower(trim($email)))
                : $email;
        }

        if (array_key_exists('parent_phone', $input)) {
            $phone = $input['parent_phone'];
            $out['parent_phone'] = is_string($phone)
                ? (trim($phone) === '' ? null : (PhoneNumber::normalize($phone) ?? trim($phone)))
                : $phone;
        }

        return $out;
    }

    /**
     * @param  string|null  $ownEmailField  tên field email của học sinh trong cùng request để so sánh (`email` ở đăng ký);
     *                                      null = request tự kiểm bằng closure (PUT /me/parent-contact)
     * @return list<mixed>
     */
    public static function emailRules(?string $ownEmailField = 'email'): array
    {
        $rules = ['nullable', 'string', 'email:rfc,strict', 'regex:'.RegisterRequest::EMAIL_SAFE_PATTERN, 'max:254'];

        if ($ownEmailField !== null) {
            $rules[] = 'different:'.$ownEmailField;
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    public static function phoneRules(?string $ownPhoneField = 'phone'): array
    {
        $rules = ['nullable', 'string', 'regex:/^0[35789]\d{8}$/'];

        if ($ownPhoneField !== null) {
            $rules[] = 'different:'.$ownPhoneField;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'parent_phone.regex' => 'Số điện thoại phụ huynh không đúng định dạng.',
            'parent_phone.different' => 'Số điện thoại phụ huynh phải khác số của bạn.',
            'parent_email.email' => 'Email phụ huynh không đúng định dạng.',
            'parent_email.regex' => 'Email phụ huynh không đúng định dạng.',
            'parent_email.different' => 'Email phụ huynh phải khác email của bạn.',
        ];
    }
}
