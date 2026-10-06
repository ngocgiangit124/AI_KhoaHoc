<?php

use App\Enums\ConsentType;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

const VV_T36_ME = '/admin/me/teacher-profile';

function vvT36Version(): string
{
    return (string) config('teacher_profile.consent_version');
}

/** @return list<AuditLog> audit của một hành động cho một giáo viên, mới nhất trước */
function vvT36Audits(User $teacher, string $action): array
{
    return AuditLog::query()->where('action', $action)->where('subject_id', $teacher->id)->orderByDesc('id')->get()->all();
}

// ---------------------------------------------------------------- AC1 / GET

test('AC1: GET /me cua giao vien chua co dong ho so -> object du khoa, gia tri rong, KHONG tao dong', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    $r = vvT36Json('GET', VV_T36_ME)->assertOk();

    expect($r->json())->toBe([
        'user' => ['id' => $t->id, 'name' => $t->name, 'role' => 'giao_vien', 'status' => 'active'],
        'headline' => null, 'bio' => null, 'avatar_url' => null,
        'consent' => [
            'given' => false, 'given_at' => null, 'version' => null, 'withdrawn_at' => null,
            'current_version' => vvT36Version(), 'current_text' => config('teacher_profile.consent_text'),
        ],
        'show_on_homepage' => false, 'homepage_order' => null,
        'homepage_status' => ['visible' => false, 'reasons' => ['not_enabled', 'no_consent', 'no_avatar', 'no_bio', 'no_published_course']],
        'published_courses_count' => 0, 'last_edited_by' => null, 'last_edited_at' => null, 'updated_at' => null,
        'abilities' => ['edit_content' => true, 'consent' => true, 'manage_homepage' => false],
    ]);
    expect(TeacherProfile::query()->count())->toBe(0);
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

test('consent.current_text la cau BR4 va khong lo email/SDT', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    $r = vvT36Json('GET', VV_T36_ME)->assertOk();

    expect($r->json('consent.current_text'))->toBe('Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui');
    expect($r->getContent())->not->toContain((string) $t->email)->not->toContain('phone');
});

// ---------------------------------------------------------------- PATCH nội dung, (f)

test('PATCH: luu headline + bio, \\r\\n thanh \\n, trim; response co last_edited_by is_self', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    $r = vvT36Json('PATCH', VV_T36_ME, ['headline' => '  Giáo viên Toán  ', 'bio' => "  Dòng 1\r\nDòng 2\rDòng 3  "])->assertOk();

    expect($r->json('headline'))->toBe('Giáo viên Toán')->and($r->json('bio'))->toBe("Dòng 1\nDòng 2\nDòng 3");
    expect($r->json('last_edited_by'))->toBe(['id' => $t->id, 'name' => $t->name, 'is_self' => true]);
    expect($r->json('last_edited_at'))->not->toBeNull();
    expect(vvT36Profile($t)->bio)->toBe("Dòng 1\nDòng 2\nDòng 3");
});

test('(f) bio 601 ky tu / headline 121 -> 422; 600 va 120 qua (tinh theo ky tu, khong theo byte)', function () {
    vvT36Env();
    vvT36Login('teacher');

    vvT36Json('PATCH', VV_T36_ME, ['bio' => str_repeat('a', 601)])->assertStatus(422)->assertJsonValidationErrors(['bio']);
    vvT36Json('PATCH', VV_T36_ME, ['headline' => str_repeat('a', 121)])->assertStatus(422)->assertJsonValidationErrors(['headline']);
    vvT36Json('PATCH', VV_T36_ME, ['bio' => str_repeat('ế', 601)])->assertStatus(422);

    vvT36Json('PATCH', VV_T36_ME, ['bio' => str_repeat('ế', 600), 'headline' => str_repeat('ê', 120)])->assertOk();
});

test('(f) bio dem ky tu SAU khi chuan hoa: 600 ky tu + \\r\\n o cuoi van qua', function () {
    vvT36Env();
    vvT36Login('teacher');

    vvT36Json('PATCH', VV_T36_ME, ['bio' => str_repeat('a', 300)."\r\n".str_repeat('b', 299)])->assertOk();
    vvT36Json('PATCH', VV_T36_ME, ['bio' => str_repeat('a', 600)."\r\n\r\n"])->assertOk();
    vvT36Json('PATCH', VV_T36_ME, ['bio' => str_repeat('a', 300)."\r\n".str_repeat('b', 300)])->assertStatus(422);
});

test('AC4/(f): HTML, <script>, <, >, bidi, zero-width, ky tu dieu khien -> 422; headline khong cho xuong dong', function (string $field, string $value) {
    vvT36Env();
    vvT36Login('teacher');

    vvT36Json('PATCH', VV_T36_ME, [$field => $value])->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'bio script' => ['bio', '<script>alert(1)</script>'],
    'bio b' => ['bio', 'Xin <b>chào</b>'],
    'bio < le' => ['bio', 'điểm < 9'],
    'bio > le' => ['bio', 'điểm > 9'],
    'bio bidi' => ['bio', "abc\u{202E}def"],
    'bio zero-width' => ['bio', "ab\u{200B}c"],
    'bio BOM' => ['bio', "ab\u{FEFF}c"],
    'bio tab' => ['bio', "a\tb"],
    'bio NUL' => ['bio', "a\0b"],
    'bio ZWJ lac' => ['bio', "a\u{200D}b"],
    'headline b' => ['headline', '<b>x</b>'],
    'headline bidi' => ['headline', "x\u{2067}y"],
    'headline zero-width' => ['headline', "x\u{200C}y"],
    'headline xuong dong' => ['headline', "dòng 1\ndòng 2"],
]);

test('bio cho phep xuong dong va emoji ghep ZWJ hop le', function () {
    vvT36Env();
    vvT36Login('teacher');

    $r = vvT36Json('PATCH', VV_T36_ME, ['bio' => "Gia đình 👨\u{200D}👩\u{200D}👧 vui\n\nHọc là vui"])->assertOk();

    expect($r->json('bio'))->toContain("\n\n");
});

test('AC21: chi gui truong da doi; truong khong gui giu nguyen; gui null/rong thi xoa; payload rong -> 422', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Json('PATCH', VV_T36_ME, ['headline' => 'H', 'bio' => 'B'])->assertOk();

    vvT36Json('PATCH', VV_T36_ME, ['headline' => 'H2'])->assertOk()->assertJsonPath('headline', 'H2')->assertJsonPath('bio', 'B');
    vvT36Json('PATCH', VV_T36_ME, ['bio' => 'B2'])->assertOk()->assertJsonPath('headline', 'H2')->assertJsonPath('bio', 'B2');
    vvT36Json('PATCH', VV_T36_ME, ['headline' => null])->assertOk()->assertJsonPath('headline', null)->assertJsonPath('bio', 'B2');
    vvT36Json('PATCH', VV_T36_ME, ['bio' => '   '])->assertOk()->assertJsonPath('bio', null);

    vvT36Json('PATCH', VV_T36_ME, [])->assertStatus(422);
    vvT36Json('PATCH', VV_T36_ME, ['khac' => 'x'])->assertStatus(422);
    expect(vvT36Profile($t)->headline)->toBeNull();
});

test('PATCH khong mass-assign: truong cot khac (avatar_path, show_on_homepage, consent...) bi bo qua', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Json('PATCH', VV_T36_ME, [
        'bio' => 'B', 'avatar_path' => 'x.webp', 'show_on_homepage' => true, 'homepage_order' => 1,
        'public_consent_at' => now()->toDateTimeString(), 'user_id' => 1, 'profile_updated_by' => 1,
    ])->assertOk();

    $p = vvT36Profile($t);
    expect($p->avatar_path)->toBeNull()->and($p->show_on_homepage)->toBeFalse()->and($p->homepage_order)->toBeNull()
        ->and($p->public_consent_at)->toBeNull()->and($p->user_id)->toBe($t->id)->and($p->profile_updated_by)->toBe($t->id);
});

// ---------------------------------------------------------------- đồng ý / rút (BR4)

test('dong y: ghi consents (type, version, granted_by self, ip, UA), audit; response given=true', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    $r = vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk();

    expect($r->json('consent.given'))->toBeTrue()->and($r->json('consent.version'))->toBe(vvT36Version())
        ->and($r->json('consent.given_at'))->not->toBeNull()->and($r->json('consent.withdrawn_at'))->toBeNull();

    $c = Consent::query()->where('user_id', $t->id)->where('type', ConsentType::TeacherPublicProfile->value)->get();
    expect($c)->toHaveCount(1);
    expect($c[0]->policy_version)->toBe(vvT36Version())->and($c[0]->granted_by)->toBe('self')->and($c[0]->channel)->toBe('web_form')
        ->and($c[0]->revoked_at)->toBeNull()->and($c[0]->ip)->not->toBeNull();

    $audit = vvT36Audits($t, 'teacher_profile.consent');
    expect($audit)->toHaveCount(1)->and($audit[0]->changes)->toEqual(['version' => vvT36Version()])->and($audit[0]->actor_id)->toBe($t->id);
});

test('dong y lai dung phien ban (double submit) -> 200, khong them consents, khong audit', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk();
    $at = vvT36Profile($t)->public_consent_at;

    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk()->assertJsonPath('consent.given', true);

    expect(Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->count())->toBe(1);
    expect(vvT36Audits($t, 'teacher_profile.consent'))->toHaveCount(1);
    expect(vvT36Profile($t)->public_consent_at->equalTo($at))->toBeTrue();
});

test('phien ban khac hien hanh -> 409 CONSENT_VERSION_CHANGED, khong ghi gi; thieu/sai kieu -> 422', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => '1999-01'])->assertStatus(409)->assertJson(['code' => 'CONSENT_VERSION_CHANGED']);
    vvT36Json('POST', VV_T36_ME.'/consent', [])->assertStatus(422)->assertJsonValidationErrors(['version']);
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => ['x']])->assertStatus(422);
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => str_repeat('x', 21)])->assertStatus(422);

    expect(Consent::query()->where('user_id', $t->id)->count())->toBe(0);
    expect(TeacherProfile::query()->find($t->id)?->public_consent_at)->toBeNull();
});

test('rut dong y: consent_at null, withdrawn_at co, consents.revoked_at, noi dung va co trang chu giu nguyen, audit', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Json('PATCH', VV_T36_ME, ['bio' => 'Bio', 'headline' => 'H'])->assertOk();
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk();
    vvT36SetProfile($t, ['show_on_homepage' => true, 'homepage_order' => 2]);

    $r = vvT36Json('DELETE', VV_T36_ME.'/consent')->assertOk();

    expect($r->json('consent.given'))->toBeFalse()->and($r->json('consent.withdrawn_at'))->not->toBeNull()
        ->and($r->json('consent.version'))->toBeNull()->and($r->json('bio'))->toBe('Bio');
    $p = vvT36Profile($t);
    expect($p->public_consent_at)->toBeNull()->and($p->public_consent_version)->toBeNull()->and($p->public_consent_withdrawn_at)->not->toBeNull()
        ->and($p->show_on_homepage)->toBeTrue()->and($p->homepage_order)->toBe(2)->and($p->bio)->toBe('Bio');
    expect(Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->whereNull('revoked_at')->count())->toBe(0);

    $audit = vvT36Audits($t, 'teacher_profile.consent_withdraw');
    expect($audit)->toHaveCount(1)->and($audit[0]->changes)->toEqual(['version' => vvT36Version()]);

    // Rút lần hai và rút khi chưa từng đồng ý: 200, không ghi thêm.
    vvT36Json('DELETE', VV_T36_ME.'/consent')->assertOk();
    expect(vvT36Audits($t, 'teacher_profile.consent_withdraw'))->toHaveCount(1);
});

test('rut dong y khi chua tung co ho so -> 200, khong tao dong, khong audit', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Json('DELETE', VV_T36_ME.'/consent')->assertOk()->assertJsonPath('consent.given', false);

    expect(TeacherProfile::query()->count())->toBe(0)->and(vvT36Audits($t, 'teacher_profile.consent_withdraw'))->toBe([]);
});

test('dong y -> rut -> dong y lai: moi lan dong y mot dong consents, dong cu giu revoked_at', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk();
    vvT36Json('DELETE', VV_T36_ME.'/consent')->assertOk();
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk()->assertJsonPath('consent.given', true)->assertJsonPath('consent.withdrawn_at', null);

    $rows = Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->revoked_at)->not->toBeNull()->and($rows[1]->revoked_at)->toBeNull();
    expect(vvT36Audits($t, 'teacher_profile.consent'))->toHaveCount(2);
});

test('doi cau chu dong y: dong y cu van hieu luc (PO 2026-10-06), vao /courses van hien bo', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    $course = vvT36PublishedCourse($t);
    vvT36Json('PATCH', VV_T36_ME, ['bio' => 'Bio'])->assertOk();
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version()])->assertOk();

    config(['teacher_profile.consent_version' => '2027-01', 'teacher_profile.consent_text' => 'Câu chữ mới']);

    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe('Bio');
    $r = vvT36Json('GET', VV_T36_ME)->assertOk();
    expect($r->json('consent.given'))->toBeTrue()->and($r->json('consent.version'))->toBe('2026-10')
        ->and($r->json('consent.current_version'))->toBe('2027-01');

    // Đồng ý lại phiên bản mới: ghi thêm một dòng consents, cập nhật phiên bản.
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => '2027-01'])->assertOk()->assertJsonPath('consent.version', '2027-01');
    expect(Consent::query()->where('user_id', $t->id)->where('type', 'teacher_public_profile')->count())->toBe(2);
});

// ---------------------------------------------------------------- (d) phân quyền

test('(d) Admin/QLT goi moi route /me/teacher-profile -> 403 FORBIDDEN (kiem truoc validate), khong ghi gi', function (string $state) {
    vvT36Env();
    $u = vvT36Login($state);
    $base = AuditLog::query()->count();

    foreach ([
        ['GET', VV_T36_ME, []], ['PATCH', VV_T36_ME, []], ['PATCH', VV_T36_ME, ['bio' => '<b>']],
        ['DELETE', VV_T36_ME.'/avatar', []], ['POST', VV_T36_ME.'/consent', []], ['POST', VV_T36_ME.'/consent', ['version' => vvT36Version()]],
        ['DELETE', VV_T36_ME.'/consent', []],
    ] as [$m, $p, $d]) {
        vvT36Json($m, $p, $d)->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    }
    vvT36Upload(VV_T36_ME.'/avatar', 'not-a-file')->assertForbidden();

    expect(TeacherProfile::query()->count())->toBe(0)->and(Consent::query()->where('user_id', $u->id)->count())->toBe(0);
    expect(AuditLog::query()->where('id', '>', $base)->where('action', 'like', 'teacher_profile.%')->count())->toBe(0);
})->with(['admin', 'pageManager']);

test('(d) khach -> 401; hoc sinh -> 401/403 o moi route teacher-profile', function () {
    vvT36Env();
    foreach ([
        ['GET', VV_T36_ME], ['PATCH', VV_T36_ME], ['POST', VV_T36_ME.'/consent'], ['DELETE', VV_T36_ME.'/consent'],
        ['GET', '/admin/teacher-profiles'], ['GET', '/admin/teacher-profiles/1'], ['PATCH', '/admin/teacher-profiles/1/homepage'],
    ] as [$m, $p]) {
        vvT36Json($m, $p, [])->assertUnauthorized();
    }

    $student = User::factory()->student()->verified()->create();
    app('auth')->forgetGuards();
    foreach ([['GET', VV_T36_ME], ['POST', VV_T36_ME.'/consent'], ['GET', '/admin/teacher-profiles']] as [$m, $p]) {
        $res = test()->actingAs($student)->json($m, vvAdminUrl($p), [], vvAdminHeaders());
        expect($res->status())->toBeIn([401, 403]);
    }
});

test('(d) khong co route dong y mang {user}: /admin/teacher-profiles/{id}/consent -> 404/405', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    vvT36Login('admin');

    foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'GET'] as $method) {
        $res = vvT36Json($method, "/admin/teacher-profiles/{$t->id}/consent", ['version' => vvT36Version()]);
        expect($res->status())->toBeIn([404, 405], $method);
    }
    expect(Consent::query()->where('user_id', $t->id)->count())->toBe(0);
    expect(DB::table('teacher_profiles')->where('user_id', $t->id)->exists())->toBeFalse();
});

test('(d) khong tham so nao cua /me doi duoc nguoi bi tac dong: gui user_id/teacher_id/user vao body bi bo qua', function () {
    vvT36Env();
    $me = vvT36Login('teacher');
    $other = User::factory()->teacher()->create();

    vvT36Json('PATCH', VV_T36_ME, ['bio' => 'Của tôi', 'user_id' => $other->id, 'teacher_id' => $other->id, 'user' => $other->id])->assertOk();
    vvT36Json('POST', VV_T36_ME.'/consent', ['version' => vvT36Version(), 'user_id' => $other->id])->assertOk();

    expect(vvT36Profile($me)->bio)->toBe('Của tôi');
    expect(vvT36Profile($other))->toBeNull();
    expect(Consent::query()->where('user_id', $other->id)->count())->toBe(0);
});

// ---------------------------------------------------------------- (g) audit

test('(g) audit teacher_profile.update: fields, on_behalf=false, bio_length, khong chua noi dung bio/headline; double submit khong ghi trung', function () {
    vvT36Env();
    $t = vvT36Login('teacher');

    vvT36Json('PATCH', VV_T36_ME, ['headline' => 'Tiêu đề bí mật', 'bio' => 'Nội dung bio bí mật'])->assertOk();
    $audit = vvT36Audits($t, 'teacher_profile.update');
    expect($audit)->toHaveCount(1);
    expect($audit[0]->changes)->toEqual(['fields' => ['headline', 'bio'], 'on_behalf' => false, 'bio_length' => ['from' => 0, 'to' => 19]]);
    expect(json_encode($audit[0]->changes))->not->toContain('bí mật');
    expect($audit[0]->actor_id)->toBe($t->id)->and($audit[0]->subject_id)->toBe($t->id);

    // Gửi lại đúng giá trị cũ: 200, không audit.
    vvT36Json('PATCH', VV_T36_ME, ['headline' => 'Tiêu đề bí mật', 'bio' => "Nội dung bio bí mật\r\n"])->assertOk();
    expect(vvT36Audits($t, 'teacher_profile.update'))->toHaveCount(1);

    // Chỉ đổi headline: fields chỉ có headline, không có bio_length.
    vvT36Json('PATCH', VV_T36_ME, ['headline' => 'Mới'])->assertOk();
    $latest = vvT36Audits($t, 'teacher_profile.update')[0];
    expect($latest->changes)->toEqual(['fields' => ['headline'], 'on_behalf' => false]);
});

test('PATCH /me: rate limit 30/phut/nguoi -> 429', function () {
    vvT36Env();
    vvT36Login('teacher');

    foreach (range(1, 30) as $i) {
        vvT36Json('PATCH', VV_T36_ME, ['headline' => 'H'.($i % 2)])->assertOk();
    }
    vvT36Json('PATCH', VV_T36_ME, ['headline' => 'X'])->assertStatus(429);
});

test('published_courses_count dem khoa published chua xoa cua giao vien; reasons doi theo du lieu', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36PublishedCourse($t);
    vvT36PublishedCourse($t)->delete();
    $draft = Course::factory()->create();
    DB::table('course_teacher')->insert(['course_id' => $draft->id, 'user_id' => $t->id]);

    $r = vvT36Json('GET', VV_T36_ME)->assertOk();

    expect($r->json('published_courses_count'))->toBe(1)
        ->and($r->json('homepage_status.reasons'))->not->toContain('no_published_course');
});
