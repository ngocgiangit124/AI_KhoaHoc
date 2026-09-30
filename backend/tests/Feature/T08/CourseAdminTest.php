<?php

use App\Enums\CourseStatus;
use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\CourseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * US-009 — quản trị khóa học (host admin-api). Bao gồm test bắt buộc T08:
 * SVG/HTML/polyglot → 422, XSS description bị lọc, GV khóa A sửa khóa B → 403.
 */
function courseUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_api_host').'/api/v1/courses'.$path;
}

function courseHeaders(): array
{
    return ['Origin' => config('app.admin_url')];
}

/**
 * @return array<string, mixed>
 */
function validCoursePayload(array $override = []): array
{
    $subject = Subject::factory()->create();
    $teacher = User::factory()->teacher()->create();

    return array_merge([
        'title' => 'Hình học lớp 9',
        'grade_level' => 9,
        'subject_ids' => [$subject->id],
        'short_description' => 'Mô tả ngắn',
        'description' => '<p>Mô tả <strong>chi tiết</strong></p>',
        'price' => 299000,
        'thumbnail' => UploadedFile::fake()->image('thumb.jpg', 800, 600),
        'teacher_ids' => [$teacher->id],
    ], $override);
}

function teacherOf(Course $course): User
{
    $teacher = User::factory()->teacher()->create();
    $course->teachers()->attach($teacher->id);

    return $teacher;
}

beforeEach(function () {
    Storage::fake('uploads');
});

// --- Tạo (AC1, AC9) ---------------------------------------------------------

test('admin tao khoa hoc thanh cong o trang thai draft (AC1, BR1)', function () {
    $admin = User::factory()->admin()->create();
    $payload = validCoursePayload();

    $response = $this->actingAs($admin)->post(courseUrl(), $payload, courseHeaders() + ['Accept' => 'application/json']);

    $response->assertCreated();
    $response->assertJson(['title' => 'Hình học lớp 9', 'slug' => 'hinh-hoc-lop-9', 'status' => 'draft']);
    expect($response->json('thumbnail_path'))->toEndWith('.webp');
    expect($response->json('teachers'))->toHaveCount(1);

    $course = Course::query()->firstOrFail();
    expect($course->created_by)->toBe($admin->id);
    Storage::disk('uploads')->assertExists($course->thumbnail_path);
});

test('giao vien tu tao khoa hoc: draft, tu la giao vien phu trach, bo qua teacher_ids (AC9, BR8)', function () {
    $teacher = User::factory()->teacher()->create();
    $other = User::factory()->teacher()->create();
    $payload = validCoursePayload(['teacher_ids' => [$other->id], 'status' => 'published']);

    $response = $this->actingAs($teacher)->post(courseUrl(), $payload, courseHeaders() + ['Accept' => 'application/json']);

    $response->assertCreated();
    $course = Course::query()->firstOrFail();
    expect($course->status)->toBe(CourseStatus::Draft);
    expect($course->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

test('slug trung ke ca khoa da xoa mem duoc sinh hau to khac', function () {
    $admin = User::factory()->admin()->create();
    Course::factory()->create(['slug' => 'hinh-hoc-lop-9'])->delete();

    $response = $this->actingAs($admin)->post(courseUrl(), validCoursePayload(), courseHeaders() + ['Accept' => 'application/json']);

    $response->assertCreated();
    $response->assertJson(['slug' => 'hinh-hoc-lop-9-2']);
});

test('hoc phi am hoac khong phai so bi tu choi', function (mixed $price) {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(courseUrl(), validCoursePayload(['price' => $price]), courseHeaders() + ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('price');
})->with([-1, 'abc', 50000001]);

test('admin gan giao vien sai role bi tu choi', function () {
    $admin = User::factory()->admin()->create();
    $student = User::factory()->student()->create();

    $response = $this->actingAs($admin)->post(courseUrl(), validCoursePayload(['teacher_ids' => [$student->id]]), courseHeaders() + ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('teacher_ids.0');
});

test('anh SVG / HTML / doi duoi / polyglot deu bi 422 (S2)', function (string $name, string $content) {
    $admin = User::factory()->admin()->create();
    $file = UploadedFile::fake()->createWithContent($name, $content);

    $response = $this->actingAs($admin)->post(courseUrl(), validCoursePayload(['thumbnail' => $file]), courseHeaders() + ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('thumbnail');
    expect(Course::query()->count())->toBe(0);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);
})->with([
    'svg' => ['evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html' => ['evil.html', '<html><body><script>alert(1)</script></body></html>'],
    'svg doi duoi png' => ['fake.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
    'html doi duoi jpg' => ['fake.jpg', '<html><script>alert(1)</script></html>'],
    'polyglot gif+html' => ['evil.jpg', "GIF89a\x01\x00\x01\x00<script>alert(1)</script>"],
]);

test('XSS trong description bi loc khi ghi va khi doc (S8)', function () {
    $admin = User::factory()->admin()->create();
    $payload = validCoursePayload([
        'description' => '<p onclick="x()">Chào</p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">bad</a>',
    ]);

    $response = $this->actingAs($admin)->post(courseUrl(), $payload, courseHeaders() + ['Accept' => 'application/json']);

    $response->assertCreated();
    expect($response->json('description'))->toBe('<p>Chào</p>bad');
    expect(Course::query()->firstOrFail()->description)->toBe('<p>Chào</p>bad');

    // Đọc: dữ liệu bẩn ghi thẳng DB vẫn bị lọc ở Resource.
    $course = Course::query()->firstOrFail();
    $course->forceFill(['description' => '<script>alert(1)</script><p>ok</p>'])->save();
    $show = $this->actingAs($admin)->getJson(courseUrl('/'.$course->id), courseHeaders());
    expect($show->json('description'))->toBe('<p>ok</p>');
});

// --- Phân quyền (AC6, AC7) --------------------------------------------------

test('giao vien chi thay khoa hoc minh phu trach (AC6)', function () {
    $mine = Course::factory()->create();
    $teacher = teacherOf($mine);
    Course::factory()->count(2)->create();

    $response = $this->actingAs($teacher)->getJson(courseUrl(), courseHeaders());

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

test('GV khoa A sua/xem khoa B bi 403 ke ca payload sai (AC7)', function () {
    $courseA = Course::factory()->create();
    $courseB = Course::factory()->create();
    $teacherA = teacherOf($courseA);

    $this->actingAs($teacherA)->getJson(courseUrl('/'.$courseB->id), courseHeaders())->assertForbidden();
    $this->actingAs($teacherA)->putJson(courseUrl('/'.$courseB->id), ['title' => 'Hack'], courseHeaders())->assertForbidden();
    $this->actingAs($teacherA)->putJson(courseUrl('/'.$courseB->id), ['price' => -5], courseHeaders())->assertForbidden();
    expect($courseB->fresh()->title)->not->toBe('Hack');

    $this->actingAs($teacherA)->putJson(courseUrl('/'.$courseA->id), ['title' => 'Khoá của tôi'], courseHeaders())->assertOk();
});

test('giao vien khong xoa/publish/unpublish/gan giao vien/manual-order duoc (BR2)', function () {
    $course = Course::factory()->published()->create();
    $teacher = teacherOf($course);

    $this->actingAs($teacher)->deleteJson(courseUrl('/'.$course->id), [], courseHeaders())->assertForbidden();
    $this->actingAs($teacher)->postJson(courseUrl('/'.$course->id.'/publish'), [], courseHeaders())->assertForbidden();
    $this->actingAs($teacher)->postJson(courseUrl('/'.$course->id.'/unpublish'), [], courseHeaders())->assertForbidden();
    $this->actingAs($teacher)->putJson(courseUrl('/'.$course->id.'/teachers'), ['teacher_ids' => [$teacher->id]], courseHeaders())->assertForbidden();
    $this->actingAs($teacher)->patchJson(courseUrl('/'.$course->id.'/manual-order'), ['manual_order' => 1], courseHeaders())->assertForbidden();
    $this->actingAs($teacher)->getJson('http://'.config('app.admin_api_host').'/api/v1/teachers', courseHeaders())->assertForbidden();
});

test('hoc sinh bi 403 va khach bi 401', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($student)->getJson(courseUrl(), courseHeaders())->assertForbidden();
});

test('khach chua dang nhap bi 401', function () {
    $this->getJson(courseUrl(), courseHeaders())->assertUnauthorized();
});

// --- Sửa (2 bộ rule staff/GV) ----------------------------------------------

test('GV gui status/manual_order/teacher_ids bi bo qua (S5, S17)', function () {
    $course = Course::factory()->create(['title' => 'Cũ']);
    $teacher = teacherOf($course);
    $stranger = User::factory()->teacher()->create();

    $response = $this->actingAs($teacher)->putJson(courseUrl('/'.$course->id), [
        'title' => 'Mới',
        'status' => 'published',
        'manual_order' => 1,
        'teacher_ids' => [$stranger->id],
    ], courseHeaders());

    $response->assertOk();
    $fresh = $course->fresh();
    expect($fresh->title)->toBe('Mới');
    expect($fresh->status)->toBe(CourseStatus::Draft);
    expect($fresh->manual_order)->toBeNull();
    expect($fresh->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

test('GV sua gia/lop duoc khi chua tung publish, khong duoc sau khi da publish', function () {
    $draft = Course::factory()->create(['price' => 100000, 'grade_level' => 7]);
    $draftTeacher = teacherOf($draft);

    $this->actingAs($draftTeacher)->putJson(courseUrl('/'.$draft->id), ['price' => 200000, 'grade_level' => 8], courseHeaders())->assertOk();
    expect($draft->fresh()->price)->toBe(200000);
    expect($draft->fresh()->grade_level)->toBe(8);

    $live = Course::factory()->published()->create(['price' => 100000, 'grade_level' => 7]);
    $liveTeacher = teacherOf($live);

    $this->actingAs($liveTeacher)->putJson(courseUrl('/'.$live->id), ['title' => 'Đổi tên', 'price' => 1, 'grade_level' => 12], courseHeaders())->assertOk();
    $fresh = $live->fresh();
    expect($fresh->title)->toBe('Đổi tên');
    expect($fresh->price)->toBe(100000);
    expect($fresh->grade_level)->toBe(7);
});

test('doi gia ghi audit course.price.change; thay anh xoa anh cu', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create(['price' => 100000]);
    $this->actingAs($admin)->put(courseUrl('/'.$course->id), ['thumbnail' => UploadedFile::fake()->image('a.png', 100, 100)], courseHeaders() + ['Accept' => 'application/json'])->assertOk();
    $old = $course->fresh()->thumbnail_path;
    Storage::disk('uploads')->assertExists($old);

    $this->actingAs($admin)->put(courseUrl('/'.$course->id), [
        'price' => 150000,
        'thumbnail' => UploadedFile::fake()->image('b.png', 100, 100),
    ], courseHeaders() + ['Accept' => 'application/json'])->assertOk();

    Storage::disk('uploads')->assertMissing($old);
    $log = AuditLog::query()->where('action', 'course.price.change')->first();
    expect($log)->not->toBeNull();
    expect($log->changes)->toEqual(['before' => 100000, 'after' => 150000]);
});

test('tao khoa hoc ghi audit course.create', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(courseUrl(), validCoursePayload(), courseHeaders() + ['Accept' => 'application/json'])->assertCreated();

    expect(AuditLog::query()->where('action', 'course.create')->exists())->toBeTrue();
});

test('admin loc danh sach theo trang thai, lop va tu khoa co escape LIKE', function () {
    $admin = User::factory()->admin()->create();
    Course::factory()->published()->create(['title' => 'Toán 9', 'grade_level' => 9]);
    Course::factory()->create(['title' => 'Văn 8', 'grade_level' => 8]);

    $byStatus = $this->actingAs($admin)->getJson(courseUrl('?status=published'), courseHeaders());
    expect($byStatus->json('data'))->toHaveCount(1);

    $byGrade = $this->actingAs($admin)->getJson(courseUrl('?grade=8'), courseHeaders());
    expect($byGrade->json('data'))->toHaveCount(1);

    $byQ = $this->actingAs($admin)->getJson(courseUrl('?q=toan'), courseHeaders());
    expect($byQ->json('data'))->toHaveCount(1);

    $wild = $this->actingAs($admin)->getJson(courseUrl('?q=%25'), courseHeaders());
    $wild->assertOk();
    expect($wild->json('data'))->toHaveCount(0);
});

test('manual-order staff dat gia tri va null', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)->patchJson(courseUrl('/'.$course->id.'/manual-order'), ['manual_order' => 3], courseHeaders())
        ->assertOk()->assertJson(['manual_order' => 3]);
    $this->actingAs($admin)->patchJson(courseUrl('/'.$course->id.'/manual-order'), ['manual_order' => null], courseHeaders())
        ->assertOk()->assertJson(['manual_order' => null]);
});

// --- Publish / unpublish (AC2, AC3) ----------------------------------------

test('publish chan khi chua co chuong/bai (AC3), thanh cong khi du dieu kien (AC2)', function () {
    $admin = User::factory()->pageManager()->create();
    $course = Course::factory()->create();

    $blocked = $this->actingAs($admin)->postJson(courseUrl('/'.$course->id.'/publish'), [], courseHeaders());
    $blocked->assertStatus(422)->assertJson([
        'code' => 'COURSE_NOT_PUBLISHABLE',
        'message' => 'Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.',
    ]);

    $chapter = Chapter::factory()->create(['course_id' => $course->id]);
    Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id]);

    $ok = $this->actingAs($admin)->postJson(courseUrl('/'.$course->id.'/publish'), [], courseHeaders());
    $ok->assertOk()->assertJson(['status' => 'published']);
    $firstPublishedAt = $course->fresh()->published_at;
    expect($firstPublishedAt)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'course.publish')->exists())->toBeTrue();

    $this->actingAs($admin)->postJson(courseUrl('/'.$course->id.'/unpublish'), [], courseHeaders())
        ->assertOk()->assertJson(['status' => 'unpublished']);
    expect(AuditLog::query()->where('action', 'course.unpublish')->exists())->toBeTrue();

    $this->travel(1)->hours();
    $this->actingAs($admin)->postJson(courseUrl('/'.$course->id.'/publish'), [], courseHeaders())->assertOk();
    expect($course->fresh()->published_at->equalTo($firstPublishedAt))->toBeTrue();
});

test('unpublish khoa chua xuat ban tra 409', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)->postJson(courseUrl('/'.$course->id.'/unpublish'), [], courseHeaders())
        ->assertStatus(409)->assertJson(['code' => 'COURSE_NOT_PUBLISHED']);
});

// --- Xoá (AC4, AC5) ---------------------------------------------------------

test('xoa khoa chua co enrollment: xoa mem cung chuong/bai, ghi audit (AC5)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();
    $chapter = Chapter::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['course_id' => $course->id, 'chapter_id' => $chapter->id]);

    $this->actingAs($admin)->deleteJson(courseUrl('/'.$course->id), [], courseHeaders())->assertNoContent();

    expect(Course::query()->find($course->id))->toBeNull();
    expect(Chapter::withTrashed()->find($chapter->id)->trashed())->toBeTrue();
    expect(Lesson::withTrashed()->find($lesson->id)->trashed())->toBeTrue();
    expect(AuditLog::query()->where('action', 'course.delete')->where('subject_id', $course->id)->exists())->toBeTrue();
    $list = $this->actingAs($admin)->getJson(courseUrl(), courseHeaders());
    expect(collect($list->json('data'))->pluck('id')->all())->not->toContain($course->id);
});

test('xoa khoa da co enrollment bi 409 va khong xoa gi (AC4)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();
    Enrollment::factory()->create(['course_id' => $course->id]);

    $this->actingAs($admin)->deleteJson(courseUrl('/'.$course->id), [], courseHeaders())
        ->assertStatus(409)->assertJson(['code' => 'COURSE_HAS_ENROLLMENT']);

    expect(Course::query()->find($course->id))->not->toBeNull();
});

// --- Giáo viên phụ trách (BR6, AC10) ---------------------------------------

test('admin gan nhieu giao vien, ca 2 thay khoa hoc (AC10), ghi audit', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();
    $t1 = teacherOf($course);
    $t2 = User::factory()->teacher()->create();

    $this->actingAs($admin)->putJson(courseUrl('/'.$course->id.'/teachers'), ['teacher_ids' => [$t1->id, $t2->id]], courseHeaders())
        ->assertOk();

    foreach ([$t1, $t2] as $teacher) {
        $list = $this->actingAs($teacher)->getJson(courseUrl(), courseHeaders());
        expect(collect($list->json('data'))->pluck('id')->all())->toBe([$course->id]);
    }
    expect(AuditLog::query()->where('action', 'course.teachers.sync')->exists())->toBeTrue();
});

test('gan giao vien: rong, sai role bi 422; go het giao vien bi chan', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();
    $teacher = teacherOf($course);
    $student = User::factory()->student()->create();

    $this->actingAs($admin)->putJson(courseUrl('/'.$course->id.'/teachers'), ['teacher_ids' => []], courseHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('teacher_ids');
    $this->actingAs($admin)->putJson(courseUrl('/'.$course->id.'/teachers'), ['teacher_ids' => [$student->id]], courseHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('teacher_ids.0');

    expect($course->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

test('danh sach giao vien chi tra id va name', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->teacher()->create();
    User::factory()->student()->create();

    $response = $this->actingAs($admin)->getJson('http://'.config('app.admin_api_host').'/api/v1/teachers', courseHeaders());

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect(array_keys($response->json('data.0')))->toBe(['id', 'name']);
});

// --- R1/R2/R5 (review-T08) --------------------------------------------------

test('list admin khong tra description, show thi co (R5)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create(['description' => '<p>Nội dung</p>']);

    $list = $this->actingAs($admin)->getJson(courseUrl(), courseHeaders());
    expect($list->json('data.0'))->not->toHaveKey('description');

    $show = $this->actingAs($admin)->getJson(courseUrl('/'.$course->id), courseHeaders());
    expect($show->json('description'))->toBe('<p>Nội dung</p>');
});

test('update loi sau khi luu anh moi: anh cu con, anh moi bi don (R1)', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();
    $this->actingAs($admin)->put(courseUrl('/'.$course->id), ['thumbnail' => UploadedFile::fake()->image('a.png', 100, 100)], courseHeaders() + ['Accept' => 'application/json'])->assertOk();
    $old = $course->fresh()->thumbnail_path;

    // Ép lỗi ở bước sau khi lưu ảnh (ghi audit) để transaction rollback.
    $this->mock(AuditLogger::class, function ($mock) {
        $mock->shouldReceive('log')->andThrow(new RuntimeException('boom'));
    });

    $service = app(CourseService::class);
    expect(fn () => $service->update($course->fresh(), ['thumbnail' => UploadedFile::fake()->image('b.png', 100, 100)], $admin))
        ->toThrow(RuntimeException::class);

    Storage::disk('uploads')->assertExists($old);
    expect(Storage::disk('uploads')->allFiles())->toBe([$old]);
    expect($course->fresh()->thumbnail_path)->toBe($old);
});

test('create loi giua chung: file moi bi don (R1)', function () {
    $admin = User::factory()->admin()->create();
    $this->mock(AuditLogger::class, function ($mock) {
        $mock->shouldReceive('log')->andThrow(new RuntimeException('boom'));
    });

    $payload = validCoursePayload();
    $service = app(CourseService::class);

    expect(fn () => $service->create($payload, $admin))->toThrow(RuntimeException::class);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);
    expect(Course::query()->count())->toBe(0);
});
