<?php

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\PaymentStatusQuery;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Gateways\Fake\FakeGateway;
use App\Services\Payments\Gateways\Momo\MoMoGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\ProductionConfigGuard;
use Illuminate\Http\Request;

test('manager resolve momo va fake trong allowlist', function () {
    config(['payments.enabled_gateways' => ['momo', 'fake']]);
    $m = app(PaymentGatewayManager::class);

    expect($m->driver('momo'))->toBeInstanceOf(MoMoGateway::class)
        ->and($m->driver('FAKE'))->toBeInstanceOf(FakeGateway::class)
        ->and($m->driver('momo')->code())->toBe('momo');
});

test('cong khong nam trong allowlist bi tu choi', function () {
    config(['payments.enabled_gateways' => ['momo']]);

    expect(fn () => app(PaymentGatewayManager::class)->driver('fake'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(PaymentGatewayManager::class)->driver('abc'))->toThrow(InvalidArgumentException::class);
});

test('contract PaymentGateway resolve ve cong mac dinh dau tien', function () {
    config(['payments.enabled_gateways' => ['fake']]);

    expect(app(PaymentGateway::class))->toBeInstanceOf(FakeGateway::class);
});

test('production: driver fake khong duoc dang ky va boot guard cam fake', function () {
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(PaymentGatewayManager::class);
    config(['payments.enabled_gateways' => ['fake']]);

    // Manager dựng lại ở production không có driver `fake`.
    expect(fn () => app(PaymentGatewayManager::class)->driver('fake'))->toThrow(InvalidArgumentException::class);

    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn'], 'app.trusted_proxies' => '10.0.0.1', 'app.static_url' => 'https://static.vitaminvui-media.net',
    ]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});

test('FakeGateway: IPN ky dung duoc chap nhan, chu ky sai bi tu choi', function () {
    $gw = new FakeGateway;
    $payload = FakeGateway::ipnPayload('VV1-1', 'rid', 120000);
    $req = fn (array $p) => Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($p));

    $n = $gw->parseNotification($req($payload));
    expect($n->status->value)->toBe('succeeded')->and($n->amount)->toBe(120000);

    $payload['amount'] = '1000';
    expect(fn () => $gw->parseNotification($req($payload)))->toThrow(InvalidSignatureException::class);
});

test('FakeGateway: createPayment va queryStatus', function () {
    $gw = new FakeGateway;
    $init = $gw->createPayment(new PaymentRequest('VV1-1', 'rid', 120000, 'Thanh toan don hang VV1', 'https://a', 'https://b', now()->addHour()));
    expect($init->payUrl)->toEndWith('/VV1-1');

    expect($gw->queryStatus(new PaymentStatusQuery('VV1-1', 120000))->status->value)->toBe('pending');

    FakeGateway::fakeQueryResult('VV1-1', 'rid', 120000, 0);
    expect($gw->queryStatus(new PaymentStatusQuery('VV1-1', 120000))->status->value)->toBe('succeeded');
});
