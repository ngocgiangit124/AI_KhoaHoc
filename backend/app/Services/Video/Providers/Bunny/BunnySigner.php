<?php

namespace App\Services\Video\Providers\Bunny;

/**
 * Công thức ký của Bunny, tách riêng để test bằng vector cố định (tests/Feature/T37/BunnySignerTest.php).
 *
 * !!! PHẢI ĐỐI CHIẾU TÀI LIỆU BUNNY HIỆN HÀNH + THỬ URL THẬT (US-021 AC16) trước khi dùng production. Các công thức
 * dưới đây viết theo tài liệu Bunny đã biết, chưa kiểm với tài khoản thật:
 *
 * 1) Chữ ký TUS (Bunny Stream "TUS resumable uploads"):
 *      AuthorizationSignature = SHA256_HEX(LibraryId . ApiKey . AuthorizationExpire . VideoId)
 *      kèm header AuthorizationExpire (unix giây), VideoId, LibraryId.
 *
 * 2) Token Authentication của Bunny CDN (khoá "Token Authentication Key" của thư viện, bản "directory token"):
 *      token = base64url( SHA256_RAW( TokenKey . token_path . expires . [ip] . "token_path=" . token_path ) )
 *      base64url = base64 chuẩn, đổi `+` -> `-`, `/` -> `_`, bỏ dấu `=`.
 *    Trong chuỗi băm, `token_path=<giá trị thô>` là "parameter data" (các tham số query, trừ `token`/`expires`, sắp xếp
 *    theo tên, nối bằng `&`); với token theo thư mục thì chỉ có đúng một tham số `token_path`.
 *    URL theo thư mục đặt token trong PHẦN ĐƯỜNG DẪN để mọi đoạn HLS tương đối tự mang token:
 *      https://{cdn_host}/bcdn_token={token}&expires={expires}&token_path={urlencode(token_path)}{đường dẫn tệp}
 *    Nếu Bunny đổi định dạng thì chỉ sửa đúng lớp này.
 */
final class BunnySigner
{
    public static function uploadSignature(string $libraryId, #[\SensitiveParameter] string $apiKey, int $expires, string $videoId): string
    {
        return hash('sha256', $libraryId.$apiKey.$expires.$videoId);
    }

    /** Token cho thư mục `$tokenPath` (ví dụ `/{guid}/`); `$ip` null = không ràng IP. */
    public static function directoryToken(#[\SensitiveParameter] string $tokenKey, string $tokenPath, int $expires, ?string $ip = null): string
    {
        $raw = hash('sha256', $tokenKey.$tokenPath.$expires.($ip ?? '').'token_path='.$tokenPath, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** URL phát HLS: `{base}/bcdn_token=...&expires=...&token_path=%2F{guid}%2F/{guid}/playlist.m3u8`. */
    public static function hlsUrl(string $cdnBase, string $guid, #[\SensitiveParameter] string $tokenKey, int $expires, ?string $ip = null, string $file = 'playlist.m3u8'): string
    {
        $tokenPath = '/'.$guid.'/';
        $token = self::directoryToken($tokenKey, $tokenPath, $expires, $ip);

        return rtrim($cdnBase, '/')."/bcdn_token={$token}&expires={$expires}&token_path=".rawurlencode($tokenPath).$tokenPath.$file;
    }
}
