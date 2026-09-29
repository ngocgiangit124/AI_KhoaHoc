<?php

use Illuminate\Support\Facades\Http;

/**
 * L3 (review bảo mật T17) — `payments:momo:verify-sandbox` không được tạo
 * giao dịch THẬT trên MoMo production dù `APP_ENV` không phải `production`
 * (ví dụ máy dev có `.env` chép nhầm từ production). Chặn theo HOST của
 * `MOMO_ENDPOINT`, không phụ thuộc `app()->isProduction()`.
 */
function verifyMomoSandboxConfig(string $endpoint = 'https://test-payment.momo.vn'): void
{
    config([
        'payments.enabled_gateways' => ['momo'],
        'payments.gateways.momo' => [
            'partner_code' => 'PARTNER',
            'access_key' => 'ACCESS',
            'secret_key' => 'SECRET',
            'endpoint' => $endpoint,
            'request_type' => 'captureWallet',
            'pay_url_hosts' => ['test-payment.momo.vn'],
        ],
    ]);
}

it('tu choi khi MOMO_ENDPOINT la host production, khong gui request nao (L3)', function () {
    Http::fake();

    verifyMomoSandboxConfig('https://payment.momo.vn');

    $this->artisan('payments:momo:verify-sandbox')->assertFailed();

    Http::assertNothingSent();
});

it('tu choi khi MOMO_ENDPOINT la host la, khong gui request nao (L3)', function () {
    Http::fake();

    verifyMomoSandboxConfig('https://evil.example');

    $this->artisan('payments:momo:verify-sandbox')->assertFailed();

    Http::assertNothingSent();
});

it('tu choi khi --amount khong phai so nguyen duong, khong gui request nao (L3)', function (string $amount) {
    Http::fake();

    verifyMomoSandboxConfig();

    $this->artisan('payments:momo:verify-sandbox', ['--amount' => $amount])->assertFailed();

    Http::assertNothingSent();
})->with(['-1', '0', 'abc', '1.5']);

it('chan khi dang o production, khong gui request nao (guard tang 1)', function () {
    Http::fake();

    app()->detectEnvironment(fn () => 'production');

    verifyMomoSandboxConfig();

    $this->artisan('payments:momo:verify-sandbox')->assertFailed();

    Http::assertNothingSent();
});
