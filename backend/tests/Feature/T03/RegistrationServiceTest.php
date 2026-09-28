<?php

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use App\Services\Auth\RegistrationService;
use Illuminate\Validation\ValidationException;

/**
 * data-model §4 — "Trùng email/SĐT khi đăng ký đồng thời": gọi thẳng
 * `RegistrationService` (bỏ qua bước kiểm `unique` chủ động của `RegisterRequest`)
 * để mô phỏng race condition — 2 request đăng ký gần như đồng thời cùng
 * email/SĐT, cả hai đều "qua" được validate trước khi 1 trong 2 insert trước.
 */
function vvValidRegisterData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Người đăng ký',
        'date_of_birth' => '2000-01-01',
        'email' => 'race'.uniqid().'@example.com',
        'phone' => '09'.random_int(10000000, 99999999),
        'grade_level' => 10,
        'password' => 'password123',
        'captcha_token' => 'ok',
    ], $overrides);
}

test('unique race tren email tra ve ValidationException dung field email', function () {
    User::factory()->create(['email' => 'daco@example.com']);

    $service = app(RegistrationService::class);

    expect(fn () => $service->register(
        vvValidRegisterData(['email' => 'daco@example.com']),
        '127.0.0.1',
        'PestAgent',
    ))->toThrow(ValidationException::class);

    try {
        $service->register(vvValidRegisterData(['email' => 'daco@example.com']), '127.0.0.1', 'PestAgent');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('email');
    }
});

test('unique race tren so dien thoai tra ve ValidationException dung field phone', function () {
    User::factory()->create(['phone' => '0999888777']);

    $service = app(RegistrationService::class);

    try {
        $service->register(vvValidRegisterData(['phone' => '0999888777']), '127.0.0.1', 'PestAgent');
        expect(false)->toBeTrue('Phải ném ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('phone');
    }
});

test('captcha sai nem DomainException CAPTCHA_FAILED truoc khi cham DB', function () {
    $service = app(RegistrationService::class);

    expect(fn () => $service->register(
        vvValidRegisterData(['captcha_token' => FakeCaptchaVerifier::INVALID_TOKEN]),
        '127.0.0.1',
        'PestAgent',
    ))->toThrow(DomainException::class);
});
