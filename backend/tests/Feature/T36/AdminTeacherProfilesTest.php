<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

const VV_T36_ADM = '/admin/teacher-profiles';

/** @return list<AuditLog> */
function vvT36AdmAudits(User $teacher, string $action): array
{
    return AuditLog::query()->where('action', $action)->where('subject_id', $teacher->id)->orderByDesc('id')->get()->all();
}

/** Bật cờ trang chủ trực tiếp cho n giáo viên (bỏ qua service) để dựng tình huống "đã đủ 6". */
function vvT36Enabled(int $n, array $userAttrs = []): array
{
    $users = [];
    foreach (range(1, $n) as $i) {
        $u = User::factory()->teacher()->create($userAttrs);
        DB::table('teacher_profiles')->insert(['user_id' => $u->id, 'show_on_homepage' => true, 'created_at' => now(), 'updated_at' => now()]);
        $users[] = $u;
    }

    return $users;
}

// ---------------------------------------------------------------- (d) IDOR / phân quyền

test('(d)/AC18: giao vien goi moi route /admin/teacher-profiles -> 403 FORBIDDEN truoc validate va truoc khi tim ban ghi; khong ghi gi', function () {
    vvT36Env();
    $a = vvT36Login('teacher');
    $b = User::factory()->teacher()->create();
    $ids = [$b->id, 999999, 'abc'];
    $base = AuditLog::query()->count();

    foreach ($ids as $id) {
        foreach ([
            ['GET', "/admin/teacher-profiles/{$id}", []],
            ['PATCH', "/admin/teacher-profiles/{$id}", ['bio' => 'hack']],
            ['PATCH', "/admin/teacher-profiles/{$id}", []],
            ['DELETE', "/admin/teacher-profiles/{$id}/avatar", []],
            ['PATCH', "/admin/teacher-profiles/{$id}/homepage", ['show_on_homepage' => true]],
            ['PATCH', "/admin/teacher-profiles/{$id}/homepage", []],
        ] as [$m, $p, $d]) {
            vvT36Json($m, $p, $d)->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
        }
        vvT36Upload("/admin/teacher-profiles/{$id}/avatar", UploadedFile::fake()->image('a.jpg', 50, 50))->assertForbidden();
    }
    vvT36Json('GET', VV_T36_ADM)->assertForbidden();

    expect(TeacherProfile::query()->count())->toBe(0);
    expect(AuditLog::query()->where('id', '>', $base)->where('action', 'like', 'teacher_profile.%')->count())->toBe(0);
    expect($a->id)->not->toBe($b->id);
});

// ---------------------------------------------------------------- show / index

test('GET /admin/teacher-profiles/{user}: Admin va QLT xem duoc; abilities consent=false, manage_homepage=true', function (string $state) {
    vvT36Env();
    $t = User::factory()->teacher()->withPublicProfile()->create();
    vvT36PublishedCourse($t);
    vvT36Login($state);

    $r = vvT36Json('GET', VV_T36_ADM.'/'.$t->id)->assertOk();

    expect($r->json('user.id'))->toBe($t->id)->and($r->json('abilities'))->toBe(['edit_content' => true, 'consent' => false, 'manage_homepage' => true])
        ->and($r->json('consent.given'))->toBeTrue()->and($r->json('published_courses_count'))->toBe(1);
    expect($r->getContent())->not->toContain((string) $t->email);
})->with(['admin', 'pageManager']);

test('{user}: hoc sinh, admin, QLT, id khong ton tai, khong phai so -> 404 NOT_FOUND', function () {
    vvT36Env();
    vvT36Login('admin');
    $others = [User::factory()->student()->create(), User::factory()->pageManager()->create(), User::factory()->admin()->create()];

    foreach ([...array_map(fn ($u) => $u->id, $others), 999999, 'abc', '1e3', '-1', '99999999999999999999'] as $id) {
        vvT36Json('GET', VV_T36_ADM."/{$id}")->assertNotFound()->assertJson(['code' => 'NOT_FOUND']);
        vvT36Json('PATCH', VV_T36_ADM."/{$id}", ['bio' => 'x'])->assertNotFound();
        vvT36Json('PATCH', VV_T36_ADM."/{$id}/homepage", ['show_on_homepage' => false])->assertNotFound();
    }
    expect(TeacherProfile::query()->count())->toBe(0);
});

test('AC8: admin sua ho noi dung (headline, bio, anh): luu duoc, noi dung co hieu luc ngay neu da dong y, audit on_behalf=true, giao vien thay "chinh sua gan nhat boi"', function () {
    vvT36Env();
    $t = User::factory()->teacher()->withPublicProfile()->create();
    $course = vvT36PublishedCourse($t);
    $admin = vvT36Login('admin');

    $r = vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id, ['headline' => 'Do admin sửa', 'bio' => 'Bio do admin'])->assertOk();
    expect($r->json('headline'))->toBe('Do admin sửa')->and($r->json('last_edited_by'))->toBe(['id' => $admin->id, 'name' => $admin->name, 'is_self' => true]);
    $oldAvatar = vvT36Profile($t)->avatar_path;
    Storage::disk('uploads')->put($oldAvatar, 'old');
    vvT36Upload(VV_T36_ADM.'/'.$t->id.'/avatar', UploadedFile::fake()->image('a.jpg', 400, 200))->assertOk();

    // Hiệu lực ngay ở API công khai (giáo viên đã đồng ý).
    $pub = vvT36Public('/courses/'.$course->slug)->json('teachers.0');
    expect($pub['bio'])->toBe('Bio do admin')->and($pub['avatar_url'])->toContain(vvT36Profile($t)->avatar_path);
    Storage::disk('uploads')->assertMissing($oldAvatar);

    $audits = vvT36AdmAudits($t, 'teacher_profile.update');
    expect($audits)->toHaveCount(2)->and($audits[1]->changes['on_behalf'])->toBeTrue()->and($audits[0]->changes['on_behalf'])->toBeTrue()
        ->and($audits[0]->actor_id)->toBe($admin->id)->and($audits[0]->subject_id)->toBe($t->id);

    // Giáo viên mở "Hồ sơ của tôi": thấy ai sửa gần nhất (is_self=false).
    vvT36Login('teacher', []); // người khác; đăng nhập lại đúng giáo viên:
    vvStaffLogin($t);
    $me = vvT36Json('GET', '/admin/me/teacher-profile')->assertOk();
    expect($me->json('last_edited_by'))->toBe(['id' => $admin->id, 'name' => $admin->name, 'is_self' => false]);
});

test('AC8: admin KHONG dong y thay: ho so cua giao vien giu consent nhu cu; /me/consent 403', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    vvT36Login('admin');

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id, ['bio' => 'Bio', 'public_consent_at' => now()->toDateTimeString(), 'consent' => true])->assertOk()
        ->assertJsonPath('consent.given', false);

    expect(vvT36Profile($t)->public_consent_at)->toBeNull();
    expect(Consent::query()->where('user_id', $t->id)->count())->toBe(0);
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => config('teacher_profile.consent_version')])->assertForbidden();
});

test('PATCH noi dung cho user khong con la giao vien (con co) -> 422 NOT_TEACHER; upload anh cung vay; xoa anh duoc', function () {
    vvT36Env();
    $ex = User::factory()->teacher()->withPublicProfile(true)->create();
    vvT36SetUser($ex, ['role' => UserRole::PageManager->value]);
    $path = vvT36Profile($ex)->avatar_path;
    Storage::disk('uploads')->put($path, 'x');
    vvT36Login('admin');

    vvT36Json('PATCH', VV_T36_ADM.'/'.$ex->id, ['bio' => 'x'])->assertStatus(422)->assertJson(['code' => 'NOT_TEACHER']);
    vvT36Upload(VV_T36_ADM.'/'.$ex->id.'/avatar', UploadedFile::fake()->image('a.jpg', 50, 50))->assertStatus(422)->assertJson(['code' => 'NOT_TEACHER']);
    expect(Storage::disk('uploads')->allFiles())->toBe([$path]);

    vvT36Json('DELETE', VV_T36_ADM.'/'.$ex->id.'/avatar')->assertOk()->assertJsonPath('avatar_url', null);
    Storage::disk('uploads')->assertMissing($path);
});

test('index: gom moi giao vien (ke ca bi khoa) + nguoi da doi vai tro con dong ho so; sap dang bat truoc theo order; khong N+1; meta.homepage', function () {
    vvT36Env();
    $plain = User::factory()->teacher()->create(['name' => 'An']);
    $locked = User::factory()->teacher()->locked()->create(['name' => 'Bình']);
    $o2 = User::factory()->teacher()->withPublicProfile(true, 2)->create(['name' => 'Zed']);
    $o1 = User::factory()->teacher()->withPublicProfile(true, 1)->create(['name' => 'Yan']);
    $noOrder = User::factory()->teacher()->withPublicProfile(true)->create(['name' => 'Xuân']);
    $ex = User::factory()->teacher()->withPublicProfile(true, 3)->create(['name' => 'Cựu']);
    vvT36SetUser($ex, ['role' => UserRole::PageManager->value]);
    $student = User::factory()->student()->create();
    $exOff = User::factory()->teacher()->create(['name' => 'Cựu tắt']);
    DB::table('teacher_profiles')->insert(['user_id' => $exOff->id, 'created_at' => now(), 'updated_at' => now()]);
    vvT36SetUser($exOff, ['role' => UserRole::PageManager->value]);
    vvT36Login('pageManager');

    DB::enableQueryLog();
    $r = vvT36Json('GET', VV_T36_ADM)->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $ids = collect($r->json('data'))->pluck('user.id')->all();
    // Đang bật: order 1, 2, 3 (Yan, Zed, Cựu), rồi chưa đặt thứ tự (Xuân); sau đó người chưa bật theo tên.
    // L1: người đã đổi vai trò nhưng còn dòng hồ sơ (kể cả đã tắt cờ) vẫn nằm trong danh sách (để xoá ảnh hộ).
    expect($ids)->toBe([$o1->id, $o2->id, $ex->id, $noOrder->id, $plain->id, $locked->id, $exOff->id]);
    expect($ids)->not->toContain($student->id);
    expect($r->json('meta.homepage'))->toBe(['enabled_count' => 4, 'max' => 6]);
    expect(collect($r->json('data'))->firstWhere('user.id', $ex->id)['homepage_status']['reasons'][0])->toBe('not_teacher');
    expect(collect($r->json('data'))->firstWhere('user.id', $locked->id)['homepage_status']['reasons'])->toContain('account_locked');
    expect($r->json('data.0.abilities'))->toBe(['edit_content' => true, 'manage_homepage' => true]);
    expect($queries)->toBeLessThan(15);
    expect($r->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page', 'homepage']);
});

test('index: loc q (theo ten, escape LIKE), homepage=1, per_page 25|50, tham so sai -> 422', function () {
    vvT36Env();
    $a = User::factory()->teacher()->create(['name' => 'Nguyễn Lan']);
    $b = User::factory()->teacher()->withPublicProfile(true)->create(['name' => 'Trần 100% Hoa']);
    User::factory()->teacher()->create(['name' => 'Trần Hoa']);
    vvT36Login('admin');
    $names = fn ($r) => collect($r->json('data'))->pluck('user.name')->all();

    expect($names(vvT36Json('GET', VV_T36_ADM.'?q=Lan')))->toBe(['Nguyễn Lan']);
    expect($names(vvT36Json('GET', VV_T36_ADM.'?q=100%25')))->toBe(['Trần 100% Hoa']);
    expect($names(vvT36Json('GET', VV_T36_ADM.'?homepage=1')))->toBe(['Trần 100% Hoa']);
    vvT36Json('GET', VV_T36_ADM.'?per_page=50')->assertOk()->assertJsonPath('meta.per_page', 50);
    foreach (['per_page=10', 'homepage=x', 'page=0', 'q='.str_repeat('a', 101)] as $bad) {
        vvT36Json('GET', VV_T36_ADM.'?'.$bad)->assertStatus(422);
    }
    expect([$a->id, $b->id])->toHaveCount(2);
});

// ---------------------------------------------------------------- homepage: bật/tắt, AC9, AC10, AC11

test('AC9: bat trang chu cho giao vien chua du dieu kien -> luu duoc, homepage_status.visible=false kem ly do (khong bao hien gia)', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    $admin = vvT36Login('admin');

    $r = vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => true])->assertOk();

    expect($r->json('show_on_homepage'))->toBeTrue()
        ->and($r->json('homepage_status'))->toBe(['visible' => false, 'reasons' => ['no_consent', 'no_avatar', 'no_bio', 'no_published_course']]);
    expect(vvT36Profile($t)->show_on_homepage)->toBeTrue();
    expect(collect(vvT36Public('/home/teachers')->json('data'))->pluck('id')->all())->not->toContain($t->id);
    expect($admin->id)->not->toBe($t->id);
});

test('bat trang chu khi du dieu kien -> hien o /home/teachers; tat -> bien mat; audit toggle', function () {
    vvT36Env();
    $t = User::factory()->teacher()->withPublicProfile()->create();
    vvT36PublishedCourse($t);
    $admin = vvT36Login('pageManager');

    $r = vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => true])->assertOk();
    expect($r->json('homepage_status'))->toBe(['visible' => true, 'reasons' => []]);
    expect(collect(vvT36Public('/home/teachers')->json('data'))->pluck('id')->all())->toBe([$t->id]);

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => false])->assertOk()->assertJsonPath('homepage_status.reasons.0', 'not_enabled');
    expect(vvT36Public('/home/teachers')->json('data'))->toBe([]);

    $audits = vvT36AdmAudits($t, 'teacher_profile.homepage_toggle');
    expect($audits)->toHaveCount(2)
        ->and($audits[1]->changes)->toEqual(['show_on_homepage' => ['from' => false, 'to' => true]])
        ->and($audits[0]->changes)->toEqual(['show_on_homepage' => ['from' => true, 'to' => false]])
        ->and($audits[0]->actor_id)->toBe($admin->id);
});

test('(c)/AC10: da du 6 nguoi, bat nguoi thu 7 -> 409 TEACHER_HOMEPAGE_LIMIT, trang thai va homepage_order gui kem giu nguyen', function () {
    vvT36Env();
    $max = (int) config('teacher_profile.homepage_max');
    vvT36Enabled($max);
    $seventh = User::factory()->teacher()->create();
    vvT36Login('admin');
    $base = AuditLog::query()->count();

    $r = vvT36Json('PATCH', VV_T36_ADM.'/'.$seventh->id.'/homepage', ['show_on_homepage' => true, 'homepage_order' => 5])
        ->assertStatus(409)->assertJson(['code' => 'TEACHER_HOMEPAGE_LIMIT']);

    expect($r->json('message'))->toBe("Trang chủ chỉ hiển thị tối đa {$max} giáo viên. Hãy tắt bớt một người trước.");
    $p = vvT36Profile($seventh);
    expect($p->show_on_homepage)->toBeFalse()->and($p->homepage_order)->toBeNull();
    expect(TeacherProfile::query()->where('show_on_homepage', true)->count())->toBe($max);
    expect(AuditLog::query()->where('id', '>', $base)->where('action', 'like', 'teacher_profile.%')->count())->toBe(0);
});

test('con 5 nguoi: bat nguoi thu 6 duoc; tat 1 nguoi roi bat nguoi khac duoc; bat lai nguoi da bat khi dang day -> 200 khong doi', function () {
    vvT36Env();
    $enabled = vvT36Enabled(5);
    $sixth = User::factory()->teacher()->create();
    $seventh = User::factory()->teacher()->create();
    vvT36Login('admin');

    vvT36Json('PATCH', VV_T36_ADM.'/'.$sixth->id.'/homepage', ['show_on_homepage' => true])->assertOk();
    vvT36Json('PATCH', VV_T36_ADM.'/'.$seventh->id.'/homepage', ['show_on_homepage' => true])->assertStatus(409);
    // Người đã bật khi đang đầy: không phải "thêm" nên không bị chặn, không audit.
    $base = count(vvT36AdmAudits($sixth, 'teacher_profile.homepage_toggle'));
    vvT36Json('PATCH', VV_T36_ADM.'/'.$sixth->id.'/homepage', ['show_on_homepage' => true])->assertOk();
    expect(vvT36AdmAudits($sixth, 'teacher_profile.homepage_toggle'))->toHaveCount($base);

    vvT36Json('PATCH', VV_T36_ADM.'/'.$enabled[0]->id.'/homepage', ['show_on_homepage' => false])->assertOk();
    vvT36Json('PATCH', VV_T36_ADM.'/'.$seventh->id.'/homepage', ['show_on_homepage' => true])->assertOk();
    expect(TeacherProfile::query()->where('show_on_homepage', true)->count())->toBe(6);
});

test('PO: nguoi bi khoa va nguoi da doi vai tro van co co van TINH vao gioi han; admin tat duoc ho tu man danh sach', function () {
    vvT36Env();
    $locked = vvT36Enabled(1)[0];
    vvT36Lock($locked);
    $ex = vvT36Enabled(1)[0];
    vvT36SetUser($ex, ['role' => UserRole::PageManager->value]);
    vvT36Enabled(4);
    $newcomer = User::factory()->teacher()->create();
    vvT36Login('admin');

    // 1 khoá + 1 đổi vai trò + 4 thường = 6: người mới bị từ chối.
    vvT36Json('PATCH', VV_T36_ADM.'/'.$newcomer->id.'/homepage', ['show_on_homepage' => true])->assertStatus(409);

    $list = vvT36Json('GET', VV_T36_ADM.'?homepage=1')->assertOk();
    $listed = collect($list->json('data'))->pluck('user.id')->all();
    expect($listed)->toContain($locked->id)->toContain($ex->id)->and($list->json('meta.homepage.enabled_count'))->toBe(6);

    // Tắt người đã đổi vai trò (tắt vẫn được dù NOT_TEACHER), rồi bật người mới.
    vvT36Json('PATCH', VV_T36_ADM.'/'.$ex->id.'/homepage', ['show_on_homepage' => false])->assertOk()->assertJsonPath('show_on_homepage', false);
    vvT36Json('PATCH', VV_T36_ADM.'/'.$newcomer->id.'/homepage', ['show_on_homepage' => true])->assertOk();
    // L1: người đã đổi vai trò, đã tắt cờ nhưng còn dòng hồ sơ: vẫn truy cập được (để xoá ảnh hộ), nay không còn chiếm suất.
    vvT36Json('GET', VV_T36_ADM.'/'.$ex->id)->assertOk()->assertJsonPath('show_on_homepage', false);
});

test('bat trang chu cho user khong con la giao vien -> 422 NOT_TEACHER; doi thu tu van duoc', function () {
    vvT36Env();
    $ex = vvT36Enabled(1)[0];
    vvT36SetUser($ex, ['role' => UserRole::PageManager->value]);
    vvT36SetProfile($ex, ['show_on_homepage' => false]);
    vvT36SetProfile($ex, ['show_on_homepage' => true]);
    vvT36Login('admin');

    // Đang bật cờ (còn giữ): bật lại là no-op nhưng vẫn phải kiểm vai trò theo hợp đồng.
    vvT36Json('PATCH', VV_T36_ADM.'/'.$ex->id.'/homepage', ['show_on_homepage' => true])->assertStatus(422)->assertJson(['code' => 'NOT_TEACHER']);
    vvT36Json('PATCH', VV_T36_ADM.'/'.$ex->id.'/homepage', ['homepage_order' => 9])->assertOk()->assertJsonPath('homepage_order', 9);
});

test('AC11: dat homepage_order -> audit homepage_order; double submit khong ghi trung; null xoa thu tu; ngoai 1..999 -> 422', function () {
    vvT36Env();
    $t = vvT36Enabled(1)[0];
    vvT36Login('admin');

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => 4])->assertOk()->assertJsonPath('homepage_order', 4);
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => 4])->assertOk();
    $audits = vvT36AdmAudits($t, 'teacher_profile.homepage_order');
    expect($audits)->toHaveCount(1)->and($audits[0]->changes)->toEqual(['homepage_order' => ['from' => null, 'to' => 4]]);
    expect(vvT36AdmAudits($t, 'teacher_profile.homepage_toggle'))->toBe([]);

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => null])->assertOk()->assertJsonPath('homepage_order', null);
    expect(vvT36AdmAudits($t, 'teacher_profile.homepage_order'))->toHaveCount(2);

    foreach ([0, 1000, -1, 'abc', 1.5, [1]] as $bad) {
        vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => $bad])->assertStatus(422)->assertJsonValidationErrors(['homepage_order']);
    }
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => 999])->assertOk();
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => 1])->assertOk();
});

test('homepage: body rong / show_on_homepage khong phai bool -> 422; gui ca hai truong ghi 2 audit', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    vvT36Login('admin');

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', [])->assertStatus(422);
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => 'maybe'])->assertStatus(422);
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => null])->assertStatus(422);
    expect(vvT36Profile($t))->toBeNull();

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => true, 'homepage_order' => 2])->assertOk();
    expect(vvT36AdmAudits($t, 'teacher_profile.homepage_toggle'))->toHaveCount(1)->and(vvT36AdmAudits($t, 'teacher_profile.homepage_order'))->toHaveCount(1);
});

test('homepage: tat cho giao vien chua co dong ho so -> 200, khong tao dong, khong audit', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    vvT36Login('admin');

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => false])->assertOk()->assertJsonPath('show_on_homepage', false);

    expect(vvT36Profile($t))->toBeNull()->and(vvT36AdmAudits($t, 'teacher_profile.homepage_toggle'))->toBe([]);
});

test('(g) du 5 action audit: update, consent, consent_withdraw, homepage_toggle, homepage_order (AC20)', function () {
    vvT36Env();
    $t = vvT36Login('teacher');
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'B'])->assertOk();
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => config('teacher_profile.consent_version')])->assertOk();
    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();
    vvStaffLogin(vvStaffUser('admin'));
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['show_on_homepage' => true, 'homepage_order' => 1])->assertOk();

    $actions = AuditLog::query()->where('subject_id', $t->id)->where('action', 'like', 'teacher_profile.%')->pluck('action')->all();
    expect($actions)->toEqualCanonicalizing([
        'teacher_profile.update', 'teacher_profile.consent', 'teacher_profile.consent_withdraw',
        'teacher_profile.homepage_toggle', 'teacher_profile.homepage_order',
    ]);
});

test('homepage/profile admin: rate limit 30/phut -> 429', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    vvT36Login('admin');

    foreach (range(1, 30) as $i) {
        vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => ($i % 5) + 1])->assertOk();
    }
    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id.'/homepage', ['homepage_order' => 9])->assertStatus(429);
});

test('Admin GET khong tao dong ho so; PATCH tao dong lan dau (tao luoi)', function () {
    vvT36Env();
    $t = User::factory()->teacher()->create();
    vvT36Login('admin');

    vvT36Json('GET', VV_T36_ADM.'/'.$t->id)->assertOk()->assertJsonPath('bio', null);
    expect(vvT36Profile($t))->toBeNull();

    vvT36Json('PATCH', VV_T36_ADM.'/'.$t->id, ['headline' => 'H'])->assertOk();
    expect(vvT36Profile($t))->not->toBeNull();
});
