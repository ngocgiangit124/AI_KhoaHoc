<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

const VV_SSR_TOKEN = 'ssr-token-ssr-token-ssr-token-ssr-token-1234';

function vvSsrGet(array $headers = [], string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('http://api.localhost/api/v1/courses', $headers);
}

beforeEach(function () {
    config(['internal.ssr_token' => VV_SSR_TOKEN, 'internal.catalog_per_minute' => 120, 'internal.catalog_ssr_total_per_minute' => 130]);
    RateLimiter::clear('x');
    Cache::flush();
});

test('SSR (a) token dung khong X-Client-IP: qua 120 van 200, cham tran tong thi 429 TOO_MANY_ATTEMPTS', function () {
    $h = ['X-Internal-Token' => VV_SSR_TOKEN];
    for ($i = 1; $i <= 130; $i++) {
        vvSsrGet($h)->assertOk();
    }
    vvSsrGet($h)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
});

test('SSR (b) token dung + X-Client-IP rac: giong khong co IP', function () {
    $h = ['X-Internal-Token' => VV_SSR_TOKEN, 'X-Client-IP' => 'abc'];
    for ($i = 1; $i <= 130; $i++) {
        vvSsrGet($h)->assertOk();
    }
    vvSsrGet($h)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
});

test('SSR (c) token dung + 2 IP khac nhau: moi IP 120 rieng', function () {
    config(['internal.catalog_ssr_total_per_minute' => 1000]);
    $h = fn (string $ip) => ['X-Internal-Token' => VV_SSR_TOKEN, 'X-Client-IP' => $ip];
    for ($i = 1; $i <= 120; $i++) {
        vvSsrGet($h('7.7.7.1'))->assertOk();
    }
    vvSsrGet($h('7.7.7.1'))->assertStatus(429);
    vvSsrGet($h('7.7.7.2'))->assertOk();
});

test('SSR (d) token sai + X-Client-IP gia: van theo IP ket noi (120)', function () {
    $h = fn (int $i) => ['X-Internal-Token' => 'sai', 'X-Client-IP' => "8.8.8.$i"];
    for ($i = 1; $i <= 120; $i++) {
        vvSsrGet($h($i % 250))->assertOk();
    }
    vvSsrGet($h(200))->assertStatus(429);
});

test('SSR (e) request co IP va khong IP cung an chung tran ssr-total', function () {
    config(['internal.catalog_ssr_total_per_minute' => 3]);
    vvSsrGet(['X-Internal-Token' => VV_SSR_TOKEN])->assertOk();
    vvSsrGet(['X-Internal-Token' => VV_SSR_TOKEN, 'X-Client-IP' => '9.9.9.1'])->assertOk();
    vvSsrGet(['X-Internal-Token' => VV_SSR_TOKEN])->assertOk();
    vvSsrGet(['X-Internal-Token' => VV_SSR_TOKEN, 'X-Client-IP' => '9.9.9.2'])->assertStatus(429);
    vvSsrGet(['X-Internal-Token' => VV_SSR_TOKEN])->assertStatus(429);
});
