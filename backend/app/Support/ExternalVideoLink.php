<?php

namespace App\Support;

/**
 * Phân tích link video ngoài (US-009 BR10, S13, ADR-002 §4) — chỉ YouTube và
 * Vimeo. KHÔNG BAO GIỜ lưu/dùng lại URL người nhập: chỉ trả `provider` + `id`
 * bắt bằng regex CHẶT (khớp toàn chuỗi); URL embed luôn được DỰNG LẠI từ ID
 * (`youtube-nocookie.com`, Vimeo `dnt=1`).
 *
 * Ràng buộc: bắt buộc `https`, không có userinfo/port/fragment lạ, host nằm
 * trong whitelist (so khớp chính xác, không dùng `str_contains`/`endsWith`
 * nên `evil-youtube.com`, `youtube.com.evil.io` đều bị loại), không có ký tự
 * điều khiển/khoảng trắng.
 */
final class ExternalVideoLink
{
    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_VIMEO = 'vimeo';

    private const YOUTUBE_ID = '/\A[A-Za-z0-9_-]{11}\z/';

    private const VIMEO_ID = '/\A\d{6,12}\z/';

    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    private const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    /**
     * @return array{provider: string, id: string}|null null nếu không hợp lệ/không được hỗ trợ.
     */
    public static function parse(mixed $url): ?array
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false
            || ($parts['scheme'] ?? null) === null
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! isset($parts['host'])
        ) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be') {
            $id = self::singleSegment($path);

            return self::youtube($id);
        }

        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            $segments = self::segments($path);

            if ($segments === ['watch']) {
                parse_str($parts['query'] ?? '', $query);
                $v = $query['v'] ?? null;

                return self::youtube(is_string($v) ? $v : null);
            }

            if (count($segments) === 2 && in_array($segments[0], ['embed', 'shorts'], true)) {
                return self::youtube($segments[1]);
            }

            return null;
        }

        if (in_array($host, self::VIMEO_HOSTS, true)) {
            $segments = self::segments($path);

            if ($host === 'player.vimeo.com') {
                $id = count($segments) === 2 && $segments[0] === 'video' ? $segments[1] : null;
            } else {
                $id = count($segments) === 1 ? $segments[0] : null;
            }

            return self::vimeo($id);
        }

        return null;
    }

    /**
     * URL embed dựng lại từ ID đã kiểm tra (S13). Trả null nếu (provider, id)
     * không khớp regex (dữ liệu DB bất thường không được dựng thành URL).
     */
    public static function embedUrl(?string $provider, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        if ($provider === self::PROVIDER_YOUTUBE && preg_match(self::YOUTUBE_ID, $id) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$id;
        }

        if ($provider === self::PROVIDER_VIMEO && preg_match(self::VIMEO_ID, $id) === 1) {
            return 'https://player.vimeo.com/video/'.$id.'?dnt=1';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));
    }

    private static function singleSegment(string $path): ?string
    {
        $segments = self::segments($path);

        return count($segments) === 1 ? $segments[0] : null;
    }

    /**
     * @return array{provider: string, id: string}|null
     */
    private static function youtube(?string $id): ?array
    {
        if ($id === null || preg_match(self::YOUTUBE_ID, $id) !== 1) {
            return null;
        }

        return ['provider' => self::PROVIDER_YOUTUBE, 'id' => $id];
    }

    /**
     * @return array{provider: string, id: string}|null
     */
    private static function vimeo(?string $id): ?array
    {
        if ($id === null || preg_match(self::VIMEO_ID, $id) !== 1) {
            return null;
        }

        return ['provider' => self::PROVIDER_VIMEO, 'id' => $id];
    }
}
