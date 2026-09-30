<?php

use App\Support\ExternalVideoLink;

test('parse tra provider + id, khong giu URL', function (string $url, string $provider, string $id) {
    expect(ExternalVideoLink::parse($url))->toBe(['provider' => $provider, 'id' => $id]);
})->with([
    ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://YOUTU.BE/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://vimeo.com/123456789', 'vimeo', '123456789'],
]);

test('parse tu choi input nguy hiem', function (mixed $url) {
    expect(ExternalVideoLink::parse($url))->toBeNull();
})->with([
    null, 123, '', [[]], 'not a url', '//youtu.be/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ/extra',
    'https://www.youtube.com/watch',
    'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
    'https://www.youtube.com/playlist?list=PLxxxxxxxxxxx',
    'ftp://youtu.be/dQw4w9WgXcQ',
    'https://vimeo.com/1234567890123',
    // Hồi quy từ review-T09 R8 (đã thử tay, đều phải bị loại).
    'host dau cham cuoi' => 'https://youtube.com./watch?v=dQw4w9WgXcQ',
    'host dau cham cuoi youtu.be' => 'https://youtu.be./dQw4w9WgXcQ',
    'userinfo 1' => 'https://youtube.com@evil.com/watch?v=dQw4w9WgXcQ',
    'userinfo 2' => 'https://evil.com@youtu.be/dQw4w9WgXcQ',
    'userinfo co mat khau' => 'https://user:pass@youtu.be/dQw4w9WgXcQ',
    'port 443' => 'https://www.youtube.com:443/watch?v=dQw4w9WgXcQ',
    'port la' => 'https://youtu.be:8080/dQw4w9WgXcQ',
    'http' => 'http://youtu.be/dQw4w9WgXcQ',
    'fragment @' => 'https://evil.com#@youtu.be/dQw4w9WgXcQ',
    'query @' => 'https://evil.com?@youtu.be/dQw4w9WgXcQ',
    'backslash' => 'https://youtu.be\\@evil.com/dQw4w9WgXcQ',
    'backslash 2' => 'https://evil.com\\.youtu.be/dQw4w9WgXcQ',
    'xuong dong giua' => "https://youtu.be/dQw4w9WgXcQ\nhttps://evil.com",
    'xuong dong cuoi' => "https://youtu.be/dQw4w9WgXcQ\n",
    'CRLF' => "https://youtu.be/dQw4w9WgXcQ\r\n",
    'NUL' => "https://youtu.be/dQw4w9WgXcQ\0",
    'tab' => "https://youtu.be/dQw4w9WgXcQ\t",
    'NBSP' => "https://youtu.be/dQw4w9WgXcQ\u{00A0}",
    'host Cyrillic o' => "https://www.y\u{043E}utube.com/watch?v=dQw4w9WgXcQ",
    'host fullwidth dot' => "https://www\u{FF0E}youtube\u{FF0E}com/watch?v=dQw4w9WgXcQ",
    'ID fullwidth' => "https://youtu.be/dQw4w9WgXc\u{FF31}",
    'ID percent-encoded' => 'https://youtu.be/dQw4w9WgXc%51',
    'ID percent-encoded watch' => 'https://www.youtube.com/watch?v=dQw4w9WgXc%22',
    'slash percent-encoded' => 'https://www.youtube.com/embed%2FdQw4w9WgXcQ',
    'host percent-encoded' => 'https://you%74u.be/dQw4w9WgXcQ',
    'dot-dot segment' => 'https://www.youtube.com/embed/../dQw4w9WgXcQ',
    'dot-dot segment 2' => 'https://youtu.be/../dQw4w9WgXcQ',
    'subdomain gia' => 'https://evil.youtube.com/watch?v=dQw4w9WgXcQ',
    'suffix gia' => 'https://youtube.com.evil.io/watch?v=dQw4w9WgXcQ',
    'prefix gia' => 'https://evilyoutube.com/watch?v=dQw4w9WgXcQ',
    'prefix gia youtu.be' => 'https://evilyoutu.be/dQw4w9WgXcQ',
    'subdomain vimeo gia' => 'https://evil.vimeo.com/123456789',
    'suffix vimeo gia' => 'https://vimeo.com.evil.io/123456789',
    'javascript' => 'javascript:alert(1)',
    'javascript co ID' => 'javascript://youtu.be/dQw4w9WgXcQ%0Aalert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'IPv6' => 'https://[::1]/watch?v=dQw4w9WgXcQ',
    'khong host' => 'https:///watch?v=dQw4w9WgXcQ',
    'ID 12 ky tu' => 'https://youtu.be/dQw4w9WgXcQQ',
    'v mang' => 'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
    'chuoi qua dai' => 'https://youtu.be/dQw4w9WgXcQ?'.str_repeat('a', 2100),
    'so Vimeo Unicode' => "https://vimeo.com/\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}",
    'so Vimeo fullwidth' => "https://vimeo.com/\u{FF11}\u{FF12}\u{FF13}\u{FF14}\u{FF15}\u{FF16}\u{FF17}",
    'vimeo kenh' => 'https://vimeo.com/channels/staffpicks/123456789',
    'youtube live' => 'https://www.youtube.com/live/dQw4w9WgXcQ',
]);

test('embedUrl chi dung lai tu ID hop le', function () {
    expect(ExternalVideoLink::embedUrl('youtube', 'dQw4w9WgXcQ'))->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    expect(ExternalVideoLink::embedUrl('vimeo', '123456789'))->toBe('https://player.vimeo.com/video/123456789?dnt=1');
    expect(ExternalVideoLink::embedUrl('youtube', 'x"><script>'))->toBeNull();
    expect(ExternalVideoLink::embedUrl('vimeo', 'abc'))->toBeNull();
    expect(ExternalVideoLink::embedUrl('other', 'dQw4w9WgXcQ'))->toBeNull();
    expect(ExternalVideoLink::embedUrl(null, null))->toBeNull();
});
