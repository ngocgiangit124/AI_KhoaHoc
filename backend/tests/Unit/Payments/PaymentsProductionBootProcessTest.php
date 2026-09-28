<?php

use Illuminate\Support\Facades\Process;

/**
 * Kiểm chứng end-to-end (chạy `php artisan` thật trong tiến trình con) rằng
 * `AppServiceProvider::boot()` → `PaymentsProductionGuard` thực sự chặn ứng
 * dụng khởi động khi cấu hình sai ở production — không chỉ test logic thuần
 * của guard (đã có ở `PaymentsProductionGuardTest`).
 */
it('APP_ENV=production + PAYMENT_GATEWAYS=fake → ứng dụng không boot (S4)', function () {
    $result = Process::path(base_path())->env([
        'APP_ENV' => 'production',
        'PAYMENT_GATEWAYS' => 'fake',
    ])->run('php artisan list --raw');

    expect($result->successful())->toBeFalse();
});

it('APP_ENV=production + momo sandbox/thiếu secret → ứng dụng không boot (S4)', function () {
    $result = Process::path(base_path())->env([
        'APP_ENV' => 'production',
        'PAYMENT_GATEWAYS' => 'momo',
        'MOMO_ENDPOINT' => 'https://test-payment.momo.vn',
        'MOMO_PARTNER_CODE' => '',
        'MOMO_ACCESS_KEY' => '',
        'MOMO_SECRET_KEY' => '',
    ])->run('php artisan list --raw');

    expect($result->successful())->toBeFalse();
});

it('APP_ENV=production + momo cấu hình hợp lệ (endpoint + secret đủ) → ứng dụng boot bình thường', function () {
    $result = Process::path(base_path())->env([
        'APP_ENV' => 'production',
        'PAYMENT_GATEWAYS' => 'momo',
        'MOMO_ENDPOINT' => 'https://payment.momo.vn',
        'MOMO_PARTNER_CODE' => 'X',
        'MOMO_ACCESS_KEY' => 'X',
        'MOMO_SECRET_KEY' => 'X',
    ])->run('php artisan list --raw');

    expect($result->successful())->toBeTrue();
});
