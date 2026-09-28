<?php

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\User;
use App\Services\Privacy\ConsentService;
use Illuminate\Database\QueryException;

test('grant tao ban ghi consent voi policy_version hien hanh', function () {
    config(['privacy.policy_version' => '2026-09']);

    $user = User::factory()->create();
    $service = app(ConsentService::class);

    $consent = $service->grant($user, ConsentType::Terms, 'self', 'web_form', '127.0.0.1', 'PestAgent');

    expect($consent->user_id)->toBe($user->id);
    expect($consent->type)->toBe(ConsentType::Terms);
    expect($consent->policy_version)->toBe('2026-09');
    expect($consent->granted_by)->toBe('self');
    expect($consent->revoked_at)->toBeNull();
});

test('revoke dat revoked_at', function () {
    $user = User::factory()->create();
    $consent = Consent::factory()->for($user)->create();

    app(ConsentService::class)->revoke($consent);

    expect($consent->fresh()->revoked_at)->not->toBeNull();
});

/**
 * L3 (review docs/security/review-T03-FW1.md) — Consent là bằng chứng đồng ý,
 * chỉ được TẠO hoặc thu hồi (`revoked_at` từ null) — không được sửa trường
 * khác, không được xoá.
 */
test('sua truong khac ngoai revoked_at nem LogicException (L3)', function () {
    $consent = Consent::factory()->create();

    expect(fn () => $consent->update(['policy_version' => '2099-01']))->toThrow(LogicException::class);
});

test('xoa Consent nem LogicException (L3)', function () {
    $consent = Consent::factory()->create();

    expect(fn () => $consent->delete())->toThrow(LogicException::class);
});

test('doi revoked_at tu null sang gia tri thi duoc phep (L3)', function () {
    $consent = Consent::factory()->create(['revoked_at' => null]);

    $consent->update(['revoked_at' => now()]);

    expect($consent->fresh()->revoked_at)->not->toBeNull();
});

test('xoa user co consents bi chan boi FK restrictOnDelete (L3)', function () {
    $user = User::factory()->create();
    Consent::factory()->for($user)->create();

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});
