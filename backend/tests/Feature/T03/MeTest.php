<?php

use App\Models\User;

test('GET /auth/me tra ve user + is_verified + parent_consent_status + cart_count', function () {
    $user = User::factory()->create();

    $response = test()->actingAs($user)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $response->assertJson([
        'id' => $user->id,
        'is_verified' => false,
        'parent_consent_status' => 'not_required',
        'cart_count' => 0,
    ]);
    $response->assertJsonStructure(['parent_phone_masked', 'parent_email_masked']);
});

test('GET /auth/me che thong tin lien he phu huynh', function () {
    $user = User::factory()->minor()->create([
        'parent_phone' => '0987654321',
        'parent_email' => 'phuhuynh@example.com',
    ]);

    $response = test()->actingAs($user)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $json = $response->json();

    expect($json['parent_phone_masked'])->not->toBe('0987654321');
    expect($json['parent_phone_masked'])->toContain('*');
    expect($json['parent_email_masked'])->not->toBe('phuhuynh@example.com');
    expect($json['parent_email_masked'])->toContain('*');
    expect($json['parent_email_masked'])->toContain('@example.com');
    expect($json['parent_consent_status'])->toBe('pending');
});

/**
 * L4 (review docs/security/review-T03-FW1.md) — che theo độ dài CỐ ĐỊNH, độ
 * dài phần che không còn tỉ lệ với độ dài chuỗi gốc: chuỗi email/SĐT NGẮN
 * (1-2 ký tự phần local, SĐT 8 số) trước đây lộ gần hết nội dung thật.
 */
test('che email/SDT ngan van khong lo qua nhieu ky tu (L4)', function () {
    $user = User::factory()->minor()->create([
        // Phần local chỉ 2 ký tự — trước khi sửa: "ab*@x.com" (lộ "ab").
        'parent_email' => 'ab@x.com',
        // SĐT chỉ 8 số — trước khi sửa: lộ 5/8 chữ số.
        'parent_phone' => '09876543',
    ]);

    $response = test()->actingAs($user)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    $json = $response->json();

    expect($json['parent_email_masked'])->toBe('a***@x.com');
    expect($json['parent_phone_masked'])->not->toContain('98765');
    expect(substr_count((string) $json['parent_phone_masked'], '*'))->toBeGreaterThanOrEqual(5);
});

test('teacher khong the goi /auth/me tren host api (role:hoc_sinh)', function () {
    $teacher = User::factory()->teacher()->create();

    $response = test()->actingAs($teacher)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('thoi gian tra ve dang ISO 8601 co offset', function () {
    $user = User::factory()->verified()->create();

    $response = test()->actingAs($user)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertOk();
    expect($response->json('email_verified_at'))->toMatch('/[+\-]\d{2}:\d{2}$/');
});

test('response da xac thuc co Cache-Control no-store (S16)', function () {
    $user = User::factory()->create();

    $response = test()->actingAs($user)->getJson('http://'.config('app.api_host').'/api/v1/auth/me', [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertHeader('Cache-Control', 'no-store, private');
});
