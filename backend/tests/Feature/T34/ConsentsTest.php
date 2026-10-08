<?php

use App\Enums\ConsentType;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\User;

require_once __DIR__.'/helpers.php';

function vvT34Accept(array $payload = [])
{
    return test()->postJson(vvApiUrl('/me/consents/accept'), array_merge([
        'policy_version' => (string) config('privacy.policy_version'),
        'accept_terms' => true,
        'accept_privacy' => true,
    ], $payload), vvWebHeaders());
}

test('(a) GET /me/consents chi co dong cua minh, khong co ip/ua, sap granted_at giam dan', function () {
    $me = vvT34Student();
    $other = vvT34Create();
    vvT34Consent($me, ConsentType::Terms, '2026-09', ['granted_at' => now()->subDays(2)]);
    vvT34Consent($me, ConsentType::PrivacyPolicy, (string) config('privacy.policy_version'), ['granted_at' => now()->subDay()]);
    vvT34Consent($other, ConsentType::Terms);

    $r = test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertOk();

    expect($r->json('data'))->toHaveCount(2)
        ->and($r->json('data.0.type'))->toBe('privacy_policy')
        ->and($r->json('data.0.is_current_version'))->toBeTrue()
        ->and($r->json('data.1.is_current_version'))->toBeFalse()
        ->and(array_keys($r->json('data.0')))->toBe(['type', 'policy_version', 'granted_by', 'channel', 'granted_at', 'revoked_at', 'is_current_version'])
        ->and($r->json('meta.current_policy_version'))->toBe('2026-10-tam')
        ->and($r->json('meta.needs_acceptance'))->toBeTrue();
    expect($r->getContent())->not->toContain('203.0.113.5')->not->toContain('Pest');
    $r->assertHeader('Cache-Control', 'no-store, private');
});

test('(a) needs_acceptance = false khi dong y o phien ban hien hanh; /auth/me khop', function () {
    $me = vvT34Student();
    vvT34Consent($me, ConsentType::Terms);
    vvT34Consent($me, ConsentType::PrivacyPolicy);

    test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertOk()->assertJsonPath('meta.needs_acceptance', false);
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertOk()->assertJsonPath('needs_policy_acceptance', false);
});

test('(a) accept -> 2 dong moi, goi lai khong them; audit 1 lan; /auth/me het banner', function () {
    $me = vvT34Student();
    vvT34Consent($me, ConsentType::Terms, '2026-09');
    vvT34Consent($me, ConsentType::PrivacyPolicy, '2026-09');
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('needs_policy_acceptance', true);

    $r = vvT34Accept()->assertOk()->assertJsonPath('meta.needs_acceptance', false);
    expect($r->json('data'))->toHaveCount(4)
        ->and(Consent::query()->where('user_id', $me->id)->where('policy_version', '2026-10-tam')->count())->toBe(2)
        ->and(vvT34Audits('privacy.policy_accepted', $me->id))->toBe(1);

    $row = Consent::query()->where('user_id', $me->id)->where('policy_version', '2026-10-tam')->first();
    expect($row->granted_by)->toBe('self')->and($row->channel)->toBe('web_form')->and($row->ip)->not->toBeNull();

    vvT34Accept()->assertOk();
    expect(Consent::query()->where('user_id', $me->id)->count())->toBe(4)
        ->and(vvT34Audits('privacy.policy_accepted', $me->id))->toBe(1);
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('needs_policy_acceptance', false);

    $log = AuditLog::query()->where('action', 'privacy.policy_accepted')->where('subject_id', $me->id)->firstOrFail();
    expect($log->changes)->toBe(['policy_version' => '2026-10-tam']);
});

test('(a) accept chi ghi loai con thieu o phien ban hien hanh', function () {
    $me = vvT34Student();
    vvT34Consent($me, ConsentType::Terms); // da du terms o phien ban hien hanh

    vvT34Accept()->assertOk();

    expect(Consent::query()->where('user_id', $me->id)->where('type', 'terms')->count())->toBe(1)
        ->and(Consent::query()->where('user_id', $me->id)->where('type', 'privacy_policy')->count())->toBe(1);
});

test('(a) policy_version sai -> 409 CONSENT_VERSION_CHANGED + current_version, khong ghi gi', function () {
    $me = vvT34Student();

    vvT34Accept(['policy_version' => '2020-01'])->assertStatus(409)
        ->assertJsonPath('code', 'CONSENT_VERSION_CHANGED')
        ->assertJsonPath('errors.current_version', '2026-10-tam');

    expect(Consent::query()->where('user_id', $me->id)->count())->toBe(0);
});

test('(a) thieu tick hoac policy_version -> 422 dung field', function () {
    vvT34Student();

    vvT34Accept(['accept_terms' => false])->assertStatus(422)->assertJsonPath('errors.accept_terms.0', 'Bạn cần đồng ý với Điều khoản sử dụng.');
    vvT34Accept(['accept_privacy' => false])->assertStatus(422)->assertJsonPath('errors.accept_privacy.0', 'Bạn cần đồng ý với Chính sách bảo mật.');
    test()->postJson(vvApiUrl('/me/consents/accept'), ['accept_terms' => true, 'accept_privacy' => true], vvWebHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('policy_version');
});

test('(a) can dang nhap va dung vai tro hoc sinh', function () {
    test()->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertStatus(401);
    test()->postJson(vvApiUrl('/me/consents/accept'), [], vvWebHeaders())->assertStatus(401);

    $teacher = User::factory()->teacher()->create();
    test()->actingAs($teacher)->getJson(vvApiUrl('/me/consents'), vvWebHeaders())->assertStatus(403);
});
