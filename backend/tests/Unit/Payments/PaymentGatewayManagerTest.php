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
