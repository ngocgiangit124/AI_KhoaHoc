<?php

namespace App\Services\Privacy;

use App\Models\User;

/**
 * Token huỷ nhận thông báo của phụ huynh (api-contract §2.8.2):
 * `{user_id}.{base64url(HMAC_SHA256(key, "parent-notice-unsub|{user_id}|" . sha256(lower(parent_email))))}`.
 *
 * Không hết hạn nhưng chỉ có hiệu lực khi khớp `parent_email` HIỆN TẠI của học sinh: đổi email phụ huynh là token cũ mất tác dụng.
 */
final class ParentNoticeToken
{
    private const LABEL = 'parent-notice-unsub';

    public static function make(User $user): ?string
    {
        $email = self::currentEmail($user);

        return $email === null ? null : $user->getKey().'.'.self::signature((int) $user->getKey(), $email);
    }

    /**
     * @return User|null học sinh nếu token hợp lệ, còn chưa ẩn danh và còn email phụ huynh khớp
     */
    public static function verify(string $token): ?User
    {
        if (preg_match('/^(\d{1,18})\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return null;
        }

        $user = User::query()->find((int) $m[1]);
        $email = $user !== null && $user->anonymized_at === null ? self::currentEmail($user) : null;

        // Luôn tính HMAC (email giả khi không có user) để thời gian phản hồi không tiết lộ id có tồn tại hay không.
        $expected = self::signature((int) $m[1], $email ?? '-');

        return $user !== null && $email !== null && hash_equals($expected, $m[2]) ? $user : null;
    }

    /**
     * Băm CÓ KHOÁ địa chỉ email phụ huynh (khoá trần thư, danh sách chặn). Không dùng hash trần vì email dễ đoán,
     * lộ Redis/DB là dò ngược được. Khoá con dẫn xuất riêng theo nhãn.
     */
    public static function addressHash(string $email): string
    {
        return hash_hmac('sha256', 'address|'.mb_strtolower(trim($email)), hash_hmac('sha256', 'parent-notice-address', self::key(), true));
    }

    private static function currentEmail(User $user): ?string
    {
        $email = $user->parent_email;

        return is_string($email) && $email !== '' ? mb_strtolower($email) : null;
    }

    private static function signature(int $userId, string $email): string
    {
        $data = self::LABEL.'|'.$userId.'|'.hash('sha256', $email);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, self::key(), true)), '+/', '-_'), '=');
    }

    private static function key(): string
    {
        $configured = config('privacy.notice_token_key');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // Dẫn xuất từ APP_KEY bằng HMAC với nhãn riêng: lộ token không suy ra được APP_KEY, và khoá này không dùng chung việc khác.
        return hash_hmac('sha256', self::LABEL, (string) config('app.key'), true);
    }
}
