<?php

namespace App\Support;

/**
 * Che PII khi hiển thị cho người duyệt (api-contract §2.6: email/SĐT che).
 */
final class Mask
{
    /** `nguyenvanan@gmail.com` → `n***@gmail.com`. */
    public static function email(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * T29-S4: thay MỌI chuỗi dạng địa chỉ email trong văn bản tự do (thông điệp exception của transport SMTP...) bằng `***`.
     * Cố ý rộng tay (bắt cả `<a@b>` lẫn tên miền IDN) vì mục đích là không còn ký tự `@` của địa chỉ trong log/failed_jobs.
     */
    public static function emailsInText(string $text): string
    {
        return preg_replace('/[^\s<>,;()]*@[^\s<>,;()]*/u', '***', $text) ?? '***';
    }

    /** `0912345678` → `******5678`. */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $len = mb_strlen($phone);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).mb_substr($phone, -4);
    }
}
