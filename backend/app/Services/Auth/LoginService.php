<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Normalizer;

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
 *
 * M1 (review docs/security/review-T03-FW1.md) — `Hash::check()` PHẢI luôn
 * chạy (kể cả khi không tìm thấy tài khoản), cùng cost với cấu hình thật, để
 * thời gian phản hồi không tiết lộ tài khoản có tồn tại hay không (đã đo thực
 * tế: ~220 ms so với ~4 ms trước khi sửa).
 *
 * M2 — khoá throttle PHẢI dùng cùng 1 dạng chuẩn hoá NFKC với `findByLogin()`
 * (xem `normalizeIdentity()`), và KHÔNG cho định danh còn ký tự ngoài ASCII
 * (sau NFKC) chạm tới DB — chặn kiểu tấn công dùng ký tự Unicode "trông giống"
 * (full-width...) mà MySQL coi là tương đương theo collation nhưng PHP thì
 * không, để lách bộ đếm theo tài khoản.
 */
class LoginService
{
    private const ACCOUNT_MAX_ATTEMPTS = 10;

    private const ACCOUNT_DECAY_SECONDS = 3600;

    public function authenticate(string $login, string $password): User
    {
        // R7 (review docs/qa/review-T03-FW1.md, lần 2) — PHẢI dùng CÙNG 1 định
        // danh đã chuẩn hoá cho cả tra cứu (findByLogin) lẫn khoá throttle:
        // trước đây khoá throttle chỉ lowercase+trim thô, nên gõ sai bằng
        // "0912345678" rồi đổi sang "+84912345678"/"84912345678" (cùng 1 SĐT,
        // 3 cách viết) bị tính là 3 định danh KHÁC nhau → không bao giờ chạm
        // ngưỡng 10 lần/giờ. Cùng tiền tố `login:` với limiter IP trong
        // AppServiceProvider để dễ đối chiếu khi tra log/Redis (2 khoá độc lập
        // nhau). Khoá theo ĐỊNH DANH đã chuẩn hoá (không theo user id) — kể cả
        // định danh không khớp tài khoản nào cũng bị giới hạn như nhau, không
        // lộ tài khoản có tồn tại hay không qua hành vi throttle (BR5).
        $throttleKey = 'login:'.self::normalizeIdentity($login);

        if (RateLimiter::tooManyAttempts($throttleKey, self::ACCOUNT_MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(
                'Bạn thao tác quá nhanh, vui lòng thử lại sau.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)],
            );
        }

        $user = $this->findByLogin($login);

        // M1 — Hash::check() LUÔN chạy, dù $user null hay không, với 1 hash
        // "giả" hợp lệ cùng driver/cost cấu hình thật (Hash::make() đọc
        // config('hashing') mặc định) — không rẽ nhánh sớm bằng `||` (đoản
        // mạch) như trước, vì đoản mạch bỏ qua hoàn toàn việc gọi Hash::check.
        $passwordOk = Hash::check($password, $user?->password ?? self::dummyHash());

        if ($user === null || ! $passwordOk) {
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

    /**
     * Hash bcrypt "giả" — sinh 1 lần/tiến trình (biến `static` trong hàm giữ
     * nguyên giữa các lần gọi cùng 1 worker PHP-FPM), dùng `Hash::make()` nên
     * LUÔN cùng cost với cấu hình thật (không hard-code số vòng — khác hằng số
     * cũ trước khi sửa M1, có thể lệch với `BCRYPT_ROUNDS` thật ở production).
     * Nội dung không nhạy cảm (chuỗi ngẫu nhiên, không phải mật khẩu thật của
     * ai) nên tái dùng giữa các request là an toàn — mục đích duy nhất là giữ
     * chi phí `Hash::check()` không đổi.
     */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }

    private function genericFailure(): ValidationException
    {
        return ValidationException::withMessages([
            'login' => ['Thông tin đăng nhập hoặc mật khẩu không đúng.'],
        ]);
    }

    private function findByLogin(string $login): ?User
    {
        $normalized = self::normalizeIdentity($login);

        // M2 — sau NFKC mà vẫn còn ký tự ngoài ASCII in được thì KHÔNG tra DB:
        // đây là script/ký tự thật sự khác (không phải biến thể full-width
        // của cùng 1 chuỗi ASCII), không thể khớp email/SĐT hợp lệ của dự án.
        if ($normalized === '' || preg_match('/[^\x21-\x7E]/', $normalized) === 1) {
            return null;
        }

        if (str_contains($normalized, '@')) {
            return User::query()->where('email', $normalized)->first();
        }

        return User::query()->where('phone', $normalized)->first();
    }

    /**
     * Chuẩn hoá `login` (email HOẶC SĐT) về ĐÚNG 1 dạng — dùng chung cho tra
     * cứu tài khoản (`findByLogin`) và khoá throttle theo tài khoản (R7, M2):
     * NFKC TRƯỚC (quy full-width về ASCII, khớp cách MySQL collation
     * `utf8mb4_0900_ai_ci` so sánh — không đổi collation DB, chỉ chuẩn hoá ở
     * tầng ứng dụng để 1 tài khoản không có nhiều "khoá" throttle khác nhau),
     * rồi lowercase, rồi SĐT hợp lệ (dù viết `0912345678`/`+84912345678`/
     * `84912345678`) quy về cùng 1 chuỗi qua `PhoneNumber`; email quy về
     * lowercase+trim. `LoginRequest` đã chặn `login` không phải ASCII ở tầng
     * validate (422, không tới được đây) — chuẩn hoá NFKC ở đây là lớp phòng
     * thủ thứ 2 (Service có thể được gọi trực tiếp, không qua FormRequest).
     */
    private static function normalizeIdentity(string $login): string
    {
        $normalizedForm = Normalizer::normalize(trim($login), Normalizer::FORM_KC);
        $trimmed = mb_strtolower($normalizedForm !== false ? $normalizedForm : trim($login));

        if ($trimmed === '' || str_contains($trimmed, '@')) {
            return $trimmed;
        }

        try {
            return PhoneNumber::fromInput($trimmed)->value();
        } catch (InvalidArgumentException) {
            return $trimmed;
        }
    }
}
