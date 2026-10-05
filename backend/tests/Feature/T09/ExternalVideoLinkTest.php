<?php

use App\Services\Content\ExternalVideoLink;

test('parse link YouTube/Vimeo hop le ra provider + ID', function (string $url, string $provider, string $id) {
    expect((new ExternalVideoLink)->parse($url))->toBe(['provider' => $provider, 'id' => $id]);
})->with([
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://youtube.com/watch?v=dQw4w9WgXcQ&list=x', 'youtube', 'dQw4w9WgXcQ'],
    ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://youtu.be/dQw4w9WgXcQ?t=5', 'youtube', 'dQw4w9WgXcQ'],
    ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'],
    ['https://vimeo.com/123456789', 'vimeo', '123456789'],
    ['https://player.vimeo.com/video/123456789?h=abc', 'vimeo', '123456789'],
]);

test('parse tu choi URL ngoai whitelist/khong an toan', function (string $url) {
    expect((new ExternalVideoLink)->parse($url))->toBeNull();
})->with([
    'ftp://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ',
    'https://evil.com/?u=https://youtu.be/dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ0',
    'https://www.youtube.com/channel/UC1234567890',
    'https://vimeo.com/channels/staffpicks/123456789',
    'https://vimeo.com/12345',
    'https://vimeo.com/123456789/abcdef1234',
    'https://user:pw@youtu.be/dQw4w9WgXcQ',
    '//youtu.be/dQw4w9WgXcQ',
    'data:text/html,<script>',
]);

test('embedUrl dung lai tu ID, ID xau -> null', function () {
    expect(ExternalVideoLink::embedUrl('youtube', 'dQw4w9WgXcQ'))->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and(ExternalVideoLink::embedUrl('vimeo', '123456789'))->toBe('https://player.vimeo.com/video/123456789?dnt=1')
        ->and(ExternalVideoLink::embedUrl('youtube', '"><script>'))->toBeNull()
        ->and(ExternalVideoLink::embedUrl(null, 'x'))->toBeNull();
});
