<?php

use App\Enums\ConsentType;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

test('erase (T34): xoa dong ho so, xoa file anh, thu hoi consents con hieu luc, audit teacher_profile.erase', function () {
    Storage::fake('uploads');
    $t = User::factory()->teacher()->withPublicProfile(true)->create();
    $path = vvT36Profile($t)->avatar_path;
    Storage::disk('uploads')->put($path, 'x');
    Consent::create([
        'user_id' => $t->id, 'type' => ConsentType::TeacherPublicProfile, 'policy_version' => '2026-10',
        'granted_by' => 'self', 'channel' => 'web_form', 'granted_at' => now(),
    ]);
    $other = User::factory()->teacher()->withPublicProfile()->create();

    app(TeacherProfileService::class)->erase($t);

    expect(vvT36Profile($t))->toBeNull()->and(vvT36Profile($other))->not->toBeNull();
    Storage::disk('uploads')->assertMissing($path);
    expect(Consent::query()->where('user_id', $t->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(Consent::query()->where('user_id', $t->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'teacher_profile.erase')->where('subject_id', $t->id)->count())->toBe(1);

    // Lần hai: không còn gì để xoá → không audit thêm.
    app(TeacherProfileService::class)->erase($t);
    expect(AuditLog::query()->where('action', 'teacher_profile.erase')->where('subject_id', $t->id)->count())->toBe(1);
});

test('erase: user khong co ho so va khong co dong y -> no-op, khong audit', function () {
    $t = User::factory()->teacher()->create();

    app(TeacherProfileService::class)->erase($t);

    expect(AuditLog::query()->where('action', 'teacher_profile.erase')->where('subject_id', $t->id)->count())->toBe(0);
});

test('erase xong: /home/teachers va chi tiet khoa khong con thong tin (BR9, US-018)', function () {
    config(['app.static_url' => 'https://static.example.test']);
    $t = vvT36EligibleTeacher();
    $course = $t->taughtCourses()->first();
    expect(vvT36Public('/home/teachers')->json('data'))->toHaveCount(1);

    app(TeacherProfileService::class)->erase($t);

    expect(vvT36Public('/home/teachers')->json('data'))->toBe([]);
    vvT36Public('/courses/'.$course->slug)->assertOk()->assertJsonPath('teachers.0.bio', null)->assertJsonPath('teachers.0.avatar_url', null);
});

test('service: noi dung chi sua duoc khi user la giao vien (NOT_TEACHER) va khong tao dong', function () {
    $student = User::factory()->student()->create();

    expect(fn () => app(TeacherProfileService::class)->updateContent($student, ['bio' => 'x'], $student))
        ->toThrow(DomainException::class);
    expect(fn () => app(TeacherProfileService::class)->giveConsent($student, config('teacher_profile.consent_version')))
        ->toThrow(DomainException::class);
    expect(TeacherProfile::query()->count())->toBe(0);
});

test('normalizeText: \\r\\n va \\r thanh \\n, trim, rong -> null, khong phai chuoi -> null', function () {
    expect(TeacherProfileService::normalizeText("  a\r\nb\rc  "))->toBe("a\nb\nc")
        ->and(TeacherProfileService::normalizeText("   \r\n  "))->toBeNull()
        ->and(TeacherProfileService::normalizeText(''))->toBeNull()
        ->and(TeacherProfileService::normalizeText(null))->toBeNull()
        ->and(TeacherProfileService::normalizeText(123))->toBeNull();
});

test('audit khong chua noi dung anh/bio/headline o bat ky action nao', function () {
    Storage::fake('uploads');
    $t = User::factory()->teacher()->create();
    $admin = User::factory()->admin()->create();
    $svc = app(TeacherProfileService::class);

    $svc->updateContent($t, ['headline' => 'HEADLINE-BI-MAT', 'bio' => 'BIO-BI-MAT'], $admin);
    $svc->replaceAvatar($t, UploadedFile::fake()->image('a.jpg', 100, 100), $admin);
    $svc->giveConsent($t, config('teacher_profile.consent_version'));
    $svc->setHomepage($t, true, true, 3);
    $svc->withdrawConsent($t);
    $svc->removeAvatar($t, $admin);

    $dump = json_encode(AuditLog::query()->where('subject_id', $t->id)->where('action', 'like', 'teacher_profile.%')->pluck('changes')->all());
    expect($dump)->not->toContain('BI-MAT')->not->toContain('.webp');
    expect(AuditLog::query()->where('subject_id', $t->id)->where('action', 'like', 'teacher_profile.%')->count())->toBe(7);
});

test('cap nhat dong thoi nhieu truong khac nhau cua 2 nguoi (AC21): moi nguoi chi ghi truong cua minh', function () {
    $t = User::factory()->teacher()->create();
    $admin = User::factory()->admin()->create();
    $svc = app(TeacherProfileService::class);

    // Giáo viên và admin mở cùng một bản (bio cũ rỗng), mỗi người chỉ gửi trường mình đổi.
    $svc->updateContent($t, ['headline' => 'Của giáo viên'], $t);
    $svc->updateContent($t, ['bio' => 'Của admin'], $admin);

    $p = vvT36Profile($t);
    expect($p->headline)->toBe('Của giáo viên')->and($p->bio)->toBe('Của admin')->and($p->profile_updated_by)->toBe($admin->id);
    expect(DB::table('teacher_profiles')->count())->toBe(1);
});
