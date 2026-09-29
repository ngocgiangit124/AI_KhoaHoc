<?php

use App\Services\Payments\Data\PaymentAttemptReference;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Gateways\MoMo\MoMoGateway;
use App\Services\Payments\Gateways\MoMo\MoMoSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array{partner_code: string, access_key: string, secret_key: string, endpoint: string, request_type: string}
 */
function momoTestConfig(): array
{
    return [
        'partner_code' => 'TESTPARTNER',
        'access_key' => 'testAccessKey',
        'secret_key' => 'testSecretKey',
        'endpoint' => 'https://test-payment.momo.vn',
        'request_type' => 'captureWallet',
    ];
}

function momoGateway(): MoMoGateway
{
    return new MoMoGateway(momoTestConfig(), new MoMoSigner);
}

/**
 * Ký payload IPN theo đúng danh sách trường ADR-001 §2 với secret của test.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signedIpnPayload(array $overrides = []): array
{
    $config = momoTestConfig();
    $signer = new MoMoSigner;

    $base = [
        'orderId' => 'ORDER1-1',
        'requestId' => 'REQ-1',
        'amount' => '100000',
        'orderInfo' => 'Thanh toan don hang ORDER1',
        'orderType' => 'momo_wallet',
        'partnerCode' => $config['partner_code'],
        'payType' => 'qr',
        'responseTime' => '1700000000000',
        'resultCode' => '0',
        'transId' => '999999',
        'message' => 'Successful.',
        'extraData' => '',
    ];

    $payload = array_merge($base, $overrides);

    $signedFields = [
        'accessKey' => $config['access_key'],
        'amount' => (string) $payload['amount'],
        'extraData' => (string) $payload['extraData'],
        'message' => (string) $payload['message'],
        'orderId' => (string) $payload['orderId'],
        'orderInfo' => (string) $payload['orderInfo'],
        'orderType' => (string) $payload['orderType'],
        'partnerCode' => (string) $payload['partnerCode'],
        'payType' => (string) $payload['payType'],
        'requestId' => (string) $payload['requestId'],
        'responseTime' => (string) $payload['responseTime'],
        'resultCode' => (string) $payload['resultCode'],
        'transId' => (string) $payload['transId'],
    ];

    $payload['signature'] = $signer->sign($signedFields, $config['secret_key']);

    return $payload;
}

// --- createPayment -----------------------------------------------------

it('createPayment trả PaymentInitResult khi MoMo trả resultCode 0', function () {
    $config = momoTestConfig();

    Http::fake([
        'test-payment.momo.vn/*' => Http::response([
            'partnerCode' => $config['partner_code'],
            'orderId' => 'ORDER1-1',
            'requestId' => 'REQ-1',
            'resultCode' => 0,
            'message' => 'Successful.',
            'payUrl' => 'https://test-payment.momo.vn/pay/abc',
            'signature' => 'irrelevant-for-create-response',
        ], 200),
    ]);

    $result = momoGateway()->createPayment(new PaymentRequest(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
        description: 'Thanh toan don hang ORDER1',
        returnUrl: 'https://vitaminvui.test/checkout/ket-qua?order=ORDER1',
        notifyUrl: 'https://api.vitaminvui.test/webhooks/payments/momo',
    ));

    expect($result->payUrl)->toBe('https://test-payment.momo.vn/pay/abc')
        ->and($result->rawResponse)->not->toHaveKey('signature');

    Http::assertSent(function ($request) {
        // Không log/gửi khoá bí mật ra ngoài payload đã ký (chỉ chữ ký hex).
        return ! str_contains(json_encode($request->data()), 'testSecretKey');
    });
});

it('createPayment ném GatewayUnavailableException khi resultCode khác 0', function () {
    Http::fake([
        'test-payment.momo.vn/*' => Http::response([
            'partnerCode' => momoTestConfig()['partner_code'],
            'orderId' => 'ORDER1-1',
            'requestId' => 'REQ-1',
            'resultCode' => 99,
            'message' => 'Failed.',
        ], 200),
    ]);

    momoGateway()->createPayment(new PaymentRequest(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
        description: 'Thanh toan don hang ORDER1',
        returnUrl: 'https://vitaminvui.test/return',
        notifyUrl: 'https://api.vitaminvui.test/webhooks/payments/momo',
    ));
})->throws(GatewayUnavailableException::class);

it('createPayment ném GatewayUnavailableException khi orderId phản hồi không khớp', function () {
    Http::fake([
        'test-payment.momo.vn/*' => Http::response([
            'partnerCode' => momoTestConfig()['partner_code'],
            'orderId' => 'ORDER-KHAC',
            'requestId' => 'REQ-1',
            'resultCode' => 0,
            'payUrl' => 'https://test-payment.momo.vn/pay/abc',
        ], 200),
    ]);

    momoGateway()->createPayment(new PaymentRequest(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
        description: 'Thanh toan don hang ORDER1',
        returnUrl: 'https://vitaminvui.test/return',
        notifyUrl: 'https://api.vitaminvui.test/webhooks/payments/momo',
    ));
})->throws(GatewayUnavailableException::class);

it('createPayment ném GatewayUnavailableException khi requestId phản hồi không khớp', function () {
    Http::fake([
        'test-payment.momo.vn/*' => Http::response([
            'partnerCode' => momoTestConfig()['partner_code'],
            'orderId' => 'ORDER1-1',
            'requestId' => 'REQ-KHAC',
            'resultCode' => 0,
            'payUrl' => 'https://test-payment.momo.vn/pay/abc',
        ], 200),
    ]);

    momoGateway()->createPayment(new PaymentRequest(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
        description: 'Thanh toan don hang ORDER1',
        returnUrl: 'https://vitaminvui.test/return',
        notifyUrl: 'https://api.vitaminvui.test/webhooks/payments/momo',
    ));
})->throws(GatewayUnavailableException::class);

it('createPayment ném GatewayUnavailableException khi lỗi mạng (map 502 domain exception)', function () {
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    try {
        momoGateway()->createPayment(new PaymentRequest(
            gatewayOrderId: 'ORDER1-1',
            requestId: 'REQ-1',
            amount: 100000,
            description: 'Thanh toan don hang ORDER1',
            returnUrl: 'https://vitaminvui.test/return',
            notifyUrl: 'https://api.vitaminvui.test/webhooks/payments/momo',
        ));

        expect(false)->toBeTrue('Phải ném GatewayUnavailableException');
    } catch (GatewayUnavailableException $e) {
        expect($e->code())->toBe('PAYMENT_GATEWAY_UNAVAILABLE')
            ->and($e->status())->toBe(502);
    }
});

it('createPayment ném GatewayUnavailableException khi MoMo trả 5xx', function () {
    Http::fake([
        'test-payment.momo.vn/*' => Http::response(['message' => 'server error'], 500),
    ]);

    momoGateway()->createPayment(new PaymentRequest(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'REQ-1',
        amount: 100000,
        description: 'Thanh toan don hang ORDER1',
        returnUrl: 'https://vitaminvui.test/return',
        notifyUrl: 'https://api.vitaminvui.test/webhooks/payments/momo',
    ));
})->throws(GatewayUnavailableException::class);

// --- parseNotification ---------------------------------------------------

it('parseNotification trả GatewayNotification Succeeded khi resultCode=0 và chữ ký hợp lệ', function () {
    $request = Request::create('/webhooks/payments/momo', 'POST', signedIpnPayload());

    $notification = momoGateway()->parseNotification($request);

    expect($notification->status)->toBe(PaymentStatus::Succeeded)
        ->and($notification->amount)->toBe(100000)
        ->and($notification->gatewayOrderId)->toBe('ORDER1-1')
        ->and($notification->raw)->not->toHaveKey('signature');
});

it('parseNotification ánh xạ resultCode 9000 sang Pending, không bao giờ Succeeded', function () {
    $request = Request::create('/webhooks/payments/momo', 'POST', signedIpnPayload(['resultCode' => '9000']));

    expect(momoGateway()->parseNotification($request)->status)->toBe(PaymentStatus::Pending);
});

it('parseNotification từ chối khi chữ ký sai', function () {
    $payload = signedIpnPayload();
    $payload['signature'] = 'chu-ky-gia';

    momoGateway()->parseNotification(Request::create('/webhooks/payments/momo', 'POST', $payload));
})->throws(InvalidSignatureException::class);

it('parseNotification từ chối khi thiếu trường bắt buộc', function () {
    $payload = signedIpnPayload();
    unset($payload['transId']);

    momoGateway()->parseNotification(Request::create('/webhooks/payments/momo', 'POST', $payload));
})->throws(InvalidSignatureException::class);

it('parseNotification từ chối khi partnerCode không khớp cấu hình', function () {
    $payload = signedIpnPayload(['partnerCode' => 'PARTNER-KHAC']);

    momoGateway()->parseNotification(Request::create('/webhooks/payments/momo', 'POST', $payload));
})->throws(InvalidSignatureException::class);

it('parseNotification từ chối amount không nghiêm ngặt dù chữ ký hợp lệ', function (string $amount) {
    $payload = signedIpnPayload(['amount' => $amount]);

    momoGateway()->parseNotification(Request::create('/webhooks/payments/momo', 'POST', $payload));
})->with(['100000.0', '1e5', '-100000'])->throws(InvalidSignatureException::class);

// --- queryStatus -----------------------------------------------------

it('queryStatus xác thực chữ ký phản hồi và trả GatewayNotification', function () {
    $config = momoTestConfig();
    $signer = new MoMoSigner;

    $responseBody = [
        'amount' => '100000',
        'extraData' => '',
        'message' => 'Successful.',
        'orderId' => 'ORDER1-1',
        'orderInfo' => 'Thanh toan don hang ORDER1',
        'orderType' => 'momo_wallet',
        'partnerCode' => $config['partner_code'],
        'payType' => 'qr',
        'responseTime' => '1700000000000',
        'resultCode' => '0',
        'transId' => '999999',
    ];

    $signedFields = array_merge(['accessKey' => $config['access_key']], $responseBody);
    // requestId của response phải khớp field ký nhưng giá trị cụ thể do gateway
    // tự sinh UUID mới cho request — ta không biết trước, nên bắt request thực
    // tế rồi trả lại chữ ký tương ứng bằng callback của Http::fake.
    Http::fake(function (Illuminate\Http\Client\Request $request) use ($responseBody, $signedFields, $signer, $config) {
        $sentRequestId = (string) $request->data()['requestId'];
        $body = array_merge($responseBody, ['requestId' => $sentRequestId]);
        $fields = array_merge($signedFields, ['requestId' => $sentRequestId]);
        $body['signature'] = $signer->sign($fields, $config['secret_key']);

        return Http::response($body, 200);
    });

    $notification = momoGateway()->queryStatus(new PaymentAttemptReference(
        gatewayOrderId: 'ORDER1-1',
        requestId: 'ignored-original-request-id',
        amount: 100000,
    ));

    expect($notification->status)->toBe(PaymentStatus::Succeeded)
        ->and($notification->amount)->toBe(100000);
});

it('queryStatus ném InvalidSignatureException khi requestId phản hồi không khớp requestId đã gửi', function () {
    $config = momoTestConfig();
    $signer = new MoMoSigner;

    // Response ký hợp lệ nhưng dùng một `requestId` KHÁC với `requestId` mà
    // gateway vừa gửi đi trong request `query` (giả lập trộn lẫn phản hồi/
    // đầu độc từ một request query khác) — phải bị từ chối dù chữ ký đúng.
    Http::fake(function (Illuminate\Http\Client\Request $request) use ($signer, $config) {
        $body = [
            'accessKey' => $config['access_key'],
            'amount' => '100000',
            'extraData' => '',
            'message' => 'Successful.',
            'orderId' => 'ORDER1-1',
            'orderInfo' => 'Thanh toan don hang ORDER1',
            'orderType' => 'momo_wallet',
            'partnerCode' => $config['partner_code'],
            'payType' => 'qr',
            'requestId' => 'REQUEST-ID-KHAC',
            'responseTime' => '1700000000000',
            'resultCode' => '0',
            'transId' => '999999',
        ];

        $signedFields = $body;
        unset($signedFields['accessKey']);
        $signedFields = array_merge(['accessKey' => $config['access_key']], $signedFields);

        $body['signature'] = $signer->sign($signedFields, $config['secret_key']);

        return Http::response($body, 200);
    });

    momoGateway()->queryStatus(new PaymentAttemptReference('ORDER1-1', 'REQ-1', 100000));
})->throws(InvalidSignatureException::class);

it('queryStatus từ chối khi phản hồi không có/sai chữ ký', function () {
    Http::fake([
        'test-payment.momo.vn/*' => Http::response([
            'orderId' => 'ORDER1-1',
            'partnerCode' => momoTestConfig()['partner_code'],
            'resultCode' => '0',
            'amount' => '100000',
            // Không có "signature".
        ], 200),
    ]);

    momoGateway()->queryStatus(new PaymentAttemptReference('ORDER1-1', 'REQ-1', 100000));
})->throws(InvalidSignatureException::class);

it('queryStatus ném GatewayUnavailableException khi lỗi mạng', function () {
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    momoGateway()->queryStatus(new PaymentAttemptReference('ORDER1-1', 'REQ-1', 100000));
})->throws(GatewayUnavailableException::class);
