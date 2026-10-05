<?php

use App\Enums\GatewayPaymentStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\PaymentStatusQuery;
use App\Services\Payments\Exceptions\AmountOutOfRangeException;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Gateways\Fake\FakeGateway;
use App\Services\Payments\Gateways\Momo\MoMoSigner;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// QA bổ sung cho T17: các ca biên/âm tính chưa có trong MoMoGatewayTest. Helper đặt tên riêng (edge*)
// để file chạy độc lập.

const EDGE_SECRET = 'edge-secret-key-not-real';

beforeEach(function () {
    config([
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo.partner_code' => 'MOMOEDGE',
        'payments.gateways.momo.access_key' => 'EDGEACCESS9',
        'payments.gateways.momo.secret_key' => EDGE_SECRET,
        'payments.gateways.momo.endpoint' => 'https://test-payment.momo.vn',
        'payments.gateways.momo.pay_url_hosts' => ['test-payment.momo.vn'],
    ]);
});

function edgeReq(int $amount = 150000): PaymentRequest
{
    return new PaymentRequest('VV9-1', 'rid-9', $amount, 'Thanh toan don hang VV9', 'https://vitaminvui.vn/r', 'https://api.vitaminvui.vn/n', now()->addMinutes(30));
}

function edgeGw()
{
    return app(PaymentGatewayManager::class)->driver('momo');
}

/** @param  array<string, mixed>  $over */
function edgeIpn(array $over = []): array
{
    $p = array_merge([
        'partnerCode' => 'MOMOEDGE', 'orderId' => 'VV9-1', 'requestId' => 'rid-9', 'amount' => 150000,
        'orderInfo' => 'Thanh toan don hang VV9', 'orderType' => 'momo_wallet', 'transId' => 77,
        'resultCode' => 0, 'message' => 'Successful.', 'payType' => 'qr', 'responseTime' => 1790000000000, 'extraData' => '',
    ], $over);
    $p['signature'] = (new MoMoSigner)->sign(['accessKey' => 'EDGEACCESS9'] + $p, MoMoSigner::IPN_FIELDS, EDGE_SECRET);

    return $p;
}

function edgeJsonReq(string $body): Request
{
    return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
}

function edgeOkCreate(): array
{
    return ['orderId' => 'VV9-1', 'requestId' => 'rid-9', 'resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/p'];
}

// ---------- hạn mức: ranh giới ----------

test('han muc 1.000 - 50.000.000: bien trong duoc chap nhan', function (int $amount) {
    Http::fake(['*' => Http::response(edgeOkCreate())]);

    expect(edgeGw()->createPayment(edgeReq($amount))->payUrl)->toBe('https://test-payment.momo.vn/p');
    Http::assertSent(fn (HttpRequest $r) => $r->data()['amount'] === $amount);
})->with([1000, 1001, 49999999, 50000000]);

test('han muc: so tien 0 va am bi tu choi truoc khi goi mang', function (int $amount) {
    Http::fake();
    expect(fn () => edgeGw()->createPayment(edgeReq($amount)))->toThrow(AmountOutOfRangeException::class);
    Http::assertNothingSent();
})->with([0, -1, -150000]);

// ---------- hạ tầng: timeout/5xx/không phải JSON ----------

test('create: phan hoi khong phai JSON / redirect / 4xx khong JSON -> GatewayUnavailable', function (callable $make) {
    Http::fake(['*' => $make()]);
    expect(fn () => edgeGw()->createPayment(edgeReq()))->toThrow(GatewayUnavailableException::class);
    Http::assertSentCount(1);
})->with([
    '200 html' => fn () => fn () => Http::response('<html>oops</html>', 200),
    '200 rong' => fn () => fn () => Http::response('', 200),
    '302 redirect' => fn () => fn () => Http::response('', 302, ['Location' => 'https://evil.example']),
    '404 text' => fn () => fn () => Http::response('not found', 404),
    '502' => fn () => fn () => Http::response(['resultCode' => 0], 502),
    '200 json scalar' => fn () => fn () => Http::response('123', 200, ['Content-Type' => 'application/json']),
]);

test('create: 4xx kem JSON resultCode loi -> GatewayUnavailable (khong bao gio tra payUrl)', function () {
    Http::fake(['*' => Http::response(['resultCode' => 1001, 'message' => 'no', 'payUrl' => 'https://test-payment.momo.vn/p'], 400)]);
    expect(fn () => edgeGw()->createPayment(edgeReq()))->toThrow(GatewayUnavailableException::class, 'create_rejected_1001');
});

test('create: resultCode "0" dang chuoi + thieu payUrl -> invalid_pay_url', function () {
    Http::fake(['*' => Http::response(['orderId' => 'VV9-1', 'requestId' => 'rid-9', 'resultCode' => '0'])]);
    expect(fn () => edgeGw()->createPayment(edgeReq()))->toThrow(GatewayUnavailableException::class, 'invalid_pay_url');
});

test('create: payUrl khong phai chuoi (mang/so/null) bi tu choi', function (mixed $payUrl) {
    Http::fake(['*' => Http::response(['orderId' => 'VV9-1', 'requestId' => 'rid-9', 'resultCode' => 0, 'payUrl' => $payUrl])]);
    expect(fn () => edgeGw()->createPayment(edgeReq()))->toThrow(GatewayUnavailableException::class, 'invalid_pay_url');
})->with([[['https://test-payment.momo.vn/p']], [123], [null], ['']]);

test('create: payUrl port 443 tuong minh hop le; host viet hoa hop le', function () {
    Http::fake(['*' => Http::response(['orderId' => 'VV9-1', 'requestId' => 'rid-9', 'resultCode' => 0, 'payUrl' => 'https://TEST-Payment.momo.vn:443/p'])]);
    expect(edgeGw()->createPayment(edgeReq())->payUrl)->toBe('https://TEST-Payment.momo.vn:443/p');
});

test('create: mac dinh pay_url_hosts chi payment.momo.vn (khong cho host sandbox)', function () {
    $cfg = require base_path('config/payments.php');
    expect($cfg['gateways']['momo']['min_amount'])->toBe(1000)
        ->and($cfg['gateways']['momo']['max_amount'])->toBe(50000000);
    // Mặc định trong file config (khi không đặt MOMO_PAY_URL_HOSTS) chỉ có payment.momo.vn.
    expect(file_get_contents(base_path('config/payments.php')))->toContain("'MOMO_PAY_URL_HOSTS', 'payment.momo.vn'");
});

test('query: 5xx, khong JSON, redirect -> GatewayUnavailable', function (callable $make) {
    Http::fake(['*' => $make()]);
    expect(fn () => edgeGw()->queryStatus(new PaymentStatusQuery('VV9-1', 150000)))->toThrow(GatewayUnavailableException::class);
})->with([
    '500' => fn () => fn () => Http::response('x', 500),
    'html' => fn () => fn () => Http::response('<html>', 200),
    '301' => fn () => fn () => Http::response('', 301, ['Location' => 'https://evil.example']),
]);

// ---------- IPN: body bất thường (phải là InvalidSignature, không phải TypeError/500) ----------

test('IPN body khong phai JSON / rong / mang rong -> InvalidSignature', function (string $body) {
    expect(fn () => edgeGw()->parseNotification(edgeJsonReq($body)))->toThrow(InvalidSignatureException::class);
})->with(['rong' => '', 'rac' => '{not json', 'mang' => '[]', 'object rong' => '{}', 'chuoi' => '"abc"']);

test('IPN truong co kieu mang/bool/null hoac signature la mang -> InvalidSignature, khong loi khac', function (string $field, mixed $value) {
    $p = edgeIpn();
    $p[$field] = $value;
    expect(fn () => edgeGw()->parseNotification(edgeJsonReq(json_encode($p))))->toThrow(InvalidSignatureException::class);
})->with([
    ['orderId', ['a']], ['requestId', ['a']], ['partnerCode', ['a']], ['amount', ['1']], ['message', ['x']],
    ['signature', ['abc']], ['signature', 123], ['signature', null], ['resultCode', ['0']], ['transId', ['1']],
    ['amount', true], ['resultCode', false],
]);

test('IPN: orderId/requestId rong, message khong phai chuoi (da ky dung) bi tu choi', function (array $over) {
    expect(fn () => edgeGw()->parseNotification(edgeJsonReq(json_encode(edgeIpn($over)))))->toThrow(InvalidSignatureException::class, 'invalid_field');
})->with([
    'orderId rong' => [['orderId' => '']],
    'requestId rong' => [['requestId' => '']],
    'message so' => [['message' => 5]],
    'amount qua dai' => [['amount' => '1234567890123']],
]);

test('IPN: resultCode lech (thanh cong gia) chi hop le khi chu ky khop; ma 1005 danh dau expired, ma 0 thi khong', function () {
    $ok = edgeGw()->parseNotification(edgeJsonReq(json_encode(edgeIpn())));
    expect($ok->expired)->toBeFalse();

    $exp = edgeGw()->parseNotification(edgeJsonReq(json_encode(edgeIpn(['resultCode' => 1005]))));
    expect($exp->status)->toBe(GatewayPaymentStatus::Failed)->and($exp->expired)->toBeTrue();

    // Mã lạ theo nguồn: IPN -> Failed
    expect(edgeGw()->parseNotification(edgeJsonReq(json_encode(edgeIpn(['resultCode' => 31337]))))->status)->toBe(GatewayPaymentStatus::Failed);
});

test('IPN: ky bang secret khac (kiem tra timing-safe, khong nhan chu ky cua cong khac)', function () {
    $p = edgeIpn();
    $p['signature'] = (new MoMoSigner)->sign(['accessKey' => 'EDGEACCESS9'] + $p, MoMoSigner::IPN_FIELDS, 'secret-khac');
    expect(fn () => edgeGw()->parseNotification(edgeJsonReq(json_encode($p))))->toThrow(InvalidSignatureException::class, 'bad_signature');
});

test('IPN: tieng Viet co dau trong orderInfo/message duoc ky va doc dung; message dai bi cat 255', function () {
    $n = edgeGw()->parseNotification(edgeJsonReq(json_encode(edgeIpn(['orderInfo' => 'Thanh toán đơn hàng Toán lớp 9', 'message' => 'Giao dịch thành công.']), JSON_UNESCAPED_UNICODE)));
    expect($n->message)->toBe('Giao dịch thành công.');

    $long = edgeGw()->parseNotification(edgeJsonReq(json_encode(edgeIpn(['message' => str_repeat('a', 1000)]))));
    expect(mb_strlen($long->message))->toBeLessThanOrEqual(255);
});

test('IPN: truong la khong nam trong danh sach bi loai khoi raw', function () {
    $p = edgeIpn();
    $p['evil'] = '<script>alert(1)</script>';
    $n = edgeGw()->parseNotification(edgeJsonReq(json_encode($p)));
    expect($n->raw)->not->toHaveKey('evil')->and($n->raw)->not->toHaveKey('signature');
});

// ---------- query: orderId/requestId không khớp, mã lạ ----------

/** @param  array<string, mixed>  $over */
function edgeQueryResp(HttpRequest $r, array $over = []): array
{
    $p = array_merge(edgeIpn(['requestId' => $r->data()['requestId']]), $over);
    unset($p['signature']);
    $p['signature'] = (new MoMoSigner)->sign(['accessKey' => 'EDGEACCESS9'] + $p, MoMoSigner::IPN_FIELDS, EDGE_SECRET);

    return $p;
}

test('query: ma la (4242) -> Pending, khong Failed khong Succeeded', function () {
    Http::fake(fn (HttpRequest $r) => Http::response(edgeQueryResp($r, ['resultCode' => 4242])));
    expect(edgeGw()->queryStatus(new PaymentStatusQuery('VV9-1', 150000))->status)->toBe(GatewayPaymentStatus::Pending);
});

test('query: phan hoi dung chu ky nhung requestId la "rid-9" cua IPN cu bi tu choi', function () {
    // Kẻ replay phản hồi/IPN hợp lệ cũ: requestId không phải UUID vừa gửi.
    $old = edgeIpn();
    Http::fake(['*' => Http::response($old)]);
    expect(fn () => edgeGw()->queryStatus(new PaymentStatusQuery('VV9-1', 150000)))->toThrow(InvalidSignatureException::class, 'response_mismatch');
});

test('query: ket qua 0 nhung amount khac so tien don van duoc tra ve nguyen (caller T20 phai so sanh)', function () {
    Http::fake(fn (HttpRequest $r) => Http::response(edgeQueryResp($r, ['amount' => 1000])));
    $n = edgeGw()->queryStatus(new PaymentStatusQuery('VV9-1', 150000));
    expect($n->amount)->toBe(1000)->and($n->status)->toBe(GatewayPaymentStatus::Succeeded);
});

// ---------- log không lộ bí mật (ghi file thật) ----------

test('log channel payments (file that) khong chua secret/accessKey/signature/payUrl/orderInfo', function () {
    $path = sys_get_temp_dir().'/vv-payments-'.uniqid().'.log';
    config(['logging.channels.payments' => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
    Log::forgetChannel('payments');

    Http::fake(function (HttpRequest $r) {
        return str_contains($r->url(), '/query')
            ? Http::response(edgeQueryResp($r))
            : Http::response(edgeOkCreate() + ['signature' => 'SIGNATURE-VALUE-XYZ']);
    });

    edgeGw()->createPayment(edgeReq());
    edgeGw()->queryStatus(new PaymentStatusQuery('VV9-1', 150000));

    $bad = edgeIpn();
    $bad['signature'] = 'BADSIGNATURE-VALUE-XYZ';
    try {
        edgeGw()->parseNotification(edgeJsonReq(json_encode($bad)));
    } catch (InvalidSignatureException) {
    }
    // thất bại mạng cũng được log
    Http::fake(fn () => throw new ConnectionException('cURL error: secret '.EDGE_SECRET));
    try {
        edgeGw()->createPayment(edgeReq());
    } catch (GatewayUnavailableException) {
    }

    $log = (string) @file_get_contents($path);
    @unlink($path);

    expect($log)->not->toBe('')
        ->and($log)->not->toContain(EDGE_SECRET)
        ->and($log)->not->toContain('EDGEACCESS9')
        ->and($log)->not->toContain('SIGNATURE-VALUE-XYZ')
        ->and($log)->not->toContain('BADSIGNATURE-VALUE-XYZ')
        ->and($log)->not->toContain('test-payment.momo.vn/p')
        ->and($log)->not->toContain('Thanh toan don hang');
});

test('thong diep GatewayUnavailable/InvalidSignature khong chua secret', function () {
    Http::fake(fn () => throw new ConnectionException('boom '.EDGE_SECRET));
    try {
        edgeGw()->createPayment(edgeReq());
    } catch (GatewayUnavailableException $e) {
        expect($e->getMessage())->not->toContain(EDGE_SECRET);
    }

    $bad = edgeIpn();
    $bad['signature'] = 'x';
    try {
        edgeGw()->parseNotification(edgeJsonReq(json_encode($bad)));
    } catch (InvalidSignatureException $e) {
        expect($e->getMessage())->not->toContain(EDGE_SECRET)->and($e->getMessage())->not->toContain('EDGEACCESS9');
    }
});

// ---------- FakeGateway / manager theo môi trường ----------

test('production: fake khong resolve duoc du enabled_gateways co fake (ca driver ten, mac dinh, contract)', function () {
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(PaymentGatewayManager::class);
    config(['payments.enabled_gateways' => ['fake', 'momo']]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('fake'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(PaymentGatewayManager::class)->driver())->toThrow(InvalidArgumentException::class); // mặc định = fake
    expect(fn () => app(PaymentGateway::class))->toThrow(InvalidArgumentException::class);
    expect(app(PaymentGatewayManager::class)->driver('momo')->code())->toBe('momo');
});

test('staging: fake cung khong duoc dang ky (chi local/testing)', function () {
    app()->detectEnvironment(fn () => 'staging');
    app()->forgetInstance(PaymentGatewayManager::class);
    config(['payments.enabled_gateways' => ['fake']]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('fake'))->toThrow(InvalidArgumentException::class);
});

test('manager: allowlist rong, ten rong, ten lach hoa thuong', function () {
    config(['payments.enabled_gateways' => []]);
    expect(fn () => app(PaymentGatewayManager::class)->driver())->toThrow(InvalidArgumentException::class);

    config(['payments.enabled_gateways' => ['momo']]);
    expect(fn () => app(PaymentGatewayManager::class)->driver(''))->toThrow(InvalidArgumentException::class);
    expect(app(PaymentGatewayManager::class)->driver('MoMo')->code())->toBe('momo');
    expect(fn () => app(PaymentGatewayManager::class)->driver('momo/../fake'))->toThrow(InvalidArgumentException::class);
});

test('FakeGateway: IPN thieu truong, resultCode/amount khong phai so, chu ky thieu', function () {
    $gw = new FakeGateway;
    $req = fn (array $p) => edgeJsonReq(json_encode($p));

    $p = FakeGateway::ipnPayload('VV1-1', 'rid', 120000);
    unset($p['signature']);
    expect(fn () => $gw->parseNotification($req($p)))->toThrow(InvalidSignatureException::class);

    foreach (['amount', 'transId', 'requestId', 'gatewayOrderId', 'resultCode', 'message'] as $f) {
        $p = FakeGateway::ipnPayload('VV1-1', 'rid', 120000);
        unset($p[$f]);
        expect(fn () => $gw->parseNotification($req($p)))->toThrow(InvalidSignatureException::class);
    }

    $p = FakeGateway::ipnPayload('VV1-1', 'rid', 120000, 7000);
    expect($gw->parseNotification($req($p))->status)->toBe(GatewayPaymentStatus::Pending);

    $p = FakeGateway::ipnPayload('VV1-1', 'rid', 120000, 1006);
    expect($gw->parseNotification($req($p))->status)->toBe(GatewayPaymentStatus::Failed);
});

test('FakeGateway: query ket qua Failed va khong ro ri payload khac don', function () {
    FakeGateway::fakeQueryResult('VV2-1', 'r2', 5000, 1006);
    $gw = new FakeGateway;
    expect($gw->queryStatus(new PaymentStatusQuery('VV2-1', 5000))->status)->toBe(GatewayPaymentStatus::Failed)
        ->and($gw->queryStatus(new PaymentStatusQuery('VV3-1', 5000))->status)->toBe(GatewayPaymentStatus::Pending);
});
