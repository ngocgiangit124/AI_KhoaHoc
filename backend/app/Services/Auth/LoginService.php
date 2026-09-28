<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Xác thực đăng nhập học sinh (host api — US-001 §2.2, BR1/BR5).
 *
 * Thứ tự bắt buộc theo api-contract §2.2/§1.7 (không được đảo):
 * 0. Đã vượt "10 lần SAI/giờ" theo tài khoản (api-contract §1.6 — R2) → 429
 *    `TOO_MANY_ATTEMPTS` TRƯỚC khi chạm DB/so mật khẩu.
 * 1. Sai email/SĐT hoặc sai mật khẩu → 422 thông điệp CHUNG (không tiết lộ
 *    tài khoản có tồn tại hay không — BR5, S20) + tăng bộ đếm "sai" ở bước 0.
 * 2. Mật khẩu đúng nhưng tài khoản bị khoá → 403 `ACCOUNT_LOCKED` — CHỈ trả
 *    khi mật khẩu đúng (S20, chống dò tài khoản qua thông điệp "bị khoá").
 * 3. Mật khẩu đúng, tài khoản không khoá, nhưng vai trò không phải `hoc_sinh`
 *    → 403 `WRONG_PORTAL` (host api chỉ dành cho học sinh).
 *
 * R2 (review docs/qa/review-T03-FW1.md) — api-contract §1.6 ghi rõ "10 lần
 * SAI/giờ/login": middleware `throttle:login` (đếm MỌI request đi qua, không
 * biết kết quả) chỉ còn giữ lớp theo IP (`AppServiceProvider`); lớp theo TÀI
 * KHOẢN chuyển vào đây, CHỈ `hit()` khi sai (không tính đăng nhập đúng nhiều
 * lần — vd nhiều tab/thiết bị hợp lệ trước khi T05 áp 1 phiên).
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

    private const ACCOUNT_MAX_ATTEMPTS = 10;

    private const ACCOUNT_DECAY_SECONDS = 3600;

    public function authenticate(string $login, string $password): User
    {
        // Cùng tiền tố khoá `login:` với limiter IP trong AppServiceProvider để
        // dễ đối chiếu khi tra log/Redis, dù 2 khoá độc lập nhau.
        $throttleKey = 'login:'.mb_strtolower(trim($login));

        if (RateLimiter::tooManyAttempts($throttleKey, self::ACCOUNT_MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(
                'Bạn thao tác quá nhanh, vui lòng thử lại sau.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)],
            );
        }

        $user = $this->findByLogin($login);

        $hashToCheck = $user !== null ? $user->password : self::DUMMY_HASH;

        if ($user === null || ! Hash::check($password, $hashToCheck)) {
            // Chỉ trường hợp THẬT SỰ sai thông tin đăng nhập mới tính là "lần
            // sai" (không tính ACCOUNT_LOCKED/WRONG_PORTAL bên dưới — mật khẩu
            // đúng, chỉ là tài khoản/vai trò không phù hợp, không phải hành vi
            // dò mật khẩu).
            RateLimiter::hit($throttleKey, self::ACCOUNT_DECAY_SECONDS);

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

        // Đăng nhập thành công — xoá bộ đếm "sai" (không bắt buộc theo hợp
        // đồng, nhưng hợp lý: không phạt các lần đăng nhập đúng tiếp theo vì
        // vài lần gõ sai trước đó).
        RateLimiter::clear($throttleKey);

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
