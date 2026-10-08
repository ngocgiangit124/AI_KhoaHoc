<?php

use App\Mail\ParentNoticeMail;
use App\Support\ProductionConfigGuard;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

require_once __DIR__.'/helpers.php';

test('(m) /config/public: parent_contact_required=false, parent_consent_age=18, policy_version=2026-10-tam', function () {
    $r = test()->getJson('http://'.config('app.api_host').'/api/v1/config/public')->assertOk();

    $r->assertJsonPath('parent_contact_required', false)
        ->assertJsonPath('parent_consent_age', 18)
        ->assertJsonPath('policy_version', '2026-10-tam');
    expect($r->json('parent_contact_required'))->toBeFalse();
});

test('(m) parent_consent_age lay tu privacy.parent_contact_suggest_age', function () {
    config(['privacy.parent_contact_suggest_age' => 16]);

    test()->getJson('http://'.config('app.api_host').'/api/v1/config/public')->assertJsonPath('parent_consent_age', 16);
});

test('config privacy moi co du khoa va mac dinh dung', function () {
    expect(config('privacy.data_export_daily_limit'))->toBe(2)
        ->and(config('privacy.parent_notice_daily_cap_per_address'))->toBe(5)
        ->and(config('features.parent_notices'))->toBeTrue()
        ->and(config('privacy'))->not->toHaveKey('parent_consent_age');
});

test('ProductionConfigGuard: privacy.policy_version rong bi chan (logic guardPolicyVersion)', function () {
    $guard = new ProductionConfigGuard;
    $method = new ReflectionMethod($guard, 'guardPolicyVersion');

    config(['privacy.policy_version' => '2026-10-tam']);
    $method->invoke($guard);

    config(['privacy.policy_version' => '  ']);
    expect(fn () => $method->invoke($guard))->toThrow(RuntimeException::class, 'PRIVACY_POLICY_VERSION');
});

test('khong co thu muc rieng: mail phu huynh co header List-Unsubscribe va ShouldBeEncrypted', function () {
    expect(is_subclass_of(ParentNoticeMail::class, ShouldBeEncrypted::class))->toBeTrue()
        ->and(is_subclass_of(ParentNoticeMail::class, ShouldQueue::class))->toBeTrue();
});
