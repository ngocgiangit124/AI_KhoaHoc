<?php

use App\Services\Payments\Enums\PaymentStatus;
use App\Services\Payments\Gateways\MoMo\MoMoResultCode;

it('chỉ mã 0 là Succeeded (S12.1)', function () {
    expect(MoMoResultCode::toStatus('0'))->toBe(PaymentStatus::Succeeded);
});

it('mã đang xử lý/chờ capture là Pending, không bao giờ Succeeded', function (string $code) {
    expect(MoMoResultCode::toStatus($code))->toBe(PaymentStatus::Pending);
})->with(['1000', '7000', '7002', '9000']);

it('mã khác là Failed', function (string $code) {
    expect(MoMoResultCode::toStatus($code))->toBe(PaymentStatus::Failed);
})->with(['1', '99', '1001', '2', 'abc']);
