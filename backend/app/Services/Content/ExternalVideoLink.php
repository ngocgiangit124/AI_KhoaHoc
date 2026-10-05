<?php

namespace App\Services\Content;

/**
 * Phân tích link video ngoài (S13, ADR-002 §4). Chỉ nhận https, host trong whitelist, không userinfo/cổng lạ.
 * Chỉ trả về provider + ID bắt bằng regex chặt; URL người nhập KHÔNG được lưu, URL nhúng dựng lại từ ID.
 */
class ExternalVideoLink
{
    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    private const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    private const YOUTUBE_ID = '/^[A-Za-z0-9_-]{11}$/';

    private const VIMEO_ID = '/^\d{6,12}$/';

    /**
     * @return array{provider: string, id: string}|null null nếu không hợp lệ/không thuộc whitelist
     */
    public function parse(string $url): ?array
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        if ($host === 'youtu.be') {
            return $this->youtube($segments[0] ?? '');
        }

        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            if (($segments[0] ?? '') === 'watch' && count($segments) === 1) {
                return $this->youtube(is_string($query['v'] ?? null) ? $query['v'] : '');
            }

            if (in_array($segments[0] ?? '', ['embed', 'shorts', 'live', 'v'], true) && count($segments) === 2) {
                return $this->youtube($segments[1]);
            }

            return null;
        }

        if (in_array($host, self::VIMEO_HOSTS, true)) {
            if ($host === 'player.vimeo.com') {
                return ($segments[0] ?? '') === 'video' && count($segments) === 2 ? $this->vimeo($segments[1]) : null;
            }

            // Chỉ video Vimeo công khai: vimeo.com/{id} (link có hash của video không công khai bị từ chối)
            return count($segments) === 1 ? $this->vimeo($segments[0]) : null;
        }

        return null;
    }

    public static function embedUrl(?string $provider, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return match ($provider) {
            'youtube' => preg_match(self::YOUTUBE_ID, $id) === 1 ? 'https://www.youtube-nocookie.com/embed/'.$id : null,
            'vimeo' => preg_match(self::VIMEO_ID, $id) === 1 ? 'https://player.vimeo.com/video/'.$id.'?dnt=1' : null,
            default => null,
        };
    }

    /** @return array{provider: string, id: string}|null */
    private function youtube(string $id): ?array
    {
        return preg_match(self::YOUTUBE_ID, $id) === 1 ? ['provider' => 'youtube', 'id' => $id] : null;
    }

    /** @return array{provider: string, id: string}|null */
    private function vimeo(string $id): ?array
    {
        return preg_match(self::VIMEO_ID, $id) === 1 ? ['provider' => 'vimeo', 'id' => $id] : null;
    }
}
