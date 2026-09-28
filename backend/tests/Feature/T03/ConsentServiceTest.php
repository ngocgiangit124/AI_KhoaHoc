<?php

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\User;
use App\Services\Privacy\ConsentService;

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
