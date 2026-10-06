<?php

use App\VideoLab\Support\Signature;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => $this->vlDir = vlUseTempStorage());
afterEach(fn () => vlCleanup($this->vlDir));

function vlCdnGet(string $url, array $headers = [])
{
    return test()->call('GET', $url, [], [], [], vlServer($headers));
}

test('cdn: token hop le -> playlist, rendition, segment dung Content-Type', function () {
    $guid = vlFinishedVideo();
    $url = vlPlaybackUrl($guid);
    $base = dirname($url);

    vlCdnGet($url)->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
    expect(vlCdnGet($url)->getContent())->toBeEmpty(); // BinaryFileResponse: nội dung ở file
    vlCdnGet($base.'/360p/index.m3u8')->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
    $seg = vlCdnGet($base.'/360p/seg_00000.ts')->assertOk()->assertHeader('Content-Type', 'video/mp2t');
    expect($seg->headers->get('Cache-Control'))->toContain('private');
});

test('cdn: het han / token sai / guid khac -> 403', function () {
    $guid = vlFinishedVideo();
    $other = vlFinishedVideo();
    $expires = time() - 5;
    $token = Signature::playback($guid, $expires);

    vlCdnGet(vlUrl("/cdn/{$token}/{$expires}/{$guid}/playlist.m3u8"))->assertForbidden();

    $url = vlPlaybackUrl($guid);
    vlCdnGet(str_replace($guid, $other, $url))->assertForbidden(); // token ký cho guid khác
    $parts = explode('/', $url);
    $parts[array_search('cdn', $parts, true) + 1] = str_repeat('A', 43);
    vlCdnGet(implode('/', $parts))->assertForbidden();
    // gia han expires nhung giu token cu
    $tampered = preg_replace('#/(\d{10})/#', '/'.(time() + 99999).'/', $url);
    vlCdnGet($tampered)->assertForbidden();
});

test('cdn: token rang IP chi dung dung IP; token khong IP dung moi noi', function () {
    $guid = vlFinishedVideo();

    $bound = vlPlaybackUrl($guid, '10.9.8.7');
    test()->call('GET', $bound, [], [], [], ['REMOTE_ADDR' => '10.9.8.7'])->assertOk();
    test()->call('GET', $bound, [], [], [], ['REMOTE_ADDR' => '10.9.8.8'])->assertForbidden();

    $free = vlPlaybackUrl($guid);
    test()->call('GET', $free, [], [], [], ['REMOTE_ADDR' => '10.9.8.8'])->assertOk();
});

test('cdn: path traversal / tuyet doi / sai dinh dang -> 404 (kem token hop le)', function (string $path) {
    $guid = vlFinishedVideo();
    $url = vlPlaybackUrl($guid);
    $prefix = substr($url, 0, strrpos($url, '/playlist.m3u8'));

    expect(vlCdnGet($prefix.'/'.$path)->getStatusCode())->toBe(404);
})->with([
    'dotdot slash' => '../'.'x/playlist.m3u8',
    'encoded slash' => '..%2f..%2f.env',
    'encoded dots' => '%2e%2e/%2e%2e/playlist.m3u8',
    'double encoded' => '%252e%252e%252fplaylist.m3u8',
    'absolute' => '%2fetc%2fpasswd',
    'absolute raw' => '/etc/passwd',
    'ext' => '360p/seg_00000.mp4',
    'source bin' => 'source.bin',
    'deep' => '360p/a/b.ts',
    'null byte' => 'playlist.m3u8%00.ts',
    'backslash' => '360p%5cseg_00000.ts',
    'nonexistent' => '720p/index.m3u8',
]);

test('cdn: guid khong phai UUID -> 404; ../ trong guid -> 404', function () {
    vlCdnGet(vlUrl('/cdn/'.str_repeat('A', 43).'/'.(time() + 100).'/..%2f..%2f/playlist.m3u8'))->assertNotFound();
    vlCdnGet(vlUrl('/cdn/'.str_repeat('A', 43).'/'.(time() + 100).'/not-a-guid/playlist.m3u8'))->assertNotFound();
});

test('cdn: realpath ra ngoai hls/{guid} (symlink) -> 404; file nguon khong bao gio phuc vu duoc', function () {
    $guid = vlFinishedVideo();
    $storage = app(VideoLabStorage::class);
    file_put_contents($this->vlDir.'/secret.txt', 'SECRET');
    symlink($this->vlDir.'/secret.txt', $storage->hlsDir($guid).'/360p/seg_00099.ts');
    // file gốc nằm thư mục khác
    mkdir($this->vlDir.'/source', 0775, true);
    file_put_contents($storage->source($guid), 'SOURCE');

    $url = vlPlaybackUrl($guid);
    $base = dirname($url);

    expect($storage->resolveHlsFile($guid, '360p/seg_00099.ts'))->toBeNull();
    vlCdnGet($base.'/360p/seg_00099.ts')->assertNotFound();
    vlCdnGet($base.'/360p/'.$guid.'.bin')->assertNotFound();
});

test('cdn: video chua transcode xong (chua co hls/{guid}) -> 404 du co token; khong truy van DB khi phuc vu', function () {
    $guid = vlCreateVideo();

    vlCdnGet(vlPlaybackUrl($guid))->assertNotFound();

    $done = vlFinishedVideo();
    $url = vlPlaybackUrl($done);
    DB::flushQueryLog();
    DB::enableQueryLog();
    vlCdnGet($url)->assertOk();
    expect(DB::getQueryLog())->toBe([]);
});

test('cdn: X-Accel-Redirect khi bat accel_redirect', function () {
    config(['videolab.accel_redirect' => true]);
    $guid = vlFinishedVideo();

    $r = vlCdnGet(vlPlaybackUrl($guid))->assertOk();
    expect($r->headers->get('X-Accel-Redirect'))->toBe('/_protected_hls/'.$guid.'/playlist.m3u8');
});

test('cdn: CORS cho FRONTEND_URL va ADMIN_URL, khong credentials; origin la khong co header', function () {
    $guid = vlFinishedVideo();
    $url = vlPlaybackUrl($guid);

    foreach ([config('app.frontend_url'), config('app.admin_url')] as $origin) {
        $r = vlCdnGet($url, ['Origin' => $origin])->assertOk()->assertHeader('Access-Control-Allow-Origin', $origin);
        expect($r->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
    }

    expect(vlCdnGet($url, ['Origin' => 'https://evil.example'])->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    test()->call('OPTIONS', $url, [], [], [], vlServer(['Origin' => config('app.frontend_url'), 'Access-Control-Request-Method' => 'GET']))
        ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'));
});
