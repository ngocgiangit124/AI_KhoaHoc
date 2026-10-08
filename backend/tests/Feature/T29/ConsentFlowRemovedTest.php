<?php

use App\Enums\ConsentType;
use App\Enums\OtpPurpose;
use App\Enums\ParentConsentStatus;
use App\Http\Middleware\EnsureParentConsent;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/helpers.php';

test('(e) hoc sinh co du lieu cu pending/revoked khong con bi 403 PARENT_CONSENT_REQUIRED o preview, checkout 0d va xin hoc mien phi', function (ParentConsentStatus $status) {
    config(['payments.enabled_gateways' => ['fake']]);

    $student = User::factory()->student()->verified()->create();
    $student->forceFill(['parent_consent_status' => $status])->save();
    vvActAsStudent($student);

    test()->getJson(vvApiUrl('/checkout/preview'), vvWebHeaders())->assertOk();
    test()->postJson(vvApiUrl('/checkout'), ['expected_total' => 0, 'gateway' => 'fake'], vvWebHeaders())
        ->assertStatus(422)->assertJsonPath('code', 'CART_EMPTY'); // qua middleware, toi tan nghiep vu

    $course = Course::factory()->published()->create();
    test()->postJson(vvApiUrl("/courses/{$course->id}/free-enrollments"), [], vvWebHeaders())
        ->assertCreated()->assertJsonPath('status', 'pending_approval');
})->with([ParentConsentStatus::Pending, ParentConsentStatus::Revoked]);

test('(e) kien truc: khong route nao mang alias parent.consent; EnsureParentConsent, OtpPurpose::ParentConsent, co parent_consent_enforced khong con', function () {
    foreach (Route::getRoutes() as $route) {
        expect($route->gatherMiddleware())->not->toContain('parent.consent');
    }

    expect(class_exists(EnsureParentConsent::class))->toBeFalse()
        ->and(OtpPurpose::tryFrom('parent_consent'))->toBeNull()
        ->and(config('features'))->not->toHaveKey('parent_consent_enforced')
        ->and(app('router')->getMiddleware())->not->toHaveKey('parent.consent')
        ->and(file_exists(app_path('Services/Privacy/ParentConsentService.php')))->toBeFalse()
        ->and(file_exists(app_path('Mail/ParentConsentMail.php')))->toBeFalse();
});

test('khong co route phu huynh dong y / gui lai / rut', function () {
    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->implode("\n");

    expect($uris)->not->toContain('parent-consents')->not->toContain('parent-consent/resend')->not->toContain('/revoke');
});

test('enum ParentConsentStatus va ConsentType::ParentConsent con giu (tuong thich v1)', function () {
    expect(ParentConsentStatus::NotRequired->value)->toBe('not_required')
        ->and(ConsentType::ParentConsent->value)->toBe('parent_consent');
});
