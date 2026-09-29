<?php

use App\Services\Payments\Data\PaymentAttemptReference;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Gateways\Fake\FakeGateway;
use Illuminate\Http\Request;

/**
 * `FakeGateway` (ADR-001 §1, S4) — chỉ dùng ở local/testing để test
 * nghiệp vụ (checkout/webhook — T18/T19) mà không cần gọi MoMo thật. Ở đây
 * chỉ kiểm hợp đồng `PaymentGateway` của chính adapter giả này.
 */
it('createPayment trả payUrl giả kèm orderId/requestId', function () {
    $result = (new FakeGateway)->createPayment(new PaymentRequest(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
        description: 'Thanh toan don hang ORDER1',
        returnUrl: 'https://vitaminvui.test/return',
        notifyUrl: 'https://vitaminvui.test/webhooks/payments/fake',
    ));

    expect($result->payUrl)->toBe('https://fake-gateway.test/pay/ORDER1-1')
        ->and($result->rawResponse)->toMatchArray(['orderId' => 'ORDER1-1', 'requestId' => 'REQ-1']);
});

it('parseNotification chấp nhận payload ký đúng và trả Succeeded/Pending/Failed đúng "status"', function (string $status, PaymentStatus $expected) {
    $gateway = new FakeGateway;

    $payload = [
        'orderId' => 'ORDER1-1',
        'requestId' => 'REQ-1',
        'amount' => '100000',
        'status' => $status,
    ];
    $payload['signature'] = $gateway->sign($payload);

    $notification = $gateway->parseNotification(Request::create('/webhooks/payments/fake', 'POST', $payload));

    expect($notification->status)->toBe($expected)
        ->and($notification->amount)->toBe(100000)
        ->and($notification->raw)->not->toHaveKey('signature');
})->with([
    ['succeeded', PaymentStatus::Succeeded],
    ['pending', PaymentStatus::Pending],
    ['failed', PaymentStatus::Failed],
]);

it('parseNotification từ chối khi chữ ký sai', function () {
    $gateway = new FakeGateway;

    $payload = [
        'orderId' => 'ORDER1-1',
        'requestId' => 'REQ-1',
        'amount' => '100000',
        'status' => 'succeeded',
        'signature' => 'chu-ky-gia',
    ];

    $gateway->parseNotification(Request::create('/webhooks/payments/fake', 'POST', $payload));
})->throws(InvalidSignatureException::class);

it('parseNotification từ chối khi thiếu trường bắt buộc', function () {
    $gateway = new FakeGateway;

    $payload = ['orderId' => 'ORDER1-1', 'requestId' => 'REQ-1', 'amount' => '100000'];
    $payload['signature'] = $gateway->sign($payload);
    unset($payload['status']);

    $gateway->parseNotification(Request::create('/webhooks/payments/fake', 'POST', $payload));
})->throws(InvalidSignatureException::class);

it('parseNotification từ chối amount không nghiêm ngặt dù chữ ký hợp lệ', function (string $amount) {
    $gateway = new FakeGateway;

    $payload = ['orderId' => 'ORDER1-1', 'requestId' => 'REQ-1', 'amount' => $amount, 'status' => 'succeeded'];
    $payload['signature'] = $gateway->sign($payload);

    $gateway->parseNotification(Request::create('/webhooks/payments/fake', 'POST', $payload));
})->with(['100000.0', '1e5', '-100000'])->throws(InvalidSignatureException::class);

it('acknowledge luôn trả 204 (không phân biệt accepted)', function (bool $accepted) {
    expect((new FakeGateway)->acknowledge($accepted)->getStatusCode())->toBe(204);
})->with([true, false]);

it('queryStatus trả Pending mặc định (không có logic đối soát thật)', function () {
    $notification = (new FakeGateway)->queryStatus(new PaymentAttemptReference(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
    ));

    expect($notification->status)->toBe(PaymentStatus::Pending)
        ->and($notification->amount)->toBe(100000)
        ->and($notification->gatewayOrderId)->toBe('ORDER1-1');
});

it('code() trả "fake"', function () {
    expect((new FakeGateway)->code())->toBe('fake');
});
