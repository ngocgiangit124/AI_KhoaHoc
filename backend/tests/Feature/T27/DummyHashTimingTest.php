<?php

use App\Enums\UserStatus;
use App\Services\Auth\LoginService;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';

/** HashManager đếm số lần băm/so trong test. */
final class VvCountingHashManager extends HashManager
{
    public int $makes = 0;

    public int $checks = 0;

    public function make($value, array $options = [])
    {
        $this->makes++;

        return parent::make($value, $options);
    }

    public function check($value, $hashedValue, array $options = [])
    {
        $this->checks++;

        return parent::check($value, $hashedValue, $options);
    }
}

function vvCountHashes(): VvCountingHashManager
{
    $manager = new VvCountingHashManager(app());
    app()->instance('hash', $manager);
    Hash::clearResolvedInstance('hash');

    return $manager;
}

/** Mô phỏng process mới (PHP-FPM): xoá biến static của LoginService. */
function vvResetDummyStatic(): void
{
    foreach (['dummyHash', 'dummyHashRounds'] as $prop) {
        (new ReflectionProperty(LoginService::class, $prop))->setValue(null, null);
    }
}

test('BUG-1 dummyHash duoc cache lien request: process moi khong goi Hash::make', function () {
    vvResetDummyStatic();
    $first = LoginService::dummyHash();
    vvResetDummyStatic();

    $hashes = vvCountHashes();

    expect(LoginService::dummyHash())->toBe($first)->and($hashes->makes)->toBe(0);
});

test('dummyHash cung cost voi hash that', function () {
    vvResetDummyStatic();
    Cache::flush();

    expect(Hash::info(LoginService::dummyHash())['options']['cost'])->toBe((int) config('hashing.bcrypt.rounds'));
});

test('moi nhanh chay DUNG 1 lan Hash::check va khong Hash::make trong request (login + reset)', function (string $case) {
    $user = vvPwStudent();
    vvResetDummyStatic();
    LoginService::dummyHash(); // làm ấm cache như môi trường thật (static của test trước không được che)
    vvResetDummyStatic();

    $hashes = vvCountHashes();

    match ($case) {
        'login-ton-tai-sai-mk' => test()->postJson(vvApiUrl('/auth/login'), ['login' => 'hs@example.com', 'password' => 'sai-mat-khau-9'], vvWebHeaders())->assertStatus(422),
        'login-khong-ton-tai' => test()->postJson(vvApiUrl('/auth/login'), ['login' => 'khongco@example.com', 'password' => 'sai-mat-khau-9'], vvWebHeaders())->assertStatus(422),
        'reset-khong-ton-tai' => vvReset('123456', ['login' => 'khongco@example.com'])->assertStatus(422),
        'reset-khong-co-ma' => vvReset('123456')->assertStatus(422),
    };

    expect($hashes->makes)->toBe(0)->and($hashes->checks)->toBe(1);
})->with(['login-ton-tai-sai-mk', 'login-khong-ton-tai', 'reset-khong-ton-tai', 'reset-khong-co-ma']);

test('reset tai khoan bi khoa: 1 lan Hash::check', function () {
    vvPwStudent(['status' => UserStatus::Locked]);
    vvResetDummyStatic();
    LoginService::dummyHash();
    vvResetDummyStatic();

    $hashes = vvCountHashes();

    vvReset('123456')->assertStatus(422);

    expect($hashes->makes)->toBe(0)->and($hashes->checks)->toBe(1);
});
