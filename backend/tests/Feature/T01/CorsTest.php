<?php

test('preflight tu localhost:3000 toi host api duoc cho phep', function () {
    $response = $this->call('OPTIONS', 'http://'.config('app.api_host').'/api/v1/config/public', [], [], [], [
        'HTTP_Origin' => 'http://localhost:3000',
        'HTTP_Access-Control-Request-Method' => 'GET',
    ]);

    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
});

test('preflight tu admin.localhost:3001 toi host api KHONG duoc cho phep', function () {
    $response = $this->call('OPTIONS', 'http://'.config('app.api_host').'/api/v1/config/public', [], [], [], [
        'HTTP_Origin' => 'http://admin.localhost:3001',
        'HTTP_Access-Control-Request-Method' => 'GET',
    ]);

    // Server chỉ echo đúng 1 origin cấu hình cho host này (FRONTEND_URL) — trình
    // duyệt sẽ chặn vì Access-Control-Allow-Origin không khớp Origin của request.
    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('http://admin.localhost:3001');
});

test('preflight tu admin.localhost:3001 toi host admin-api duoc cho phep', function () {
    $response = $this->call('OPTIONS', 'http://'.config('app.admin_api_host').'/api/v1/csrf-token', [], [], [], [
        'HTTP_Origin' => 'http://admin.localhost:3001',
        'HTTP_Access-Control-Request-Method' => 'GET',
    ]);

    $response->assertHeader('Access-Control-Allow-Origin', 'http://admin.localhost:3001');
});

test('preflight tu localhost:3000 toi host admin-api KHONG duoc cho phep', function () {
    $response = $this->call('OPTIONS', 'http://'.config('app.admin_api_host').'/api/v1/csrf-token', [], [], [], [
        'HTTP_Origin' => 'http://localhost:3000',
        'HTTP_Access-Control-Request-Method' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('http://localhost:3000');
});

/**
 * T04 security review L3 — `Retry-After` (429 của throttle route lẫn
 * `DomainException TOO_MANY_ATTEMPTS` từ tầng Service) phải nằm trong
 * `Access-Control-Expose-Headers`, nếu không trình duyệt (fetch/XHR từ
 * `apps/web`, khác origin) sẽ KHÔNG đọc được header này dù server có trả.
 */
test('response tu host api co Access-Control-Expose-Headers chua Retry-After', function () {
    $response = $this->getJson('http://'.config('app.api_host').'/api/v1/config/public', [
        'Origin' => config('app.frontend_url'),
    ]);

    $exposed = (string) $response->headers->get('Access-Control-Expose-Headers');
    expect($exposed)->toContain('Retry-After');
});
