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
]);

test('embedUrl chi dung lai tu ID hop le', function () {
    expect(ExternalVideoLink::embedUrl('youtube', 'dQw4w9WgXcQ'))->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    expect(ExternalVideoLink::embedUrl('vimeo', '123456789'))->toBe('https://player.vimeo.com/video/123456789?dnt=1');
    expect(ExternalVideoLink::embedUrl('youtube', 'x"><script>'))->toBeNull();
    expect(ExternalVideoLink::embedUrl('vimeo', 'abc'))->toBeNull();
    expect(ExternalVideoLink::embedUrl('other', 'dQw4w9WgXcQ'))->toBeNull();
    expect(ExternalVideoLink::embedUrl(null, null))->toBeNull();
});
