<?php

use App\Enums\CourseStatus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

test('chua dang nhap: 401; hoc sinh: bi chan', function () {
    vvAdminGet('/admin/courses')->assertUnauthorized();

    test()->actingAs(User::factory()->student()->create());
    vvAdminGet('/admin/courses')->assertStatus(403);
});

test('AC1: staff tao khoa hoc, mac dinh draft, slug, anh webp, nhieu giao vien, chuyen de, audit', function (string $state) {
    $actor = vvCourseActor($state);
    [$t1, $t2] = [vvStaffUser('teacher'), vvStaffUser('teacher')];

    $response = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$t1->id, $t2->id], 'status' => 'published', 'slug' => 'hack', 'created_by' => 999]))
        ->assertCreated()
        ->assertJsonPath('title', 'Toán 9 nâng cao')
        ->assertJsonPath('slug', 'toan-9-nang-cao')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('price', 299000)
        ->assertJsonPath('created_by', $actor->id)
        ->assertJsonCount(2, 'teachers')
        ->assertJsonCount(1, 'subjects')
        ->assertJsonPath('abilities.publish', true);

    $course = Course::query()->findOrFail($response->json('id'));
    expect($course->status)->toBe(CourseStatus::Draft)
        ->and($course->slug)->toBe('toan-9-nang-cao')
        ->and($course->published_at)->toBeNull()
        ->and($course->thumbnail_path)->toMatch('/^[0-9a-f-]{36}\.webp$/')
        ->and($course->search_text)->toContain('toan 9 nang cao');
    Storage::disk('uploads')->assertExists($course->thumbnail_path);
    expect($response->json('thumbnail_url'))->toEndWith('/'.$course->thumbnail_path);

    $log = AuditLog::query()->where('action', 'course.create')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($actor->id)->and($log->subject_id)->toBe($course->id);
})->with(['admin', 'pageManager']);

test('slug trung (ke ca khoa da xoa mem) them hau to', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');
    Course::factory()->create(['title' => 'Toán 9 nâng cao', 'slug' => 'toan-9-nang-cao'])->delete();

    vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$teacher->id]]))
        ->assertCreated()->assertJsonPath('slug', 'toan-9-nang-cao-2');
});

test('AC9: giao vien tu tao khoa: draft, tu la giao vien phu trach, teacher_ids bi bo qua', function () {
    Storage::fake('uploads');
    $teacher = vvStaffUser('teacher');
    $other = vvStaffUser('teacher');
    vvStaffLogin($teacher);

    $response = vvCoursePost('/admin/courses', vvCoursePayload(['teacher_ids' => [$other->id]]))
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonCount(1, 'teachers')
        ->assertJsonPath('teachers.0.id', $teacher->id)
        ->assertJsonPath('abilities.publish', false)
        ->assertJsonPath('abilities.edit_price', false);

    $course = Course::query()->findOrFail($response->json('id'));
    expect($course->teachers()->pluck('users.id')->all())->toBe([$teacher->id])
        ->and($course->created_by)->toBe($teacher->id);

    // và thấy khóa này trong danh sách của mình
    vvAdminGet('/admin/courses')->assertOk()->assertJsonPath('data.0.id', $course->id);
});

test('validate tao: thieu truong, hoc phi am/chu/qua tran, lop ngoai 6-12, ten co HTML, chuyen de an/khong ton tai', function () {
    vvCourseActor();
    $teacher = vvStaffUser('teacher');
    $hidden = Subject::factory()->hidden()->create();
    $ok = vvCoursePayload(['teacher_ids' => [$teacher->id]]);

    vvCoursePost('/admin/courses', [])->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'grade_level', 'description', 'price', 'thumbnail', 'subject_ids', 'teacher_ids']);

    foreach ([
        ['price', -1], ['price', 'abc'], ['price', 50_000_001], ['price', 1.5],
        ['grade_level', 5], ['grade_level', 13],
        ['title', '<b>x</b>'], ['title', str_repeat('a', 256)],
        ['short_description', '<script>x</script>'],
        ['subject_ids', [$hidden->id]], ['subject_ids', [999999]], ['subject_ids', []],
        ['teacher_ids', []], ['teacher_ids', [999999]],
    ] as [$field, $value]) {
        $res = vvCoursePost('/admin/courses', [...$ok, 'thumbnail' => UploadedFile::fake()->image('a.jpg'), $field => $value])
            ->assertStatus(422);
        expect(collect(array_keys($res->json('errors')))->contains(fn ($k) => $k === $field || str_starts_with($k, $field.'.')))
            ->toBeTrue("{$field} => ".json_encode($value));
    }

    expect(Course::query()->where('title', 'Toán 9 nâng cao')->count())->toBe(0);
});

test('gan khong phai giao vien (hoc sinh, admin) -> 422 o tung phan tu; giao vien bi khoa them moi -> 422', function () {
    vvCourseActor();
    $base = vvCoursePayload();

    foreach ([User::factory()->student()->create(), vvStaffUser('admin')] as $bad) {
        vvCoursePost('/admin/courses', [...$base, 'thumbnail' => UploadedFile::fake()->image('a.jpg'), 'teacher_ids' => [$bad->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['teacher_ids.0']);
    }

    $locked = vvStaffUser('teacher', ['status' => 'locked']);
    vvCoursePost('/admin/courses', [...$base, 'thumbnail' => UploadedFile::fake()->image('a.jpg'), 'teacher_ids' => [$locked->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['teacher_ids']);
});

test('R5: giao vien da gan roi bi khoa van duoc giu khi gui lai danh sach; chi chan them moi nguoi bi khoa', function () {
    vvCourseActor();
    $keep = vvStaffUser('teacher');
    $course = vvAssign(Course::factory()->create(), $keep);
    $keep->forceFill(['status' => 'locked'])->save();
    $newLocked = vvStaffUser('teacher', ['status' => 'locked']);
    $ok = vvStaffUser('teacher');

    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$keep->id, $ok->id]])
        ->assertOk()->assertJsonCount(2, 'teachers');
    vvCourseJson('PUT', "/admin/courses/{$course->id}/teachers", ['teacher_ids' => [$keep->id, $ok->id, $newLocked->id]])
        ->assertStatus(422);
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['title' => 'Vẫn sửa được', 'teacher_ids' => [$keep->id, $ok->id]])->assertOk();
    expect($course->teachers()->count())->toBe(2);
});

test('AC6/AC10: giao vien chi thay khoa minh phu trach; staff thay het; loc va tim kiem khong dau', function () {
    $teacher = vvStaffUser('teacher');
    $mine = vvAssign(Course::factory()->create(['title' => 'Hình học phẳng']), $teacher);
    $coTeach = vvAssign(Course::factory()->create(['title' => 'Đại số']), $teacher, vvStaffUser('teacher'));
    $notMine = Course::factory()->create(['title' => 'Hình học không gian']);
    Storage::fake('uploads');

    vvStaffLogin($teacher);
    $ids = collect(vvAdminGet('/admin/courses')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([$mine->id, $coTeach->id])
        ->and($ids)->not->toContain($notMine->id);
    vvAdminGet('/admin/courses?q=hinh hoc')->assertOk()->assertJsonCount(1, 'data');

    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);
    $all = collect(vvAdminGet('/admin/courses?per_page=50')->assertOk()->json('data'))->pluck('id');
    expect($all->contains($mine->id) && $all->contains($coTeach->id) && $all->contains($notMine->id))->toBeTrue();
    $hh = collect(vvAdminGet('/admin/courses?q=Hình học')->assertOk()->json('data'))->pluck('id')->all();
    expect($hh)->toContain($mine->id, $notMine->id)->not->toContain($coTeach->id);
    vvAdminGet('/admin/courses?q=100%25')->assertOk()->assertJsonPath('meta.total', 0);
    vvAdminGet('/admin/courses?status=published&q=Hình học')->assertOk()->assertJsonPath('meta.total', 0);
    vvAdminGet('/admin/courses?per_page=7')->assertStatus(422);
    vvAdminGet('/admin/courses?status=zzz')->assertStatus(422);
});

test('hien thi chi tiet: description loc lai khi tra ra, staff thay manual_order, giao vien khong', function () {
    $teacher = vvStaffUser('teacher');
    $course = vvAssign(Course::factory()->create(['description' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>']), $teacher);
    Course::query()->whereKey($course->id)->update(['manual_order' => 3]);
    Storage::fake('uploads');

    vvStaffLogin($teacher);
    $res = vvAdminGet("/admin/courses/{$course->id}")->assertOk();
    expect($res->json('description'))->toBe('<p>ok</p>')->and($res->json())->not->toHaveKey('manual_order');

    vvStaffLogin(vvStaffUser('admin'));
    vvAdminGet("/admin/courses/{$course->id}")->assertOk()->assertJsonPath('manual_order', 3)
        ->assertJsonStructure(['chapters_count', 'lessons_count', 'abilities']);
    vvAdminGet('/admin/courses/999999')->assertNotFound();
    vvAdminGet("/admin/courses/{$course->id}/")->assertOk();
});

test('AC7: giao vien khoa A khong xem/sua/xoa/publish/gan GV khoa B -> 403', function () {
    $a = vvStaffUser('teacher');
    $b = vvStaffUser('teacher');
    $courseB = vvAssign(Course::factory()->create(), $b);
    Storage::fake('uploads');
    vvStaffLogin($a);

    vvAdminGet("/admin/courses/{$courseB->id}")->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    vvCourseJson('PUT', "/admin/courses/{$courseB->id}", ['title' => 'Hacked'])->assertStatus(403);
    vvCourseJson('PUT', "/admin/courses/{$courseB->id}", ['title' => ''])->assertStatus(403); // quyền trước validate
    vvCourseJson('DELETE', "/admin/courses/{$courseB->id}")->assertStatus(403);
    vvCourseJson('POST', "/admin/courses/{$courseB->id}/publish")->assertStatus(403);
    vvCourseJson('POST', "/admin/courses/{$courseB->id}/unpublish")->assertStatus(403);
    vvCourseJson('PUT', "/admin/courses/{$courseB->id}/teachers", ['teacher_ids' => [$a->id]])->assertStatus(403);
    vvCourseJson('PATCH', "/admin/courses/{$courseB->id}/manual-order", ['manual_order' => 1])->assertStatus(403);

    expect($courseB->fresh()->title)->not->toBe('Hacked');
});

test('giao vien duoc gan: sua noi dung, nhung price/status/manual_order/teacher_ids/slug bi bo qua; khong xoa/publish', function () {
    $teacher = vvStaffUser('teacher');
    $course = vvAssign(Course::factory()->paid(100000)->create(['grade_level' => 7]), $teacher);
    Storage::fake('uploads');
    vvStaffLogin($teacher);

    vvCourseJson('PUT', "/admin/courses/{$course->id}", [
        'title' => 'Tên mới', 'description' => '<p>Mô tả mới</p>', 'grade_level' => 8,
        'price' => 1, 'status' => 'published', 'manual_order' => 1, 'slug' => 'x', 'created_by' => 5,
        'teacher_ids' => [vvStaffUser('teacher')->id],
    ])->assertOk()->assertJsonPath('title', 'Tên mới')->assertJsonPath('grade_level', 8);

    $fresh = $course->fresh();
    expect($fresh->price)->toBe(100000)->and($fresh->status)->toBe(CourseStatus::Draft)
        ->and($fresh->manual_order)->toBeNull()->and($fresh->slug)->toBe($course->slug)
        ->and($fresh->teachers()->count())->toBe(1)
        ->and($fresh->search_text)->toContain('ten moi');

    vvCourseJson('DELETE', "/admin/courses/{$course->id}")->assertStatus(403);
    vvCourseJson('POST', "/admin/courses/{$course->id}/publish")->assertStatus(403);
});

test('giao vien khong doi duoc lop khi khoa da tung xuat ban (bo qua); staff doi duoc + audit gia', function () {
    $teacher = vvStaffUser('teacher');
    $course = vvAssign(Course::factory()->published()->paid(100000)->create(['grade_level' => 7]), $teacher);
    Storage::fake('uploads');

    vvStaffLogin($teacher);
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['grade_level' => 12, 'title' => 'Mới'])->assertOk()->assertJsonPath('grade_level', 7);

    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['grade_level' => 12, 'price' => 150000])
        ->assertOk()->assertJsonPath('grade_level', 12)->assertJsonPath('price', 150000);

    $log = AuditLog::query()->where('action', 'course.price_change')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($course->id)
        ->and($log->changes['price'])->toEqual(['from' => 100000, 'to' => 150000]);
    expect(AuditLog::query()->where('action', 'course.update')->where('subject_id', $course->id)->count())->toBe(2);
});

test('sua chuyen de + giu nguyen truong khong gui; price am -> 422; teacher_ids rong -> 422', function () {
    vvCourseActor();
    $course = vvAssign(Course::factory()->paid(1000)->create(), $t = vvStaffUser('teacher'));
    $s1 = Subject::factory()->create();
    $s2 = Subject::factory()->create();

    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['subject_ids' => [$s1->id, $s2->id]])
        ->assertOk()->assertJsonCount(2, 'subjects')->assertJsonPath('price', 1000);
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['subject_ids' => [$s2->id]])->assertOk()->assertJsonCount(1, 'subjects');

    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['price' => -5])->assertStatus(422)->assertJsonValidationErrors('price');
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['title' => null])->assertStatus(422)->assertJsonValidationErrors('title');
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['teacher_ids' => []])->assertStatus(422)->assertJsonValidationErrors('teacher_ids');
    expect($course->fresh()->price)->toBe(1000);
});

test('manual-order: staff dat so/null; am hoac thieu khoa -> 422', function () {
    $admin = vvCourseActor();
    $course = Course::factory()->create();

    vvCourseJson('PATCH', "/admin/courses/{$course->id}/manual-order", ['manual_order' => 5])->assertOk()->assertJsonPath('manual_order', 5);
    vvCourseJson('PATCH', "/admin/courses/{$course->id}/manual-order", ['manual_order' => null])->assertOk()->assertJsonPath('manual_order', null);
    vvCourseJson('PATCH', "/admin/courses/{$course->id}/manual-order", ['manual_order' => -1])->assertStatus(422);
    vvCourseJson('PATCH', "/admin/courses/{$course->id}/manual-order", [])->assertStatus(422);

    expect(AuditLog::query()->where('action', 'course.manual_order')->where('actor_id', $admin->id)->count())->toBe(2);
});

test('GET /admin/teachers: staff thay id+name giao vien dang hoat dong; giao vien 403', function () {
    vvCourseActor();
    $t = vvStaffUser('teacher', ['name' => 'Cô Lan']);
    vvStaffUser('teacher', ['name' => 'Thầy Khoá', 'status' => 'locked']);
    User::factory()->student()->create();

    $res = vvAdminGet('/admin/teachers')->assertOk();
    expect($res->json('data'))->toContain(['id' => $t->id, 'name' => 'Cô Lan'])
        ->and(collect($res->json('data'))->pluck('name'))->not->toContain('Thầy Khoá');
    $res->assertJsonMissingPath('data.0.email');
    expect(vvAdminGet('/admin/teachers?q=Cô Lan')->json('data'))->toContain(['id' => $t->id, 'name' => 'Cô Lan']);

    vvStaffLogin(vvStaffUser('teacher'));
    vvAdminGet('/admin/teachers')->assertStatus(403);
});
