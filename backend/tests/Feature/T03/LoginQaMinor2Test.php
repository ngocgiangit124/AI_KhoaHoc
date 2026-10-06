<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/LoginTest.php';

// QA "Sửa lỗi nhỏ 2": các ca bổ sung cho đăng nhập học sinh (M2, M3).

/** Bỏ các header thay đổi theo từng request. */
function vvStableHeaders($response): array
{
    $h = collect($response->headers->all())->except(['date', 'set-cookie', 'x-request-id', 'x-ratelimit-remaining'])->all();
    ksort($h);

    return $h;
}

test('M2: email viet hoa/co dau va SDT cua cung tai khoan dung chung 1 bo dem', function () {
    $user = vvStudent();
    $logins = ['HS@Example.com', '0912345678', 'hs@exámple.com', '+84912345678', '091 234 5678', 'hs@example.com', 'Hś@example.com', '84912345678', 'HS@EXAMPLE.COM', '0912.345.678'];

    foreach ($logins as $login) {
        vvLogin(['login' => $login, 'password' => 'sai'])->assertStatus(422);
        vvResetClient();
    }

    expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(10);

    // Lượt 11 bằng SĐT (mật khẩu ĐÚNG) vẫn 429, bằng email cũng 429.
    vvLogin(['login' => '0912345678', 'password' => 'dung-mat-khau-1'])->assertStatus(429);
    vvLogin(['login' => 'HS@example.com', 'password' => 'dung-mat-khau-1'])->assertStatus(429);
    $this->assertGuest('web');
});

test('M3: sau 10 luot sai, luot 11 voi mat khau DUNG van bi 429 va co Retry-After hop le', function () {
    vvStudent();
    for ($i = 0; $i < 10; $i++) {
        vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
        vvResetClient();
    }

    $r = vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertStatus(429);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(3600);
    $this->assertGuest('web');
});

test('M3: het TTL (1 gio) thi dang nhap lai duoc', function () {
    $user = vvStudent();
    for ($i = 0; $i < 10; $i++) {
        vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
        vvResetClient();
    }
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertStatus(429);

    $this->travel(3601)->seconds();
    vvResetClient();

    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();
    expect(RateLimiter::attempts('login-fail:u:'.$user->getKey()))->toBe(0);
});

test('BR5: tai khoan khong ton tai giong het tai khoan that sai mat khau (status, body, header) va deu so hash 1 lan', function () {
    vvStudent();
    $calls = 0;
    Hash::shouldReceive('check')->andReturnUsing(function () use (&$calls) {
        $calls++;

        return false;
    });

    $real = vvLogin(['login' => 'hs@example.com', 'password' => 'sai']);
    $afterReal = $calls;
    vvResetClient();
    $ghost = vvLogin(['login' => 'ghost@example.com', 'password' => 'sai']);
    $afterGhost = $calls - $afterReal;
    vvResetClient();
    $ghostPhone = vvLogin(['login' => '0999999999', 'password' => 'sai']);

    expect($afterReal)->toBe(1)->and($afterGhost)->toBe(1);
    foreach ([$ghost, $ghostPhone] as $g) {
        expect($g->status())->toBe($real->status())
            ->and(Arr::except($g->json(), 'request_id'))->toBe(Arr::except($real->json(), 'request_id'))
            ->and(vvStableHeaders($g))->toBe(vvStableHeaders($real));
    }
});

test('BR5: thoi gian phan hoi tai khoan khong ton tai ~ tai khoan that sai mat khau (trung vi, khong qua 3 lan)', function () {
    vvStudent();
    $median = function (string $login): float {
        $t = [];
        for ($i = 0; $i < 9; $i++) {
            $s = hrtime(true);
            vvLogin(['login' => $login, 'password' => 'sai'])->assertStatus(422);
            $t[] = (hrtime(true) - $s) / 1e6;
            Cache::flush();
        }
        sort($t);

        return $t[4];
    };

    $real = $median('hs@example.com');
    $ghost = $median('ghost@example.com');

    expect($ghost)->toBeLessThan($real * 3 + 20)->and($real)->toBeLessThan($ghost * 3 + 20);
});

test('M3: khong ton tai - 10 luot sai roi luot 11 429 giong tai khoan that (cung status/header Retry-After)', function () {
    vvStudent();
    $statuses = ['real' => [], 'ghost' => []];
    foreach (['real' => 'hs@example.com', 'ghost' => 'ghost@example.com'] as $k => $login) {
        Cache::flush();
        for ($i = 0; $i < 11; $i++) {
            $statuses[$k][] = vvLogin(['login' => $login, 'password' => 'sai'])->status();
            vvResetClient();
        }
    }

    expect($statuses['ghost'])->toBe($statuses['real'])->and($statuses['real'][10])->toBe(429);
});
