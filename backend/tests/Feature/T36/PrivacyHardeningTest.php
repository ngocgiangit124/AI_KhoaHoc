<?php

use App\Enums\UserRole;
use App\Mail\TeacherProfileEditedByStaffMail;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ImageUploadService;
use App\Services\Teachers\HomepageTeacherQuery;
use App\Services\Teachers\TeacherEligibility;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

function vvT36PrivVersion(): string
{
    return (string) config('teacher_profile.consent_version');
}

/** Giáo viên đăng nhập, đã có ảnh, bio, đồng ý. */
function vvT36PrivConsentedTeacher(): array
{
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Bio công khai', 'headline' => 'Toán'])->assertOk();
    vvT36Upload('/admin/me/teacher-profile/avatar', UploadedFile::fake()->image('a.jpg', 300, 300))->assertOk();
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => vvT36PrivVersion()])->assertOk();

    return [$t, vvT36Profile($t)->avatar_path];
}

// ---------------------------------------------------------------- M1: rút đồng ý thì URL ảnh cũ hết hiệu lực

test('M1: rut dong y -> file cu khong con tren dia, avatar_path doi sang UUID moi (noi dung anh giu nguyen), API cong khai null', function () {
    [$t, $old] = vvT36PrivConsentedTeacher();
    $course = vvT36PublishedCourse($t);
    $bytes = Storage::disk('uploads')->get($old);
    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.avatar_url'))->toContain($old);

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk()->assertJsonPath('consent.given', false);

    Storage::disk('uploads')->assertMissing($old);
    $new = vvT36Profile($t)->avatar_path;
    expect($new)->not->toBe($old)->and($new)->toMatch('/^[0-9a-f-]{36}\.webp$/');
    Storage::disk('uploads')->assertExists($new);
    expect(Storage::disk('uploads')->get($new))->toBe($bytes);
    expect(Storage::disk('uploads')->allFiles())->toBe([$new]);

    // API công khai: không còn URL nào (cũ lẫn mới).
    $pub = vvT36Public('/courses/'.$course->slug)->assertOk();
    expect($pub->json('teachers.0.avatar_url'))->toBeNull()->and($pub->getContent())->not->toContain($old)->not->toContain($new);

    // Hồ sơ của tôi: giáo viên vẫn thấy ảnh (URL mới), đồng ý lại thì công khai URL MỚI.
    expect(vvT36Json('GET', '/admin/me/teacher-profile')->json('avatar_url'))->toContain($new);
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => vvT36PrivVersion()])->assertOk();
    $again = vvT36Public('/courses/'.$course->slug)->json('teachers.0.avatar_url');
    expect($again)->toContain($new)->not->toContain($old);
});

test('M1: rut dong y khong co anh -> khong tao file; rut khi chua dong y -> khong doi ten anh', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Upload('/admin/me/teacher-profile/avatar', UploadedFile::fake()->image('a.jpg', 100, 100))->assertOk();
    $path = vvT36Profile($t)->avatar_path;

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();
    expect(vvT36Profile($t)->avatar_path)->toBe($path)->and(Storage::disk('uploads')->allFiles())->toBe([$path]);
});

test('M1: khong sao chep duoc anh (file goc mat) -> van rut dong y, ghi log loi, giu duong dan cu', function () {
    [$t, $old] = vvT36PrivConsentedTeacher();
    Storage::disk('uploads')->delete($old); // mô phỏng file gốc mất/ổ đĩa lỗi
    Log::spy();

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk()->assertJsonPath('consent.given', false);

    $p = vvT36Profile($t);
    expect($p->public_consent_at)->toBeNull()->and($p->avatar_path)->toBe($old);
    Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'URL cũ vẫn còn hiệu lực'))->once();
});

test('M1: loi trong transaction khi rut dong y -> file moi bi xoa, file cu va tham chieu giu nguyen', function () {
    [$t, $old] = vvT36PrivConsentedTeacher();

    app()->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function log(string $action, ?Model $subject = null, array $changes = []): AuditLog
        {
            throw new RuntimeException('audit down');
        }
    });

    expect(fn () => app(TeacherProfileService::class)->withdrawConsent($t))->toThrow(RuntimeException::class, 'audit down');

    expect(vvT36Profile($t)->avatar_path)->toBe($old)->and(vvT36Profile($t)->public_consent_at)->not->toBeNull();
    expect(Storage::disk('uploads')->allFiles())->toBe([$old]);
});

test('M1: ImageUploadService::delete khong nem loi khi disk loi, chi ghi log warning (images:prune-orphans don lai)', function () {
    Log::spy();
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('disk down'));

    (new ImageUploadService)->delete((string) Str::uuid().'.webp');

    Log::shouldHaveReceived('warning')->once();
});

test('M1: rut dong y ghi consents.revoked_at + audit nhu cu (khong doi hanh vi khac)', function () {
    [$t] = vvT36PrivConsentedTeacher();

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();

    expect(Consent::query()->where('user_id', $t->id)->whereNull('revoked_at')->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'teacher_profile.consent_withdraw')->where('subject_id', $t->id)->count())->toBe(1);
});

// ---------------------------------------------------------------- L1

test('L1: nguoi da doi vai tro nhung con ho so tu rut dong y va xoa anh cua minh duoc; khong dong y/sua noi dung duoc', function () {
    [$t, $old] = vvT36PrivConsentedTeacher();
    vvT36SetUser($t, ['role' => UserRole::PageManager->value]);
    // Phiên hiện hành vẫn của người này (đã là QLT): đăng nhập lại cho đúng vai trò mới.
    vvStaffLogin($t->fresh());

    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => vvT36PrivVersion()])->assertForbidden();
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'x'])->assertForbidden();

    $r = vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();
    expect($r->json('consent.given'))->toBeFalse()->and($r->json('abilities'))->toBe(['edit_content' => false, 'consent' => false, 'manage_homepage' => false]);
    expect(Consent::query()->where('user_id', $t->id)->whereNull('revoked_at')->count())->toBe(0);
    Storage::disk('uploads')->assertMissing($old);

    $new = vvT36Profile($t)->avatar_path;
    vvT36Json('DELETE', '/admin/me/teacher-profile/avatar')->assertOk()->assertJsonPath('avatar_url', null);
    Storage::disk('uploads')->assertMissing($new);
    expect(vvT36Profile($t)->avatar_path)->toBeNull();
});

test('FA11-1: GET /me cua nguoi da doi vai tro con dong ho so -> 200, abilities chi doc, role/reasons dung, khong lo them', function () {
    [$t] = vvT36PrivConsentedTeacher();
    vvT36SetUser($t, ['role' => UserRole::PageManager->value]);
    vvStaffLogin($t->fresh());

    $r = vvT36Json('GET', '/admin/me/teacher-profile')->assertOk();
    expect($r->json('user.role'))->toBe('quan_ly_trang')
        ->and($r->json('consent.given'))->toBeTrue()
        ->and($r->json('avatar_url'))->not->toBeNull()
        ->and($r->json('abilities'))->toBe(['edit_content' => false, 'consent' => false, 'manage_homepage' => false])
        ->and($r->json('homepage_status.visible'))->toBeFalse()
        ->and($r->json('homepage_status.reasons'))->toContain('not_teacher')
        ->and(array_keys($r->json()))->not->toContain('email', 'phone');
    expect(json_encode($r->json()))->not->toContain('@');
});

test('FA11-1: nguoi doi vai tro da rut dong y va xoa anh nhung dong van con -> GET 200 rong; khong tao dong khi GET', function () {
    [$t] = vvT36PrivConsentedTeacher();
    vvT36SetUser($t, ['role' => UserRole::PageManager->value]);
    vvStaffLogin($t->fresh());
    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();
    vvT36Json('DELETE', '/admin/me/teacher-profile/avatar')->assertOk();

    $r = vvT36Json('GET', '/admin/me/teacher-profile')->assertOk();
    expect($r->json('consent.given'))->toBeFalse()->and($r->json('avatar_url'))->toBeNull()
        ->and($r->json('abilities.consent'))->toBeFalse()->and($r->json('abilities.edit_content'))->toBeFalse();
    expect(vvT36Profile($t))->not->toBeNull();
});

test('FA11-1: GET /me khong tao dong cho nguoi chua tung co ho so (admin, QLT) -> 403; hoc sinh/khach bi chan', function (string $state) {
    vvT36Env();
    vvT36Login($state);

    vvT36Json('GET', '/admin/me/teacher-profile')->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    expect(TeacherProfile::query()->count())->toBe(0);
})->with(['admin', 'pageManager']);

test('L1: user khong co dong ho so (admin, QLT, hoc sinh) van 403 o rut dong y/xoa anh', function (string $state) {
    vvT36Env();
    vvT36Login($state);

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertForbidden();
    vvT36Json('DELETE', '/admin/me/teacher-profile/avatar')->assertForbidden();
})->with(['admin', 'pageManager']);

test('L1: Admin/QLT xoa anh ho nguoi da doi vai tro (con ho so, co trang chu da tat); ho so xuat hien o danh sach', function () {
    vvT36Env();
    $ex = User::factory()->teacher()->withPublicProfile()->create(['name' => 'Cựu GV']);
    $path = vvT36Profile($ex)->avatar_path;
    Storage::disk('uploads')->put($path, 'x');
    vvT36SetUser($ex, ['role' => UserRole::PageManager->value]);
    vvT36Login('pageManager');

    expect(collect(vvT36Json('GET', '/admin/teacher-profiles?q=Cựu')->json('data'))->pluck('user.id')->all())->toBe([$ex->id]);
    vvT36Json('GET', '/admin/teacher-profiles/'.$ex->id)->assertOk()->assertJsonPath('user.role', 'quan_ly_trang');
    vvT36Json('DELETE', '/admin/teacher-profiles/'.$ex->id.'/avatar')->assertOk()->assertJsonPath('avatar_url', null);

    Storage::disk('uploads')->assertMissing($path);
    // Người không có dòng hồ sơ vẫn 404.
    $none = User::factory()->pageManager()->create();
    vvT36Json('GET', '/admin/teacher-profiles/'.$none->id)->assertNotFound();
});

// ---------------------------------------------------------------- email báo khi staff sửa hộ

test('admin sua ho khi giao vien DANG dong y -> email xep hang cho giao vien (khong chua toan van bio), kem ten truong', function () {
    vvT36Env();
    Mail::fake();
    $t = User::factory()->teacher()->withPublicProfile()->create(['name' => 'Cô Lan']);
    $admin = vvT36Login('admin', ['name' => 'Quản Trị Viên']);

    vvT36Json('PATCH', '/admin/teacher-profiles/'.$t->id, ['bio' => 'NOI-DUNG-BIO-BI-MAT', 'headline' => 'Dòng mới'])->assertOk();

    Mail::assertQueued(TeacherProfileEditedByStaffMail::class, function (TeacherProfileEditedByStaffMail $m) use ($t) {
        $html = $m->render();

        return $m->hasTo($t->email)
            && $m->fieldLabels === ['dòng chuyên môn', 'phần giới thiệu']
            && str_contains($html, 'Cô Lan') && str_contains($html, 'Quản Trị Viên') && str_contains($html, 'phần giới thiệu')
            && ! str_contains($html, 'NOI-DUNG-BIO-BI-MAT') && ! str_contains($html, 'Dòng mới');
    });

    vvT36Upload('/admin/teacher-profiles/'.$t->id.'/avatar', UploadedFile::fake()->image('a.jpg', 100, 100))->assertOk();
    vvT36Json('DELETE', '/admin/teacher-profiles/'.$t->id.'/avatar')->assertOk();
    Mail::assertQueuedCount(3);
    expect($admin->id)->not->toBe($t->id);
});

test('khong gui email khi: giao vien chua/da rut dong y, tu sua, khong co thay doi, khong co email', function () {
    vvT36Env();
    Mail::fake();
    $noConsent = User::factory()->teacher()->create();
    $consented = User::factory()->teacher()->withPublicProfile()->create();
    $noEmail = User::factory()->teacher()->withPublicProfile()->create(['email' => null, 'phone' => '0911111111']);
    vvT36Login('admin');

    vvT36Json('PATCH', '/admin/teacher-profiles/'.$noConsent->id, ['bio' => 'A'])->assertOk();
    vvT36Json('PATCH', '/admin/teacher-profiles/'.$noEmail->id, ['bio' => 'B'])->assertOk();
    $same = vvT36Profile($consented)->bio;
    vvT36Json('PATCH', '/admin/teacher-profiles/'.$consented->id, ['bio' => $same])->assertOk(); // không đổi
    Mail::assertNotQueued(TeacherProfileEditedByStaffMail::class);

    // Giáo viên tự sửa: không gửi.
    vvStaffLogin($consented);
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Tự sửa'])->assertOk();
    Mail::assertNotQueued(TeacherProfileEditedByStaffMail::class);
});

test('loi gui email khong lam hong thao tac sua ho (log warning)', function () {
    vvT36Env();
    $t = User::factory()->teacher()->withPublicProfile()->create();
    vvT36Login('admin');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    Log::spy();

    vvT36Json('PATCH', '/admin/teacher-profiles/'.$t->id, ['bio' => 'Mới'])->assertOk();

    expect(vvT36Profile($t)->bio)->toBe('Mới');
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'email báo giáo viên'))->once();
});

// ---------------------------------------------------------------- I3

test('I3: noi dung file la DUONG DAN toi anh that khong bi doc nhu file (chi giai ma nhi phan) -> ValidationException', function () {
    Storage::fake('uploads');
    $real = UploadedFile::fake()->image('real.png', 50, 50);
    $path = $real->getRealPath();
    expect(is_file($path))->toBeTrue();
    $pathAsContent = new UploadedFile(vvT36TmpFile($path), 'a.png', 'image/png', null, true);

    expect(fn () => app(ImageUploadService::class)->storeWebp($pathAsContent, 'avatar', 800))->toThrow(ValidationException::class);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);
});

// ---------------------------------------------------------------- L2: normalize + "có bio" nhìn thấy được

test('L2: bio chi gom khoang trang Unicode (NBSP, U+3000) -> luu null; dong trong toi da 2; trailing space moi dong bi cat', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => "\u{00A0}\u{3000}  \u{2003}"])->assertOk()->assertJsonPath('bio', null);

    $r = vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => "Đoạn 1   \n\n\n\n\n\nĐoạn 2\u{00A0}\n \n \n \n \nĐoạn 3"])->assertOk();
    expect($r->json('bio'))->toBe("Đoạn 1\n\n\nĐoạn 2\n\n\nĐoạn 3");
    expect(vvT36Profile($t)->bio)->toBe("Đoạn 1\n\n\nĐoạn 2\n\n\nĐoạn 3");
});

test('L2: bio/headline chi gom ky tu an khong bi luu: TrimStrings cua framework gom U+3164/U+2800/U+200E ve rong (xoa), con lai 422', function () {
    vvT36Env();
    vvT36Login('teacher');
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Có bio', 'headline' => 'Có headline'])->assertOk();

    foreach (["\u{3164}", "\u{2800}\u{2800}", "\u{200E}"] as $hidden) {
        // Framework cắt các ký tự này ở hai đầu → chuỗi rỗng → null (không bao giờ lưu ký tự ẩn).
        vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => $hidden])->assertOk()->assertJsonPath('bio', null);
        vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Có bio'])->assertOk();
    }

    foreach (["\u{E0041}\u{E0042}", "a\u{0300}\u{0301}\u{0302}\u{0303}", "x\u{3164}y", "x\u{200E}y", "\u{3164}x\u{2800}y\u{3164}"] as $bad) {
        vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => $bad])->assertStatus(422)->assertJsonValidationErrors(['bio']);
        vvT36Json('PATCH', '/admin/me/teacher-profile', ['headline' => $bad])->assertStatus(422)->assertJsonValidationErrors(['headline']);
    }
});

dataset('vvT36BlankBios', [
    'chi NBSP' => ["\u{00A0}\u{00A0}"],
    'chi U+3000' => ["\u{3000}"],
    'chi Hangul filler' => ["\u{3164}"],
    'chi braille blank' => ["\u{2800}"],
    'chi LRM/zero-width' => ["\u{200E}\u{200B}\u{2060}"],
    'khoang trang + filler' => [" \u{3164} \n \u{2800}"],
    'rong' => [''],
    'dau cach' => ['   '],
]);

test('L2: "co bio" tinh tren noi dung nhin thay duoc: SQL REGEXP khop TeacherEligibility (du lieu seed tho)', function (string $bio) {
    config(['teacher_profile.homepage_max' => 500]);
    $good = vvT36EligibleTeacher(['name' => 'Đối chứng']);
    $t = vvT36EligibleTeacher(['name' => 'Bio ẩn']);
    vvT36SetProfile($t, ['bio' => $bio]);

    $visible = collect((new HomepageTeacherQuery)->get())->pluck('user.id')->all();
    $reasons = TeacherEligibility::reasons($t->fresh(), TeacherProfile::query()->find($t->id), 1);

    expect($visible)->toContain($good->id)->not->toContain($t->id);
    expect($reasons)->toBe(['no_bio']);
})->with('vvT36BlankBios');

test('L2: bio co chu that, kem ky tu an/khoang trang quanh, van duoc tinh la co bio (SQL va PHP cung dong y)', function () {
    config(['teacher_profile.homepage_max' => 500]);
    $t = vvT36EligibleTeacher();
    vvT36SetProfile($t, ['bio' => "\u{3000}\u{3164}Xin chào\u{200E} "]);

    expect(collect((new HomepageTeacherQuery)->get())->pluck('user.id')->all())->toContain($t->id);
    expect(TeacherEligibility::reasons($t->fresh(), TeacherProfile::query()->find($t->id), 1))->toBe([]);
});

// ---------------------------------------------------------------- I7: symlink trong uploads

test('I7: images:prune-orphans bo qua symlink, khong dung cat luot, khong xoa dich cua link', function () {
    Storage::fake('uploads');
    $disk = Storage::disk('uploads');
    $orphan = (string) Str::uuid().'.webp';
    $linkName = (string) Str::uuid().'.webp';
    $disk->put($orphan, 'x');
    touch($disk->path($orphan), time() - 48 * 3600);

    $targetDir = sys_get_temp_dir().'/vv-t36-link-target-'.uniqid();
    mkdir($targetDir);
    file_put_contents($targetDir.'/secret.txt', 'keep');
    touch($targetDir.'/secret.txt', time() - 48 * 3600);
    symlink($targetDir, $disk->path($linkName));

    try {
        Artisan::call('images:prune-orphans');
        $out = Artisan::output();
    } finally {
        @unlink($disk->path($linkName));
    }

    $disk->assertMissing($orphan);
    expect(file_get_contents($targetDir.'/secret.txt'))->toBe('keep');
    expect($out)->toContain('bỏ qua symlink: 1')->toContain('đã xoá: 1');
    unlink($targetDir.'/secret.txt');
    rmdir($targetDir);
});

test('I7: thu muc uploads chua ton tai -> lenh chay khong loi', function () {
    config(['filesystems.disks.uploads.root' => sys_get_temp_dir().'/vv-t36-missing-'.uniqid()]);
    Storage::forgetDisk('uploads');

    expect(Artisan::call('images:prune-orphans'))->toBe(0);
});
