<?php

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Teachers\HomepageTeacherQuery;
use App\Services\Teachers\TeacherEligibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

function vvT36HomeIds(): array
{
    return collect(vvT36Public('/home/teachers')->assertOk()->json('data'))->pluck('id')->all();
}

/** Số khóa published chưa xoá của giáo viên (đầu vào của TeacherEligibility, cùng định nghĩa với SQL trang chủ). */
function vvT36PublishedCount(User $user): int
{
    return DB::table('course_teacher as ct')->join('courses as c', 'c.id', '=', 'ct.course_id')
        ->where('ct.user_id', $user->id)->where('c.status', 'published')->whereNull('c.deleted_at')->count();
}

test('response: dung hinh dang, khong lo truong noi bo, header cache cong khai + ETag, khong cookie', function () {
    config(['app.static_url' => 'https://static.example.test']);
    $t = vvT36EligibleTeacher(['name' => 'Nguyễn Thị Lan']);
    $profile = vvT36Profile($t);
    // Hai khóa published ở lớp 9 và 10 (+ 1 khóa nháp không tính).
    $t->taughtCourses()->first()->forceFill(['grade_level' => 10])->save();
    vvT36PublishedCourse($t, ['grade_level' => 9]);
    $draft = Course::factory()->create(['grade_level' => 12]);
    DB::table('course_teacher')->insert(['course_id' => $draft->id, 'user_id' => $t->id]);

    $r = vvT36Public('/home/teachers')->assertOk();

    expect($r->json('data'))->toBe([[
        'id' => $t->id, 'name' => 'Nguyễn Thị Lan', 'headline' => $profile->headline, 'bio' => $profile->bio,
        'avatar_url' => 'https://static.example.test/'.$profile->avatar_path, 'grade_levels' => [9, 10], 'courses_count' => 2,
    ]]);
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    expect($r->headers->get('ETag'))->not->toBeNull();
    expect($r->headers->getCookies())->toBe([]);

    $raw = $r->getContent();
    foreach (['email', 'phone', 'status', 'role', 'show_on_homepage', 'consent', 'homepage_order'] as $leak) {
        expect($raw)->not->toContain($leak);
    }
    expect($raw)->not->toContain((string) $t->email);

    // ETag: gửi lại If-None-Match thì 304.
    app('auth')->forgetGuards();
    test()->getJson(vvApiUrl('/home/teachers'), ['If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
});

test('AC15/BR10: khong ai du dieu kien -> data rong 200', function () {
    vvT36Public('/home/teachers')->assertOk()->assertExactJson(['data' => []]);
});

test('route nam trong nhom catalog: throttle:catalog + Cache-Control public max-age=60 + ETag nhu /courses', function () {
    $route = app('router')->getRoutes()->getByName('api.catalog.home-teachers');
    expect($route->gatherMiddleware())->toContain('throttle:catalog');
    expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'cache.headers:public;max_age=60')))->toBeTrue();
});

/**
 * (a) Ma trận BR2: thiếu từng điều kiện thì KHÔNG có trong /home/teachers, và TeacherEligibility báo đúng lý do.
 * Mỗi ca chỉ phá MỘT điều kiện của giáo viên đủ điều kiện.
 */
dataset('vvT36BreakOne', [
    'chua dong y' => ['no_consent', fn (User $t) => vvT36SetProfile($t, ['public_consent_at' => null, 'public_consent_version' => null])],
    'chua bat trang chu' => ['not_enabled', fn (User $t) => vvT36SetProfile($t, ['show_on_homepage' => false])],
    'thieu anh' => ['no_avatar', fn (User $t) => vvT36SetProfile($t, ['avatar_path' => null])],
    'thieu bio (null)' => ['no_bio', fn (User $t) => vvT36SetProfile($t, ['bio' => null])],
    'bio rong' => ['no_bio', fn (User $t) => vvT36SetProfile($t, ['bio' => ''])],
    'tai khoan bi khoa' => ['account_locked', fn (User $t) => vvT36Lock($t)],
    'an danh hoa' => ['account_locked', fn (User $t) => vvT36SetUser($t, ['anonymized_at' => now()])],
    'doi vai tro' => ['not_teacher', fn (User $t) => vvT36SetUser($t, ['role' => UserRole::PageManager->value])],
    'khoa bi ngung ban' => ['no_published_course', fn (User $t) => Course::query()->whereIn('id', DB::table('course_teacher')->where('user_id', $t->id)->pluck('course_id'))->update(['status' => 'unpublished'])],
    'khoa bi xoa mem' => ['no_published_course', fn (User $t) => Course::query()->whereIn('id', DB::table('course_teacher')->where('user_id', $t->id)->pluck('course_id'))->update(['deleted_at' => now()])],
    'khoa nhap' => ['no_published_course', fn (User $t) => Course::query()->whereIn('id', DB::table('course_teacher')->where('user_id', $t->id)->pluck('course_id'))->update(['status' => 'draft'])],
    'khong co khoa nao' => ['no_published_course', fn (User $t) => DB::table('course_teacher')->where('user_id', $t->id)->delete()],
]);

test('(a) ma tran BR2: thieu tung dieu kien -> khong hien; TeacherEligibility khop SQL', function (string $reason, Closure $break) {
    $control = vvT36EligibleTeacher(['name' => 'Đối chứng']);
    $t = vvT36EligibleTeacher(['name' => 'Bị phá']);

    // Trước khi phá: cả hai hiện, lý do rỗng.
    expect(vvT36HomeIds())->toContain($t->id)->toContain($control->id);
    $fresh = fn (User $u) => TeacherEligibility::reasons($u->fresh(), TeacherProfile::query()->find($u->id), vvT36PublishedCount($u));
    expect($fresh($t))->toBe([]);

    $break($t);

    expect(vvT36HomeIds())->not->toContain($t->id)->toContain($control->id);
    expect($fresh($t))->toBe([$reason]);
    expect($fresh($control))->toBe([]);
})->with('vvT36BreakOne');

test('(a) TeacherEligibility khop SQL tren moi to hop 2^7 dieu kien (so khop tap hien thi)', function () {
    $matrix = [];
    foreach (range(0, 127) as $mask) {
        $t = User::factory()->teacher()->create();
        $attrs = [
            'show_on_homepage' => ($mask & 1) !== 0,
            'public_consent_at' => ($mask & 2) !== 0 ? now() : null,
            'public_consent_version' => ($mask & 2) !== 0 ? '2026-10' : null,
            'avatar_path' => ($mask & 4) !== 0 ? 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb.webp' : null,
            'bio' => ($mask & 8) !== 0 ? 'Bio' : null,
        ];
        DB::table('teacher_profiles')->insert(['user_id' => $t->id, 'created_at' => now(), 'updated_at' => now()] + $attrs);
        if (($mask & 16) === 0) {
            vvT36Lock($t);
        }
        if (($mask & 32) === 0) {
            vvT36SetUser($t, ['role' => UserRole::PageManager->value]);
        }
        if (($mask & 64) !== 0) {
            vvT36PublishedCourse($t);
        }
        $matrix[$t->id] = $mask;
    }

    // Khóa tối đa 6 người ở SQL: bỏ giới hạn để so khớp cả tập (chỉ ở test).
    config(['teacher_profile.homepage_max' => 500]);
    $visibleBySql = collect((new HomepageTeacherQuery)->get())->pluck('user.id')->all();

    $visibleByPhp = [];
    foreach (array_keys($matrix) as $id) {
        $user = User::query()->findOrFail($id);
        $reasons = TeacherEligibility::reasons($user, TeacherProfile::query()->find($id), vvT36PublishedCount($user));
        if ($reasons === []) {
            $visibleByPhp[] = $id;
        }
    }

    sort($visibleBySql);
    sort($visibleByPhp);
    expect($visibleBySql)->toBe($visibleByPhp)->and(count($visibleBySql))->toBe(1);
});

test('AC6: chua dong y nhung co anh/bio va duoc bat trang chu -> khong hien', function () {
    $t = vvT36EligibleTeacher();
    vvT36SetProfile($t, ['public_consent_at' => null, 'public_consent_version' => null]);

    expect(vvT36HomeIds())->toBe([]);
});

test('AC13/AC14: ngung ban khoa duy nhat roi co khoa published tro lai -> tu hien lai; khoa/doi vai tro -> bien mat roi tu hien lai', function () {
    $t = vvT36EligibleTeacher();
    $courseId = DB::table('course_teacher')->where('user_id', $t->id)->value('course_id');

    Course::query()->whereKey($courseId)->update(['status' => 'unpublished']);
    expect(vvT36HomeIds())->toBe([]);
    Course::query()->whereKey($courseId)->update(['status' => 'published']);
    expect(vvT36HomeIds())->toBe([$t->id]);

    vvT36Lock($t);
    expect(vvT36HomeIds())->toBe([]);
    vvT36SetUser($t, ['status' => 'active']);
    expect(vvT36HomeIds())->toBe([$t->id]);

    vvT36SetUser($t, ['role' => UserRole::PageManager->value]);
    expect(vvT36HomeIds())->toBe([]);
    vvT36SetUser($t, ['role' => UserRole::Teacher->value]);
    expect(vvT36HomeIds())->toBe([$t->id]);

    // BR9: cờ, thứ tự, đồng ý giữ nguyên suốt các lần ẩn.
    $p = vvT36Profile($t);
    expect($p->show_on_homepage)->toBeTrue()->and($p->public_consent_at)->not->toBeNull();
});

test('AC11: thu tu theo homepage_order tang dan, chua dat xep sau, cung thu tu theo id', function () {
    $nullA = vvT36EligibleTeacher();
    $o3 = vvT36EligibleTeacher(order: 3);
    $o1 = vvT36EligibleTeacher(order: 1);
    $o3b = vvT36EligibleTeacher(order: 3);
    $nullB = vvT36EligibleTeacher();

    expect(vvT36HomeIds())->toBe([$o1->id, $o3->id, $o3b->id, $nullA->id, $nullB->id]);
});

test('toi da homepage_max nguoi, cat theo thu tu (khong tra the thu 7)', function () {
    $ids = [];
    foreach (range(1, 8) as $i) {
        $ids[] = vvT36EligibleTeacher(order: $i)->id;
    }

    expect(vvT36HomeIds())->toBe(array_slice($ids, 0, 6));
});

test('giao vien dong giang nhieu khoa: dem moi khoa published mot lan, lop khac nhau tang dan', function () {
    $t = vvT36EligibleTeacher(courseAttrs: ['grade_level' => 11]);
    vvT36PublishedCourse($t, ['grade_level' => 7]);
    vvT36PublishedCourse($t, ['grade_level' => 7]);
    // Khóa của người khác (đồng giảng) không ảnh hưởng.
    $other = User::factory()->teacher()->create();
    $shared = vvT36PublishedCourse($t, ['grade_level' => 8]);
    DB::table('course_teacher')->insert(['course_id' => $shared->id, 'user_id' => $other->id]);

    $row = vvT36Public('/home/teachers')->json('data.0');

    expect($row['courses_count'])->toBe(4)->and($row['grade_levels'])->toBe([7, 8, 11]);
});

test('hieu nang: dung 2 cau SQL cho /home/teachers du co nhieu giao vien (khong N+1)', function () {
    foreach (range(1, 6) as $i) {
        vvT36EligibleTeacher(order: $i);
    }

    DB::enableQueryLog();
    $r = vvT36Public('/home/teachers')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'teacher_profiles') || str_contains($q, 'course_teacher'));
    DB::disableQueryLog();

    expect($r->json('data'))->toHaveCount(6);
    expect($queries->count())->toBe(2);
});

test('rut dong y o /home/teachers: lan goi ke tiep khong con giao vien do (AC7)', function () {
    vvT36Env();
    $teacher = vvT36Login('teacher');
    vvT36PublishedCourse($teacher);
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Bio', 'headline' => 'Toán'])->assertOk();
    vvT36Upload('/admin/me/teacher-profile/avatar', UploadedFile::fake()->image('a.jpg', 300, 300))->assertOk();
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => config('teacher_profile.consent_version')])->assertOk();
    DB::table('teacher_profiles')->where('user_id', $teacher->id)->update(['show_on_homepage' => true]);

    expect(vvT36HomeIds())->toBe([$teacher->id]);

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();
    expect(vvT36HomeIds())->toBe([]);

    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => config('teacher_profile.consent_version')])->assertOk();
    expect(vvT36HomeIds())->toBe([$teacher->id]);
});

test('AC12: nhieu giao vien du dieu kien -> tra du the voi day du truong', function () {
    $ts = [vvT36EligibleTeacher(order: 1), vvT36EligibleTeacher(order: 2), vvT36EligibleTeacher(order: 3)];

    $data = vvT36Public('/home/teachers')->json('data');

    expect($data)->toHaveCount(3);
    foreach ($data as $i => $row) {
        expect(array_keys($row))->toBe(['id', 'name', 'headline', 'bio', 'avatar_url', 'grade_levels', 'courses_count']);
        expect($row['id'])->toBe($ts[$i]->id)->and($row['avatar_url'])->not->toBeNull()->and($row['bio'])->not->toBeNull();
    }
});
