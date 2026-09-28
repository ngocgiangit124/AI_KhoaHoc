<?php

use App\Support\PaymentsProductionGuard;

it('không chặn gì khi không phải production', function () {
    PaymentsProductionGuard::assertSafeForProduction(false, ['fake'], []);
})->throwsNoExceptions();

it('chặn production khi không bật cổng nào', function () {
    PaymentsProductionGuard::assertSafeForProduction(true, [], []);
})->throws(RuntimeException::class);

it('chặn "fake" ở production (S4)', function () {
    PaymentsProductionGuard::assertSafeForProduction(true, ['fake'], []);
})->throws(RuntimeException::class);

it('chặn tên cổng lạ/gõ sai ở production (allowlist tường minh, không có nhánh default)', function () {
    PaymentsProductionGuard::assertSafeForProduction(true, ['momo-typo'], [
        'momo-typo' => ['endpoint' => 'https://payment.momo.vn'],
    ]);
})->throws(RuntimeException::class);

it('chặn endpoint MoMo sandbox ở production', function () {
    PaymentsProductionGuard::assertSafeForProduction(true, ['momo'], [
        'momo' => [
            'endpoint' => 'https://test-payment.momo.vn',
            'partner_code' => 'X',
            'access_key' => 'X',
            'secret_key' => 'X',
        ],
    ]);
})->throws(RuntimeException::class);

it('chặn khi thiếu bất kỳ secret nào của MoMo ở production', function (string $missingKey) {
    $config = [
        'endpoint' => 'https://payment.momo.vn',
        'partner_code' => 'X',
        'access_key' => 'X',
        'secret_key' => 'X',
    ];
    $config[$missingKey] = '';

    PaymentsProductionGuard::assertSafeForProduction(true, ['momo'], ['momo' => $config]);
})->with(['partner_code', 'access_key', 'secret_key'])->throws(RuntimeException::class);

it('cho qua khi production + momo endpoint đúng + đủ secret', function () {
    PaymentsProductionGuard::assertSafeForProduction(true, ['momo'], [
        'momo' => [
            'endpoint' => 'https://payment.momo.vn',
            'partner_code' => 'PC',
            'access_key' => 'AK',
            'secret_key' => 'SK',
        ],
    ]);
})->throwsNoExceptions();
