<?php

use App\Enums\GatewayPaymentStatus;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\PaymentStatusQuery;
use App\Services\Payments\Exceptions\AmountOutOfRangeException;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Gateways\Momo\MoMoGateway;
use App\Services\Payments\Gateways\Momo\MoMoSigner;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Các test này không cần DB nhưng nằm trong Feature (RefreshDatabase) theo cấu hình Pest hiện có.

const MOMO_SECRET = 'test-secret-key-not-real';

beforeEach(function () {
    config([
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.partner_code' => 'MOMOTEST',
        'payments.gateways.momo.access_key' => 'ACCESSKEY1',
        'payments.gateways.momo.secret_key' => MOMO_SECRET,
        'payments.gateways.momo.endpoint' => 'https://test-payment.momo.vn',
        'payments.gateways.momo.pay_url_hosts' => ['test-payment.momo.vn', 'payment.momo.vn'],
    ]);
});

function momoReq(int $amount = 150000): PaymentRequest
{
    return new PaymentRequest(
        gatewayOrderId: 'VV123-1',
        requestId: 'req-uuid-1',
        amount: $amount,
        description: 'Thanh toan don hang VV123',
        returnUrl: 'https://vitaminvui.vn/checkout/ket-qua?order=VV123',
        notifyUrl: 'https://api.vitaminvui.vn/api/v1/webhooks/payments/momo',
        expiresAt: now()->addMinutes(30),
    );
}

/** @param  array<string, mixed>  $over */
function momoIpn(array $over = [], bool $sign = true): array
{
    $p = array_merge([
        'partnerCode' => 'MOMOTEST',
        'orderId' => 'VV123-1',
        'requestId' => 'req-uuid-1',
        'amount' => 150000,
        'orderInfo' => 'Thanh toan don hang VV123',
        'orderType' => 'momo_wallet',
        'transId' => 2820000001,
        'resultCode' => 0,
        'message' => 'Successful.',
        'payType' => 'qr',
        'responseTime' => 1790000000000,
        'extraData' => '',
    ], $over);

    if ($sign) {
        $p['signature'] = (new MoMoSigner)->sign(['accessKey' => 'ACCESSKEY1'] + $p, MoMoSigner::IPN_FIELDS, MOMO_SECRET);
    }

    return $p;
}

function ipnRequest(array $payload): Request
{
    return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
}

// ---------- createPayment ----------

test('createPayment ky dung, khong gui accessKey/secret trong body, tra payUrl', function () {
    Http::fake(['*' => Http::response([
        'partnerCode' => 'MOMOTEST', 'orderId' => 'VV123-1', 'requestId' => 'req-uuid-1', 'amount' => 150000,
        'resultCode' => 0, 'message' => 'Thanh cong', 'payUrl' => 'https://test-payment.momo.vn/v2/gateway/pay?t=abc',
        'signature' => 'xyz',
    ])]);

    $result = app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq());

    expect($result->payUrl)->toBe('https://test-payment.momo.vn/v2/gateway/pay?t=abc')
        ->and($result->rawResponse)->not->toHaveKey('signature');

    Http::assertSent(function (HttpRequest $r) {
        $b = $r->data();
        $expected = (new MoMoSigner)->sign([
            'accessKey' => 'ACCESSKEY1', 'amount' => '150000', 'extraData' => '', 'ipnUrl' => $b['ipnUrl'],
            'orderId' => 'VV123-1', 'orderInfo' => 'Thanh toan don hang VV123', 'partnerCode' => 'MOMOTEST',
            'redirectUrl' => $b['redirectUrl'], 'requestId' => 'req-uuid-1', 'requestType' => 'captureWallet',
        ], MoMoSigner::CREATE_FIELDS, MOMO_SECRET);

        return $r->url() === 'https://test-payment.momo.vn/v2/gateway/api/create'
            && $b['signature'] === $expected
            && ! array_key_exists('accessKey', $b)
            && ! str_contains($r->body(), MOMO_SECRET)
            && $b['amount'] === 150000
            && $b['extraData'] === '';
    });
});

test('createPayment khong retry khi loi 500 va nem GatewayUnavailable', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class);
    Http::assertSentCount(1);
});

test('createPayment timeout/loi mang nem GatewayUnavailable', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class);
});

test('createPayment resultCode != 0 nem GatewayUnavailable', function () {
    Http::fake(['*' => Http::response(['resultCode' => 11, 'message' => 'x'])]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class);
});

test('createPayment tu choi payUrl sai host/scheme/userinfo', function (string $payUrl) {
    Http::fake(['*' => Http::response([
        'orderId' => 'VV123-1', 'requestId' => 'req-uuid-1', 'resultCode' => 0, 'payUrl' => $payUrl,
    ])]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class, 'invalid_pay_url');
})->with([
    'host la' => 'https://evil.example/pay',
    'http' => 'http://test-payment.momo.vn/pay',
    'host giong' => 'https://test-payment.momo.vn.evil.example/pay',
    'userinfo' => 'https://test-payment.momo.vn@evil.example/pay',
    'khong phai url' => 'javascript:alert(1)',
]);

test('createPayment tu choi phan hoi lech orderId/requestId', function () {
    Http::fake(['*' => Http::response([
        'orderId' => 'KHAC-1', 'requestId' => 'req-uuid-1', 'resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/p',
    ])]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class);
});

test('createPayment kiem han muc so tien truoc khi goi mang', function () {
    Http::fake();
    $gw = app(PaymentGatewayManager::class)->driver('momo');

    expect(fn () => $gw->createPayment(momoReq(999)))->toThrow(AmountOutOfRangeException::class);
    expect(fn () => $gw->createPayment(momoReq(50000001)))->toThrow(AmountOutOfRangeException::class);
    Http::assertNothingSent();
});

test('endpoint http hoac thieu cau hinh khoa bi tu choi, khong goi mang', function () {
    Http::fake();
    config(['payments.gateways.momo.endpoint' => 'http://payment.momo.vn']);
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class);
    Http::assertNothingSent();

    config(['payments.gateways.momo.endpoint' => 'https://payment.momo.vn', 'payments.gateways.momo.secret_key' => '']);
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class);
    Http::assertNothingSent();
});

test('log payments khong chua secret hoac signature', function () {
    $messages = [];
    Log::shouldReceive('channel')->with('payments')->andReturnSelf();
    Log::shouldReceive('info')->andReturnUsing(function ($msg, $ctx = []) use (&$messages) {
        $messages[] = json_encode([$msg, $ctx]);
    });
    Http::fake(['*' => Http::response(['orderId' => 'VV123-1', 'requestId' => 'req-uuid-1', 'resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/p', 'signature' => 'SIGVALUE'])]);

    app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq());

    $joined = implode('|', $messages);
    expect($messages)->not->toBeEmpty()
        ->and($joined)->not->toContain(MOMO_SECRET)
        ->and($joined)->not->toContain('SIGVALUE')
        ->and($joined)->not->toContain('ACCESSKEY1');
});

// ---------- parseNotification ----------

test('IPN hop le: Succeeded, amount int, raw khong co signature', function () {
    $n = app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest(momoIpn()));

    expect($n->status)->toBe(GatewayPaymentStatus::Succeeded)
        ->and($n->amount)->toBe(150000)
        ->and($n->gatewayOrderId)->toBe('VV123-1')
        ->and($n->requestId)->toBe('req-uuid-1')
        ->and($n->transactionId)->toBe('2820000001')
        ->and($n->raw)->not->toHaveKey('signature')
        ->and($n->raw)->not->toHaveKey('accessKey');
});

test('IPN amount dang chuoi chi gom so duoc chap nhan', function () {
    $n = app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest(momoIpn(['amount' => '150000'])));
    expect($n->amount)->toBe(150000);
});

test('IPN amount la bi tu choi (du chu ky dung)', function (mixed $amount) {
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest(momoIpn(['amount' => $amount]))))
        ->toThrow(InvalidSignatureException::class);
})->with(['thap phan' => '150000.0', 'mu' => '1e5', 'am' => -5, 'am chuoi' => '-5', 'rong' => '', 'float' => 150000.5, 'khoang trang' => ' 150000']);

test('IPN chu ky sai / thieu / khoa sai', function () {
    $gw = app(PaymentGatewayManager::class)->driver('momo');

    $bad = momoIpn();
    $bad['signature'] = str_repeat('a', 64);
    expect(fn () => $gw->parseNotification(ipnRequest($bad)))->toThrow(InvalidSignatureException::class);

    $none = momoIpn(sign: false);
    expect(fn () => $gw->parseNotification(ipnRequest($none)))->toThrow(InvalidSignatureException::class);

    // sửa amount sau khi ký
    $tampered = momoIpn();
    $tampered['amount'] = 1000;
    expect(fn () => $gw->parseNotification(ipnRequest($tampered)))->toThrow(InvalidSignatureException::class);
});

test('IPN thieu truong bat buoc bi tu choi', function (string $field) {
    $p = momoIpn();
    unset($p[$field]);
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest($p)))
        ->toThrow(InvalidSignatureException::class);
})->with(['orderId', 'transId', 'resultCode', 'requestId', 'partnerCode', 'amount', 'extraData']);

test('IPN accessKey luon lay tu config, gui kem accessKey gia vo hieu', function () {
    // Kẻ tấn công ký bằng accessKey khác (không biết secret thì không ký được; ở đây dùng đúng secret
    // nhưng accessKey khác → phải bị từ chối vì server dùng accessKey từ config).
    $p = momoIpn(sign: false);
    $p['signature'] = (new MoMoSigner)->sign(['accessKey' => 'KHAC'] + $p, MoMoSigner::IPN_FIELDS, MOMO_SECRET);
    $p['accessKey'] = 'KHAC';

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest($p)))
        ->toThrow(InvalidSignatureException::class);
});

test('IPN partnerCode lech config bi tu choi du chu ky dung', function () {
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest(momoIpn(['partnerCode' => 'KHAC']))))
        ->toThrow(InvalidSignatureException::class, 'partner_mismatch');
});

test('bang ma ket qua: chi 0 la Succeeded', function (int $code, GatewayPaymentStatus $expected) {
    $n = app(PaymentGatewayManager::class)->driver('momo')->parseNotification(ipnRequest(momoIpn(['resultCode' => $code])));
    expect($n->status)->toBe($expected);
})->with([
    [0, GatewayPaymentStatus::Succeeded],
    [9000, GatewayPaymentStatus::Pending],
    [1000, GatewayPaymentStatus::Pending],
    [7000, GatewayPaymentStatus::Pending],
    [7002, GatewayPaymentStatus::Pending],
    [8000, GatewayPaymentStatus::Pending],
    [10, GatewayPaymentStatus::Pending],
    [99, GatewayPaymentStatus::Pending],
    [1005, GatewayPaymentStatus::Failed],
    [1006, GatewayPaymentStatus::Failed],
    [4242, GatewayPaymentStatus::Failed],
    [-1, GatewayPaymentStatus::Failed],
]);

test('resultCode dang chuoi "0" van la Succeeded, "abc" bi tu choi', function () {
    $gw = app(PaymentGatewayManager::class)->driver('momo');
    expect($gw->parseNotification(ipnRequest(momoIpn(['resultCode' => '0'])))->status)->toBe(GatewayPaymentStatus::Succeeded);
    expect(fn () => $gw->parseNotification(ipnRequest(momoIpn(['resultCode' => 'abc']))))->toThrow(InvalidSignatureException::class);
});

test('acknowledge: 204 khi accepted, 400 khi khong', function () {
    $gw = app(PaymentGatewayManager::class)->driver('momo');
    expect($gw->acknowledge(true)->getStatusCode())->toBe(204)
        ->and($gw->acknowledge(false)->getStatusCode())->toBe(400);
});

// ---------- queryStatus ----------

/** @param  array<string, mixed>  $over */
function momoQueryResponse(HttpRequest $r, array $over = [], bool $sign = true): array
{
    $p = array_merge(momoIpn(['requestId' => $r->data()['requestId']], false), $over);
    if ($sign) {
        $p['signature'] = (new MoMoSigner)->sign(['accessKey' => 'ACCESSKEY1'] + $p, MoMoSigner::IPN_FIELDS, MOMO_SECRET);
    }

    return $p;
}

test('queryStatus ky request, verify phan hoi, tra Succeeded', function () {
    Http::fake(fn (HttpRequest $r) => Http::response(momoQueryResponse($r)));

    $n = app(PaymentGatewayManager::class)->driver('momo')->queryStatus(new PaymentStatusQuery('VV123-1', 150000));

    expect($n->status)->toBe(GatewayPaymentStatus::Succeeded)->and($n->amount)->toBe(150000);

    Http::assertSent(function (HttpRequest $r) {
        $b = $r->data();
        $expected = (new MoMoSigner)->sign(
            ['accessKey' => 'ACCESSKEY1', 'orderId' => 'VV123-1', 'partnerCode' => 'MOMOTEST', 'requestId' => $b['requestId']],
            MoMoSigner::QUERY_REQUEST_FIELDS,
            MOMO_SECRET
        );

        return $r->url() === 'https://test-payment.momo.vn/v2/gateway/api/query'
            && $b['signature'] === $expected
            && $b['orderId'] === 'VV123-1'
            && preg_match('/^[0-9a-f-]{36}$/', $b['requestId']) === 1;
    });
});

test('queryStatus phan hoi khong hop le -> InvalidSignature', function (array $over, bool $sign, string $reason) {
    Http::fake(fn (HttpRequest $r) => Http::response(momoQueryResponse($r, $over, $sign)));

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->queryStatus(new PaymentStatusQuery('VV123-1', 150000)))
        ->toThrow(InvalidSignatureException::class, $reason);
})->with([
    'khong co chu ky' => [[], false, 'bad_signature'],
    'chu ky sai' => [['signature' => '0000000000000000000000000000000000000000000000000000000000000000'], false, 'bad_signature'],
    'don khac' => [['orderId' => 'VV999-1'], true, 'response_mismatch'],
    'requestId cu' => [['requestId' => 'cu'], true, 'response_mismatch'],
    'partnerCode lech' => [['partnerCode' => 'KHAC'], true, 'partner_mismatch'],
]);

test('queryStatus resultCode dang xu ly khong phai thanh cong', function () {
    Http::fake(fn (HttpRequest $r) => Http::response(momoQueryResponse($r, ['resultCode' => 7002])));

    $n = app(PaymentGatewayManager::class)->driver('momo')->queryStatus(new PaymentStatusQuery('VV123-1', 150000));
    expect($n->status)->toBe(GatewayPaymentStatus::Pending);
});

test('queryStatus timeout nem GatewayUnavailable', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->queryStatus(new PaymentStatusQuery('VV123-1', 150000)))
        ->toThrow(GatewayUnavailableException::class);
});

test('queryStatus 5xx nem GatewayUnavailable', function () {
    Http::fake(['*' => Http::response('', 503)]);
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->queryStatus(new PaymentStatusQuery('VV123-1', 150000)))
        ->toThrow(GatewayUnavailableException::class);
});

test('query: ma la mac dinh Pending, ma het han danh dau expired, ma that bai ro rang van Failed', function () {
    expect(MoMoGateway::mapResultCode(4242, 'query'))->toBe(GatewayPaymentStatus::Pending)
        ->and(MoMoGateway::mapResultCode(4242, 'ipn'))->toBe(GatewayPaymentStatus::Failed)
        ->and(MoMoGateway::mapResultCode(1006, 'query'))->toBe(GatewayPaymentStatus::Failed)
        ->and(MoMoGateway::mapResultCode(0, 'query'))->toBe(GatewayPaymentStatus::Succeeded);

    Http::fake(fn (HttpRequest $r) => Http::response(momoQueryResponse($r, ['resultCode' => 1005])));
    $n = app(PaymentGatewayManager::class)->driver('momo')->queryStatus(new PaymentStatusQuery('VV123-1', 150000));
    expect($n->status)->toBe(GatewayPaymentStatus::Failed)->and($n->expired)->toBeTrue();
});

test('pay_url bi tu choi: port la, khoang trang, ky tu dieu khien, backslash', function (string $payUrl) {
    Http::fake(['*' => Http::response(['orderId' => 'VV123-1', 'requestId' => 'req-uuid-1', 'resultCode' => 0, 'payUrl' => $payUrl])]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('momo')->createPayment(momoReq()))
        ->toThrow(GatewayUnavailableException::class, 'invalid_pay_url');
})->with([
    'port' => 'https://test-payment.momo.vn:8443/pay',
    'khoang trang' => 'https://test-payment.momo.vn/pa y',
    'xuong dong' => "https://test-payment.momo.vn/pay\r\nX: y",
    'backslash' => 'https://evil.com\\@test-payment.momo.vn/pay',
]);
