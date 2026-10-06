<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

// ---------------------------------------------------------------- GET /courses/{slug} (AC5, AC7, AC19, BR5)

test('AC5/AC19: giao vien chua dong y -> teachers[] chi co id, name; avatar_url va bio null (key van co)', function () {
    config(['app.static_url' => 'https://static.example.test']);
    $teacher = User::factory()->teacher()->create(['name' => 'Cô Lan']);
    // Có ảnh + bio + bật trang chủ nhưng CHƯA đồng ý.
    DB::table('teacher_profiles')->insert([
        'user_id' => $teacher->id, 'headline' => 'Toán', 'bio' => 'Bio riêng tư', 'avatar_path' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.webp',
        'show_on_homepage' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $course = vvT36PublishedCourse($teacher);

    $r = vvT36Public('/courses/'.$course->slug)->assertOk();

    expect($r->json('teachers'))->toBe([['id' => $teacher->id, 'name' => 'Cô Lan', 'bio' => null, 'avatar_url' => null]]);
    expect($r->getContent())->not->toContain('Bio riêng tư')->not->toContain('aaaaaaaa-aaaa');
});

test('giao vien moi tao chua co dong ho so van tra null (AC19)', function () {
    $teacher = User::factory()->teacher()->create();
    $course = vvT36PublishedCourse($teacher);

    vvT36Public('/courses/'.$course->slug)->assertOk()
        ->assertJsonPath('teachers.0.bio', null)->assertJsonPath('teachers.0.avatar_url', null);
});

test('da dong y -> tra bio va avatar_url; khong lo headline o chi tiet khoa, khong lo email', function () {
    config(['app.static_url' => 'https://static.example.test']);
    $teacher = User::factory()->teacher()->withPublicProfile()->create();
    $course = vvT36PublishedCourse($teacher);
    $profile = vvT36Profile($teacher);

    $r = vvT36Public('/courses/'.$course->slug)->assertOk();

    expect($r->json('teachers.0'))->toBe([
        'id' => $teacher->id, 'name' => $teacher->name, 'bio' => $profile->bio,
        'avatar_url' => 'https://static.example.test/'.$profile->avatar_path,
    ]);
    expect($r->getContent())->not->toContain((string) $teacher->email);
});

test('AC7(b): rut dong y -> lan goi ke tiep tra null NGAY o chi tiet khoa; dong y lai thi hien lai', function () {
    vvT36Env();
    $teacher = vvT36Login('teacher');
    $course = vvT36PublishedCourse($teacher);
    vvT36Json('PATCH', '/admin/me/teacher-profile', ['bio' => 'Giới thiệu của tôi'])->assertOk();
    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => config('teacher_profile.consent_version')])->assertOk();

    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe('Giới thiệu của tôi');

    vvT36Json('DELETE', '/admin/me/teacher-profile/consent')->assertOk();
    $r = vvT36Public('/courses/'.$course->slug)->assertOk();
    expect($r->json('teachers.0.bio'))->toBeNull()->and($r->json('teachers.0.avatar_url'))->toBeNull();
    // Nội dung hồ sơ vẫn còn trong DB (AC7).
    expect(vvT36Profile($teacher)->bio)->toBe('Giới thiệu của tôi');

    vvT36Json('POST', '/admin/me/teacher-profile/consent', ['version' => config('teacher_profile.consent_version')])->assertOk();
    expect(vvT36Public('/courses/'.$course->slug)->json('teachers.0.bio'))->toBe('Giới thiệu của tôi');
});

test('user da doi vai tro nhung con ho so da dong y -> chi tiet khoa tra null (PublicTeacher kiem role)', function () {
    $teacher = User::factory()->teacher()->withPublicProfile()->create();
    $course = vvT36PublishedCourse($teacher);
    vvT36SetUser($teacher, ['role' => UserRole::PageManager->value]);

    vvT36Public('/courses/'.$course->slug)->assertOk()
        ->assertJsonPath('teachers.0.bio', null)->assertJsonPath('teachers.0.avatar_url', null);
});

test('chi tiet khoa nhieu giao vien: ten nao cung hien, ai dong y moi co bio, khong N+1', function () {
    $a = User::factory()->teacher()->withPublicProfile()->create(['name' => 'A']);
    $b = User::factory()->teacher()->create(['name' => 'B']);
    $c = User::factory()->teacher()->withPublicProfile()->create(['name' => 'C']);
    $course = vvT36PublishedCourse($a);
    foreach ([$b, $c] as $t) {
        DB::table('course_teacher')->insert(['course_id' => $course->id, 'user_id' => $t->id, 'created_at' => now()->addSecond()]);
    }

    DB::enableQueryLog();
    $r = vvT36Public('/courses/'.$course->slug)->assertOk();
    $teacherProfileQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'teacher_profiles'))->count();
    DB::disableQueryLog();

    expect(collect($r->json('teachers'))->pluck('name')->sort()->values()->all())->toBe(['A', 'B', 'C']);
    expect(collect($r->json('teachers'))->firstWhere('name', 'B')['bio'])->toBeNull();
    expect(collect($r->json('teachers'))->firstWhere('name', 'A')['bio'])->not->toBeNull();
    expect($teacherProfileQueries)->toBe(1);
});

// ---------------------------------------------------------------- GET /courses?teacher_id= (h)

test('(h) teacher_id loc dung khoa cua giao vien, ket hop bo loc khac, id la -> rong, sai kieu -> 422, links giu tham so', function () {
    $t1 = User::factory()->teacher()->create();
    $t2 = User::factory()->teacher()->create();
    $c1 = vvT36PublishedCourse($t1, ['grade_level' => 9]);
    $c1b = vvT36PublishedCourse($t1, ['grade_level' => 10]);
    $c2 = vvT36PublishedCourse($t2, ['grade_level' => 9]);
    $draft = Course::factory()->create(['grade_level' => 9]);
    DB::table('course_teacher')->insert(['course_id' => $draft->id, 'user_id' => $t1->id]);

    $slugs = fn ($r) => collect($r->json('data'))->pluck('slug')->sort()->values()->all();

    expect($slugs(vvT36Public("/courses?teacher_id={$t1->id}")->assertOk()))->toBe(collect([$c1->slug, $c1b->slug])->sort()->values()->all());
    expect($slugs(vvT36Public("/courses?teacher_id={$t2->id}")))->toBe([$c2->slug]);
    expect($slugs(vvT36Public("/courses?teacher_id={$t1->id}&grade=9")))->toBe([$c1->slug]);

    // Id không tồn tại, hoặc là học sinh: rỗng, không lỗi.
    $student = User::factory()->create();
    foreach ([999999, $student->id] as $id) {
        vvT36Public("/courses?teacher_id={$id}")->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    foreach (['abc', '0', '-3', '1.5', '[]'] as $bad) {
        vvT36Public('/courses?teacher_id='.$bad)->assertStatus(422);
    }
    vvT36Public('/courses?teacher_id[]=1')->assertStatus(422);

    // links giữ teacher_id (đủ khóa để có trang 2).
    foreach (range(1, 26) as $i) {
        vvT36PublishedCourse($t2);
    }
    $page1 = vvT36Public("/courses?teacher_id={$t2->id}")->assertOk();
    expect($page1->json('links.next'))->toContain("teacher_id={$t2->id}");
    $page2 = vvT36Public($page1->json('links.next') === null ? '' : str_replace('/api/v1', '', $page1->json('links.next')))->assertOk();
    expect($page2->json('meta.current_page'))->toBe(2)->and($page2->json('meta.total'))->toBe(27);
});

test('teacher_id: danh sach khoa chi tra id, name cua giao vien (khong bio/avatar)', function () {
    $t = User::factory()->teacher()->withPublicProfile()->create();
    vvT36PublishedCourse($t);

    $r = vvT36Public("/courses?teacher_id={$t->id}")->assertOk();

    expect($r->json('data.0.teachers.0'))->toBe(['id' => $t->id, 'name' => $t->name]);
});

// ---------------------------------------------------------------- không rò audit

test('doc API cong khai khong sinh audit', function () {
    $before = AuditLog::query()->count();
    $t = vvT36EligibleTeacher();
    $course = $t->taughtCourses()->first();
    $base = AuditLog::query()->count();

    vvT36Public('/home/teachers')->assertOk();
    vvT36Public('/courses/'.$course->slug)->assertOk();

    expect(AuditLog::query()->count())->toBe($base)->and($base)->toBeGreaterThanOrEqual($before);
});
