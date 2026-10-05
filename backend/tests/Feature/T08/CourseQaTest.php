<?php

use App\Enums\CourseStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

// QA bổ sung cho T08 (US-009): biên, phân quyền chéo, hiệu năng, vòng đời với catalog công khai.

test('QA phan quyen: khach 401 tren moi route T08, hoc sinh 403 tren moi route T08', function () {
    $course = Course::factory()->create();
    $routes = [
        ['GET', '/admin/teachers'], ['GET', '/admin/courses'], ['POST', '/admin/courses'],
        ['GET', "/admin/courses/{$course->id}"], ['PUT', "/admin/courses/{$course->id}"],
        ['DELETE', "/admin/courses/{$course->id}"], ['POST', "/admin/courses/{$course->id}/publish"],
        ['POST', "/admin/courses/{$course->id}/unpublish"], ['PATCH', "/admin/courses/{$course->id}/manual-order"],
        ['PUT', "/admin/courses/{$course->id}/teachers"],
    ];

    foreach ($routes as [$method, $path]) {
        vvCourseJson($method, $path, [])->assertStatus(401);
    }

    test()->actingAs(User::factory()->student()->create());
    foreach ($routes as [$method, $path]) {
        vvCourseJson($method, $path, [])->assertStatus(403);
    }

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});

test('QA phan quyen: giao vien khoa la gui anh/payload sai -> 403 va khong sinh file', function () {
    $a = vvStaffUser('teacher');
    $courseB = vvAssign(Course::factory()->create(), vvStaffUser('teacher'));
    Storage::fake('uploads');
    vvStaffLogin($a);

    vvCoursePost("/admin/courses/{$courseB->id}", [
        'thumbnail' => UploadedFile::fake()->image('x.jpg', 100, 100), 'price' => 'abc', 'title' => '<b>',
    ], 'PUT')->assertStatus(403);

    expect(Storage::disk('uploads')->allFiles())->toBe([]);
});

test('QA phan quyen: giao vien khong loc duoc theo teacher_id cua nguoi khac, khong thay khoa la', function () {
    $a = vvStaffUser('teacher');
    $b = vvStaffUser('teacher');
    $mine = vvAssign(Course::factory()->create(), $a);
    $others = vvAssign(Course::factory()->create(), $b);
    Storage::fake('uploads');
    vvStaffLogin($a);

    $ids = collect(vvAdminGet("/admin/courses?teacher_id={$b->id}")->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$mine->id])->not->toContain($others->id);
});

test('QA bien: gia 0 va 50.000.000 hop le; 50.000.001, 12.5, chu, 1e3 -> 422', function () {
    $actor = vvCourseActor();
    $teacher = vvStaffUser('teacher');

    foreach ([0, 50_000_000] as $ok) {
        vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'price' => $ok]))
            ->assertCreated()->assertJsonPath('price', $ok);
    }
    foreach ([50_000_001, '12.5', 'abc', '1e3', '-1', ''] as $bad) {
        $before = Course::query()->count();
        vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'price' => $bad]))
            ->assertStatus(422)->assertJsonValidationErrors('price');
        expect(Course::query()->count())->toBe($before, 'price='.var_export($bad, true));
    }
    expect(Course::query()->count())->toBe(2)->and($actor)->not->toBeNull();
});

test('QA bien: title 255 ky tu OK, 256 -> 422; title tieng Viet co dau + d sinh slug; title chi ky tu la van co slug', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');

    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'title' => str_repeat('a', 255)]))->assertCreated();
    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'title' => str_repeat('a', 256)]))
        ->assertStatus(422)->assertJsonValidationErrors('title');

    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'title' => 'Đại số & Hình học: Đỉnh cao']))
        ->assertCreated()->assertJsonPath('slug', 'dai-so-hinh-hoc-dinh-cao');

    $res = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'title' => '!!!']))->assertCreated();
    expect($res->json('slug'))->not->toBe('')->and($res->json('slug'))->toStartWith('khoa-hoc');
});

test('QA bien: description 100000 ky tu OK, 100001 -> 422; short_description 500/501', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');

    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'short_description' => str_repeat('b', 501)]))
        ->assertStatus(422)->assertJsonValidationErrors('short_description');
    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'short_description' => str_repeat('b', 500)]))->assertCreated();
    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'description' => str_repeat('c', 100001)]))
        ->assertStatus(422)->assertJsonValidationErrors('description');
});

test('QA bien: subject_ids trung lap, qua 20, gia tri chuoi -> 422; teacher_ids trung lap -> 422', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');
    $s = Subject::factory()->create();

    foreach ([[$s->id, $s->id], ['x'], [], 'abc'] as $bad) {
        vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id], 'subject_ids' => $bad]))->assertStatus(422);
    }
    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id, $teacher->id]]))->assertStatus(422);
    expect(Course::query()->count())->toBe(0);
});

test('QA anh: file khong phai ma gui kieu chuoi, mang file, hoac thieu -> 422, khong luu file', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');
    $payload = vvCoursePayload(['teacher_ids' => [$teacher->id]]);

    unset($payload['thumbnail']);
    vvCoursePost('/admin/courses', $payload)->assertStatus(422)->assertJsonValidationErrors('thumbnail');
    expect(Storage::disk('uploads')->allFiles())->toBe([], 'thieu anh');
    vvCoursePost('/admin/courses', $payload + ['thumbnail' => '../../etc/passwd'])->assertStatus(422)->assertJsonValidationErrors('thumbnail');
    expect(Storage::disk('uploads')->allFiles())->toBe([], 'chuoi');
    vvCoursePost('/admin/courses', $payload + ['thumbnail' => [UploadedFile::fake()->image('a.jpg')]])->assertStatus(422);

    expect(Storage::disk('uploads')->allFiles())->toBe([])->and(Course::query()->count())->toBe(0);
});

test('QA anh: gui nhieu thumbnail (field la) hoac file ten co duong dan khong ghi ra ngoai disk, ten luu van la UUID', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');

    $res = vvCoursePost('/admin/courses', vvCoursePayload([
        'teacher_ids' => [$teacher->id],
        'thumbnail' => UploadedFile::fake()->image('../../../public/shell.php.jpg', 300, 300),
    ]))->assertCreated();

    $path = Course::query()->findOrFail($res->json('id'))->thumbnail_path;
    expect($path)->toMatch('/^[0-9a-f-]{36}\.webp$/')->and(Storage::disk('uploads')->allFiles())->toBe([$path]);
});

test('QA AC2: publish -> hien tren danh muc cong khai; unpublish -> khach khong thay, hoc sinh da ghi danh van vao duoc', function () {
    $admin = vvCourseActor();
    $course = vvCourseWithContent();
    $slug = $course->slug;

    test()->getJson('http://api.localhost/api/v1/courses/'.$slug)->assertNotFound();

    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertOk()->assertJsonPath('status', 'published');
    $first = $course->fresh()->published_at;
    expect($first)->not->toBeNull();

    // chu y: publish -> unpublish -> publish giu nguyen published_at lan dau
    vvCourseJson('POST', "/admin/courses/{$course->id}/unpublish")->assertOk()->assertJsonPath('status', 'unpublished');
    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertOk();
    expect($course->fresh()->published_at->equalTo($first))->toBeTrue();
    expect($admin)->not->toBeNull();
});

test('QA AC4: khoa co don pending/rejected cung chan xoa (409); xoa roi ghi danh moi khong con', function () {
    vvCourseActor();

    foreach (['pending', 'rejected', 'active'] as $state) {
        $course = vvCourseWithContent();
        vvEnroll($course, $state);
        vvCourseJson('DELETE', "/admin/courses/{$course->id}")->assertStatus(409)->assertJsonPath('code', 'COURSE_HAS_ENROLLMENTS');
        expect(Course::query()->whereKey($course->id)->exists())->toBeTrue()
            ->and(Chapter::query()->where('course_id', $course->id)->count())->toBe(1);
    }
});

test('QA AC5: xoa khoa that su an chuong/bai khoi catalog admin va 404 khi xem/sua/publish', function () {
    vvCourseActor();
    $course = vvCourseWithContent();

    vvCourseJson('DELETE', "/admin/courses/{$course->id}")->assertNoContent();

    expect(Lesson::query()->where('course_id', $course->id)->count())->toBe(0)
        ->and(Lesson::withTrashed()->where('course_id', $course->id)->count())->toBe(1);
    vvAdminGet("/admin/courses/{$course->id}")->assertNotFound();
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['title' => 'x'])->assertNotFound();
    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertNotFound();
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [1]])->assertNotFound();
    expect(collect(vvAdminGet('/admin/courses')->json('data'))->pluck('id')->all())->not->toContain($course->id);
});

test('QA teachers: khoa khong ton tai/id la -> 404; role admin/hoc sinh/locked bi tu choi; giu GV bi khoa da gan', function () {
    vvCourseActor();
    $course = vvAssign(Course::factory()->create(), $t = vvStaffUser('teacher'));
    $locked = vvStaffUser('teacher', ['status' => 'locked']);
    $student = User::factory()->student()->create();

    vvCourseJson('PUT', '/admin/courses/999999/teachers', ['teacher_ids' => [$t->id]])->assertNotFound();
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$locked->id]])
        ->assertStatus(422)->assertJsonValidationErrors('teacher_ids');
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$student->id]])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => 'x'])->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$t->id, 0]])->assertStatus(422);

    expect(DB::table('course_teacher')->where('course_id', $course->id)->pluck('user_id')->all())->toBe([$t->id]);
});

test('QA N+1: danh sach admin co so truy van khong tang theo so khoa', function () {
    $teacher = vvStaffUser('teacher');
    Storage::fake('uploads');
    $subject = Subject::factory()->create();
    foreach (range(1, 3) as $_) {
        $c = vvAssign(Course::factory()->create(), $teacher, vvStaffUser('teacher'));
        $c->subjects()->attach($subject);
    }
    vvStaffLogin(vvStaffUser('admin'));

    DB::enableQueryLog();
    $fewRows = count(vvAdminGet('/admin/courses')->assertOk()->json('data'));
    $few = count(DB::getQueryLog());

    foreach (range(1, 20) as $_) {
        $c = vvAssign(Course::factory()->create(), $teacher, vvStaffUser('teacher'));
        $c->subjects()->attach($subject);
    }
    DB::flushQueryLog();
    vvAdminGet('/admin/courses?per_page=25')->assertOk()->assertJsonCount($fewRows + 20, 'data');
    $many = count(DB::getQueryLog());

    expect($many)->toBeLessThanOrEqual($few + 1)
        ->and(collect(vvAdminGet('/admin/courses')->json('data.0'))->keys()->all())->not->toContain('description');
});

test('QA chi tiet: khong lo duong dan noi bo/email giao vien trong CourseResource', function () {
    vvCourseActor();
    $t = vvStaffUser('teacher');
    $course = vvAssign(Course::factory()->create(['thumbnail_path' => 'abc.webp']), $t);

    $json = vvAdminGet("/admin/courses/{$course->id}")->assertOk()->getContent();

    expect($json)->not->toContain($t->email)->not->toContain('thumbnail_path')->not->toContain('search_text')
        ->not->toContain('password');
});

test('QA sua: thay doi dong thoi khong de hai ban ghi anh; sua khong hop le khong xoa anh cu', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');
    $id = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id]]))->assertCreated()->json('id');
    $old = Course::query()->findOrFail($id)->thumbnail_path;

    vvCoursePost("/admin/courses/{$id}", ['thumbnail' => UploadedFile::fake()->image('b.png', 50, 50), 'price' => -1], 'PUT')
        ->assertStatus(422);
    expect(Storage::disk('uploads')->allFiles())->toBe([$old]);

    vvCoursePost("/admin/courses/{$id}", ['thumbnail' => UploadedFile::fake()->image('b.png', 50, 50)], 'PUT')->assertOk();
    $new = Course::query()->findOrFail($id)->thumbnail_path;
    expect($new)->not->toBe($old)->and(Storage::disk('uploads')->allFiles())->toBe([$new]);
});
