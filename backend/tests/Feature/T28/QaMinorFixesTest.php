<?php

use App\Models\Course;

require_once __DIR__.'/helpers.php';

if (! function_exists('vvAdminPasswordPayload')) {
    function vvAdminPasswordPayload(string $current = 'password', string $new = 'mat-khau-moi-123'): array
    {
        return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new];
    }
}

// QA gom sửa lỗi nhỏ (minor-fixes-1): phiên cũ, khách thiếu Accept, message validation tiếng Việt (host admin).

test('QA minor-fixes: admin phien cu - route can dang nhap bi 401 envelope, csrf va login van 200', function () {
    $teacher = vvStaffUser('teacher');
    $oldCookie = vvAdminFollow(vvAdminLogin((string) $teacher->email)->assertOk());
    app('auth')->forgetGuards();
    test()->putJson(vvAdminUrl('/admin/auth/password'), vvAdminPasswordPayload(), vvAdminHeaders())->assertOk();

    vvAdminUseCookie($oldCookie);
    $me = vvAdminGet('/admin/auth/me')->assertUnauthorized();
    expect($me->json('code'))->toBeIn(['SESSION_REVOKED', 'UNAUTHENTICATED'])->and($me->json())->toHaveKeys(['code', 'message']);

    // Sau 401 (flush phiên) hoặc dùng lại cookie cũ: cửa vào vẫn mở.
    vvAdminUseCookie($oldCookie);
    vvAdminGet('/csrf-token')->assertOk();
    vvAdminUseCookie($oldCookie);
    vvAdminLogin((string) $teacher->email, 'mat-khau-moi-123')->assertOk();
});

test('QA minor-fixes: khach thieu Accept goi route can dang nhap tren host admin -> 401 JSON envelope, khong 500/redirect', function () {
    foreach (['/admin/auth/me', '/admin/courses', '/admin/coupons', '/admin/enrollment-requests'] as $path) {
        $r = test()->get(vvAdminUrl($path), vvAdminHeaders());
        expect($r->status())->toBe(401, $path);
        $r->assertHeader('Content-Type', 'application/json');
        expect($r->json('code'))->toBe('UNAUTHENTICATED');
        expect($r->headers->has('Location'))->toBeFalse();
    }
    // Header Accept: text/html cũng không được redirect.
    $r = test()->get(vvAdminUrl('/admin/auth/me'), vvAdminHeaders(['Accept' => 'text/html']));
    expect($r->status())->toBe(401)->and($r->json('code'))->toBe('UNAUTHENTICATED');
});

test('QA minor-fixes: khach thieu Accept tren host api -> 401 envelope', function () {
    foreach (['/auth/me'] as $path) {
        $r = test()->get(vvApiUrl($path), vvWebHeaders());
        expect($r->status())->toBe(401, $path)->and($r->json('code'))->toBe('UNAUTHENTICATED');
        expect($r->headers->has('Location'))->toBeFalse();
    }
    $r = test()->get(vvApiUrl('/auth/me'), vvWebHeaders(['Accept' => 'text/html']));
    expect($r->status())->toBe(401);
    // Route ghi (PUT) không Accept JSON.
    $r = test()->put(vvApiUrl('/auth/contact'), ['email' => 'a@example.com'], vvWebHeaders());
    expect($r->status())->toBe(401)->and($r->json('code'))->toBe('UNAUTHENTICATED');
});

test('QA minor-fixes: message validation tieng Viet - coupon admin (body rong)', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $r = test()->postJson(vvAdminUrl('/admin/coupons'), [], vvAdminHeaders())->assertStatus(422);
    $errors = $r->json('errors');
    expect($errors)->not->toBeEmpty();
    foreach ($errors as $field => $messages) {
        foreach ($messages as $m) {
            expect($m)->not->toMatch('/must be|is required|field is|The /i', "{$field}: {$m}");
            expect($m)->not->toContain('validation.');
        }
    }
});

test('QA minor-fixes: message validation tieng Viet - chapter (tieu de rong va qua dai)', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $course = Course::factory()->create();
    $r = test()->postJson(vvAdminUrl("/admin/courses/{$course->id}/chapters"), ['title' => ''], vvAdminHeaders())->assertStatus(422);
    $all = collect($r->json('errors'))->flatten()->implode(' | ');
    expect($all)->not->toMatch('/must be|is required|field is|The /i')->and($all)->not->toContain('validation.')->and($all)->not->toBe('');

    $r = test()->postJson(vvAdminUrl("/admin/courses/{$course->id}/chapters"), ['title' => str_repeat('á', 400)], vvAdminHeaders())->assertStatus(422);
    $all = collect($r->json('errors'))->flatten()->implode(' | ');
    expect($all)->toContain('ký tự')->and($all)->not->toMatch('/characters|may not/i');
});
