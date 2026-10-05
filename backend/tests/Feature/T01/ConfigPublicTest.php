<?php

test('config public tra ve allowlist tren host api voi cache-control public', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/config/public');

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'max-age=60, public');
    $response->assertJsonStructure([
        'referral_code_enabled',
        'quiz_time_limit_enabled',
        'otp' => ['ttl_minutes', 'resend_cooldown_seconds'],
        'grades',
        'captcha_site_key',
        'policy_version',
        'parent_consent_age',
    ]);

    // Đúng tên khoá theo ví dụ JSON ở api-contract §2.1 (không phải `cooldown_seconds`).
    $response->assertJsonPath('otp.resend_cooldown_seconds', config('auth.otp.cooldown_seconds'));
});

test('config public khong ton tai tren host admin-api', function () {
    $response = $this->getJson('http://'.config('app.admin_api_host').'/api/v1/config/public', [
        'Origin' => config('app.admin_url'),
    ]);

    $response->assertNotFound();
});

test('health tra ve 200 va khong lo phien ban', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/health');

    $response->assertOk();
    $response->assertJson(['status' => 'ok']);

    $body = $response->getContent();
    expect($body)->not->toContain('Laravel')
        ->and($body)->not->toContain(app()->version());
});

test('captcha_site_key la null (khong phai chuoi rong) khi chua cau hinh', function () {
    config(['services.turnstile.site_key' => '']);

    $this->getJson('http://'.config('app.api_host').'/api/v1/config/public')
        ->assertOk()
        ->assertJsonPath('captcha_site_key', null);
});
