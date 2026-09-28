<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Xác thực đăng nhập học sinh (host api — US-001 §2.2, BR1/BR5).
 *
 * Thứ tự bắt buộc theo api-contract §2.2/§1.7 (không được đảo):
 * 1. Sai email/SĐT hoặc sai mật khẩu → 422 thông điệp CHUNG (không tiết lộ
 *    tài khoản có tồn tại hay không — BR5, S20).
 * 2. Mật khẩu đúng nhưng tài khoản bị khoá → 403 `ACCOUNT_LOCKED` — CHỈ trả
 *    khi mật khẩu đúng (S20, chống dò tài khoản qua thông điệp "bị khoá").
 * 3. Mật khẩu đúng, tài khoản không khoá, nhưng vai trò không phải `hoc_sinh`
 *    → 403 `WRONG_PORTAL` (host api chỉ dành cho học sinh).
 */
class LoginService
{
    /**
     * Hash bcrypt "giả" dùng khi không tìm thấy tài khoản — giữ cho
     * `Hash::check()` luôn thực thi với chi phí tương đương dù tài khoản có
     * tồn tại hay không (giảm nhẹ rủi ro dò tài khoản qua thời gian phản hồi;
     * không phải yêu cầu cứng của story, chi phí thêm không đáng kể).
     */
    private const DUMMY_HASH = '$2y$12$CwmYqfQK9v3rC9wR1nE9qOqf1kNq9m8Yv2yq7B0m8b7q6qYV0ZgWK';

    public function authenticate(string $login, string $password): User
    {
        $user = $this->findByLogin($login);

        $hashToCheck = $user !== null ? $user->password : self::DUMMY_HASH;

        if ($user === null || ! Hash::check($password, $hashToCheck)) {
            throw $this->genericFailure();
        }

        if ($user->status === UserStatus::Locked) {
            throw new DomainException(
                code: 'ACCOUNT_LOCKED',
                message: 'Tài khoản của bạn đã bị khoá.',
                status: 403,
            );
        }

        if ($user->role !== UserRole::Student) {
            throw new DomainException(
                code: 'WRONG_PORTAL',
                message: 'Vui lòng đăng nhập đúng cổng dành cho vai trò của bạn.',
                status: 403,
            );
        }

        return $user;
    }

    private function genericFailure(): ValidationException
    {
        return ValidationException::withMessages([
            'login' => ['Thông tin đăng nhập hoặc mật khẩu không đúng.'],
        ]);
    }

    private function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        if (str_contains($login, '@')) {
            return User::query()->where('email', mb_strtolower($login))->first();
        }

        try {
            $phone = PhoneNumber::fromInput($login)->value();
        } catch (InvalidArgumentException) {
            return null;
        }

        return User::query()->where('phone', $phone)->first();
    }
}
