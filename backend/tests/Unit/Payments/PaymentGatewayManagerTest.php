<?php

use App\Services\Payments\Gateways\Fake\FakeGateway;
use App\Services\Payments\Gateways\MoMo\MoMoGateway;
use App\Services\Payments\PaymentGatewayManager;

/**
 * `PaymentGatewayManager` (ADR-001 §1, S4): chỉ resolve cổng nằm trong
 * allowlist `payments.enabled_gateways`, và `fake` không bao giờ boot ngoài
 * local/testing — dù có lỡ nằm trong allowlist.
 */
function paymentGatewayManager(): PaymentGatewayManager
{
    return app(PaymentGatewayManager::class);
}

it('resolve momo khi nằm trong allowlist', function () {
    config([
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo' => [
            'partner_code' => 'PARTNER',
            'access_key' => 'ACCESS',
            'secret_key' => 'SECRET',
            'endpoint' => 'https://test-payment.momo.vn',
            'request_type' => 'captureWallet',
        ],
    ]);

    expect(paymentGatewayManager()->driver('momo'))->toBeInstanceOf(MoMoGateway::class);
});

it('ném lỗi khi resolve cổng không nằm trong allowlist dù class có tồn tại', function () {
    config(['payments.enabled_gateways' => ['momo']]);

    paymentGatewayManager()->driver('fake');
})->throws(RuntimeException::class);

it('resolve fake khi nằm trong allowlist và đang ở testing', function () {
    config(['payments.enabled_gateways' => ['fake']]);

    expect(paymentGatewayManager()->driver('fake'))->toBeInstanceOf(FakeGateway::class);
});

it('getDefaultDriver trả cổng đầu tiên của allowlist', function () {
    config([
        'payments.enabled_gateways' => ['fake'],
    ]);

    expect(paymentGatewayManager()->driver())->toBeInstanceOf(FakeGateway::class);
});

it('ném lỗi khi allowlist rỗng', function () {
    config(['payments.enabled_gateways' => []]);

    paymentGatewayManager()->getDefaultDriver();
})->throws(RuntimeException::class);

it('FakeGateway không bao giờ boot ngoài local/testing dù nằm trong allowlist (S4, lớp phòng thủ thứ hai)', function () {
    config(['payments.enabled_gateways' => ['fake']]);
    app()->detectEnvironment(fn () => 'production');

    paymentGatewayManager()->driver('fake');
})->throws(RuntimeException::class);

/**
 * M2 (review bảo mật T17) — fail-closed ở MỌI môi trường, không chỉ
 * production: thiếu 1 trong 4 khoá cấu hình MoMo (rỗng hoặc chỉ có khoảng
 * trắng) phải chặn việc resolve adapter, KHÔNG được để `MoMoSigner` ký/verify
 * bằng `secretKey=''` (ai cũng tính lại được).
 */
it('ném lỗi khi thiếu bất kỳ khoá cấu hình MoMo nào (rỗng hoặc chỉ khoảng trắng) (M2)', function (string $emptyKey, string $emptyValue) {
    $config = [
        'partner_code' => 'PARTNER',
        'access_key' => 'ACCESS',
        'secret_key' => 'SECRET',
        'endpoint' => 'https://test-payment.momo.vn',
        'request_type' => 'captureWallet',
    ];

    $config[$emptyKey] = $emptyValue;

    config([
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo' => $config,
    ]);

    paymentGatewayManager()->driver('momo');
})->with([
    ['partner_code', ''],
    ['partner_code', '   '],
    ['access_key', ''],
    ['access_key', '   '],
    ['secret_key', ''],
    ['secret_key', '   '],
    ['endpoint', ''],
    ['endpoint', '   '],
])->throws(RuntimeException::class);
