<?php

use App\Services\Video\Providers\Bunny\BunnySigner;

/*
 * Vector cố định tính độc lập (script Python hashlib), KHÔNG phải kết quả Bunny thật. Phải đối chiếu tài liệu Bunny hiện hành
 * và thử URL thật (docs/tech/US-021.md) trước khi mở bán.
 */

const BS_GUID = '11111111-2222-3333-4444-555555555555';

test('chu ky TUS: sha256 hex cua LibraryId+ApiKey+Expire+VideoId khop vector', function () {
    expect(BunnySigner::uploadSignature('12345', 'api-key-for-tests-0001', 1900000000, BS_GUID))
        ->toBe('5777c60956f48dac3f7384481c91cda1c3e6ef2aae2df1dbdde1b18c04725ecf');
});

test('token thu muc khop vector: khong IP, IPv4, IPv6', function (?string $ip, string $expected) {
    expect(BunnySigner::directoryToken('token-key-for-tests-0002', '/'.BS_GUID.'/', 1900000900, $ip))->toBe($expected);
})->with([
    'khong IP' => [null, 'lYK_EFxYX9YMwvL1DVqlhg8ycR8RJY8SshN6QNkRIe8'],
    'IPv4' => ['203.0.113.7', 'DwGvIshuY2h5Tz0EHUgJlvj-oDNbZy1Q2i6yUcOo5vA'],
    'IPv6' => ['2001:db8::1', 'Y7vwAFi9U6ZKjx-eiK-N_JklTV7kPSGYWJ6YZdL3lVc'],
]);

test('URL HLS: token trong duong dan, token_path ma hoa, expires, playlist.m3u8', function () {
    $url = BunnySigner::hlsUrl('https://vz-test.b-cdn.net/', BS_GUID, 'token-key-for-tests-0002', 1900000900);

    expect($url)->toBe('https://vz-test.b-cdn.net/bcdn_token=lYK_EFxYX9YMwvL1DVqlhg8ycR8RJY8SshN6QNkRIe8&expires=1900000900&token_path=%2F'.BS_GUID.'%2F/'.BS_GUID.'/playlist.m3u8');
});

test('token chi gom ky tu URL-safe, doi IP/han/khoa/guid doi token', function () {
    $base = BunnySigner::directoryToken('k', '/a/', 100, null);

    expect($base)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(BunnySigner::directoryToken('k', '/a/', 100, '1.1.1.1'))->not->toBe($base)
        ->and(BunnySigner::directoryToken('k', '/a/', 101, null))->not->toBe($base)
        ->and(BunnySigner::directoryToken('k2', '/a/', 100, null))->not->toBe($base)
        ->and(BunnySigner::directoryToken('k', '/b/', 100, null))->not->toBe($base);
});
