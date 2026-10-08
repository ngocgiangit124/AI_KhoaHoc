<?php

use App\Enums\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

function vvFa11QaChangedRole(): array
{
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Bio công khai', 'headline' => 'Toán'])->assertOk();
    vvT36Upload('/admin/me/teacher-profile/avatar', UploadedFile::fake()->image('a.jpg', 300, 300))->assertOk();
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => (string) config('teacher_profile.consent_version')])->assertOk();
    vvT36PublishedCourse($t);
    vvT36SetUser($t, ['role' => UserRole::PageManager->value]);
    vvStaffLogin($t->fresh());

    return [$t, vvT36Profile($t)->avatar_path];
}

test('FA11-1 QA: chi con anh (da rut dong y) -> GET 200 consent=false, avatar_url co; xoa anh duoc', function () {
    [$t] = vvFa11QaChangedRole();
    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();

    $r = vvT36Json('GET', '/admin/me/teacher-profile')->assertOk();
    expect($r->json('consent.given'))->toBeFalse()->and($r->json('avatar_url'))->not->toBeNull();

    vvT36Json('DELETE', '/admin/me/teacher-profile/avatar')->assertOk();
    expect(Storage::disk('uploads')->allFiles())->toBe([])->and(vvT36Profile($t)->avatar_path)->toBeNull();
});

test('FA11-1 QA: chi con dong y (da xoa anh) -> GET 200 consent=true, avatar_url null; rut duoc', function () {
    [$t] = vvFa11QaChangedRole();
    vvT36Json('DELETE', '/admin/me/teacher-profile/avatar')->assertOk();

    $r = vvT36Json('GET', '/admin/me/teacher-profile')->assertOk();
    expect($r->json('consent.given'))->toBeTrue()->and($r->json('avatar_url'))->toBeNull();

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk()->assertJsonPath('consent.given', false);
    expect(vvT36Profile($t)->public_consent_at)->toBeNull();
});

test('FA11-1 QA: nguoi doi vai tro khong hien o /home/teachers; sau khi xoa anh URL anh cu khong con tren dia', function () {
    [$t, $old] = vvFa11QaChangedRole();
    expect(collect(vvT36Public('/home/teachers')->json('data'))->pluck('id'))->not->toContain($t->id);

    vvT36Json('DELETE', '/admin/me/teacher-profile/avatar')->assertOk();
    Storage::disk('uploads')->assertMissing($old);
});

test('FA11-1 QA: khach chua dang nhap GET /me -> 401', function () {
    vvT36Env();
    vvT36Json('GET', '/admin/me/teacher-profile')->assertUnauthorized();
});
