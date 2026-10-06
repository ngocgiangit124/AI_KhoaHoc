<?php

namespace App\VideoLab\Support;

/**
 * Công thức ký của VideoLab (ADR-002 §3, §3a.5). Adapter phía nghiệp vụ giữ bản sao công thức riêng
 * (InternalVideoProvider) để module không phụ thuộc ngược.
 */
class Signature
{
    public static function upload(string $libraryId, int $expire, string $guid): string
    {
        return hash_hmac('sha256', $libraryId.$expire.$guid, (string) config('videolab.api_key'));
    }

    /** `token = base64url(HMAC_SHA256(token_key, "/{guid}/" . expires . ip))` — ký theo thư mục như token_path của Bunny. */
    public static function playback(string $guid, int $expires, string $ip = ''): string
    {
        $raw = hash_hmac('sha256', '/'.$guid.'/'.$expires.$ip, (string) config('videolab.token_key'), true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function webhook(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('videolab.webhook_secret'));
    }
}
