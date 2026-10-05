<?php

use App\Enums\SubjectStatus;
use App\Enums\VideoSource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Subject;
use App\Models\User;
use App\Support\StaticUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../T04/helpers.php';

function vvCatalogGet(string $path)
{
    return test()->getJson(vvApiUrl($path));
}

function vvPublished(array $attrs = [], array $subjects = []): Course
{
    $course = Course::factory()->published()->create($attrs);
    foreach ($subjects as $subject) {
        DB::table('course_subject')->insert(['course_id' => $course->id, 'subject_id' => $subject->id]);
    }

    return $course;
}

function vvTeach(Course $course, ?User $teacher = null): User
{
    $teacher ??= User::factory()->teacher()->create();
    DB::table('course_teacher')->insert(['course_id' => $course->id, 'user_id' => $teacher->id]);

    return $teacher;
}

function vvSlugs($response): array
{
    return collect($response->json('data'))->pluck('slug')->all();
}

// ---------------------------------------------------------------- danh sách

test('danh muc chi hien khoa published chua xoa, kem header cache va khong cookie', function () {
    $pub = vvPublished();
    Course::factory()->create();               // draft
    Course::factory()->unpublished()->create();
    vvPublished()->delete();                   // published nhưng đã xoá mềm

    $r = vvCatalogGet('/courses')->assertOk();

    expect(vvSlugs($r))->toBe([$pub->slug]);
    $r->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)->assertJsonPath('links.prev', null)->assertJsonPath('links.next', null);
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    expect($r->headers->get('ETag'))->not->toBeNull();
    expect($r->headers->getCookies())->toBe([]);
    expect((string) $r->headers->get('Vary'))->not->toContain('Cookie');
    $r->assertJsonStructure(['data' => [['id', 'title', 'slug', 'short_description', 'grade_level', 'price', 'is_free',
        'thumbnail_url', 'enrollments_count', 'published_at', 'subjects', 'teachers']]]);
    expect($r->json('data.0'))->not->toHaveKeys(['description', 'status', 'search_text', 'created_by', 'manual_order']);
});

test('ETag khop If-None-Match tra 304', function () {
    vvPublished();
    $first = vvCatalogGet('/courses')->assertOk();

    test()->getJson(vvApiUrl('/courses'), ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304);
});

test('loc theo lop, chuyen de, ket hop (AC1, AC2)', function () {
    $geo = Subject::factory()->create(['name' => 'Hình học']);
    $alg = Subject::factory()->create(['name' => 'Đại số']);
    $a = vvPublished(['grade_level' => 8], [$geo]);
    $b = vvPublished(['grade_level' => 8], [$alg]);
    $c = vvPublished(['grade_level' => 9], [$geo]);

    expect(vvSlugs(vvCatalogGet('/courses?grade=8')))->toEqualCanonicalizing([$a->slug, $b->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?grade=8&subject_ids[]='.$geo->id)))->toBe([$a->slug]);
    // nhiều chuyên đề = OR
    expect(vvSlugs(vvCatalogGet("/courses?subject_ids[]={$geo->id}&subject_ids[]={$alg->id}")))
        ->toEqualCanonicalizing([$a->slug, $b->slug, $c->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?grade=12')))->toBe([]);
});

test('chuyen de an khong loc duoc va khong lo ra trong khoa (AC9)', function () {
    $hidden = Subject::factory()->create();
    $shown = Subject::factory()->create();
    $course = vvPublished([], [$hidden, $shown]);
    $hidden->forceFill(['status' => SubjectStatus::Hidden])->save();

    $r = vvCatalogGet('/courses')->assertOk();
    expect(collect($r->json('data.0.subjects'))->pluck('id')->all())->toBe([$shown->id]);

    expect(vvSlugs(vvCatalogGet('/courses?subject_ids[]='.$hidden->id)))->toBe([]);
    expect(vvSlugs(vvCatalogGet('/courses?subject_ids[]=999999')))->toBe([]);

    $detail = vvCatalogGet('/courses/'.$course->slug)->assertOk();
    expect(collect($detail->json('subjects'))->pluck('id')->all())->toBe([$shown->id]);

    $list = vvCatalogGet('/subjects')->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$shown->id]);
    expect($list->json('data.0'))->toHaveKeys(['id', 'name', 'slug'])->not->toHaveKey('status');
});

test('tim kiem khong dau, khong phan biet hoa thuong, nhieu tu (AC5)', function () {
    $a = vvPublished(['title' => 'Hình học nâng cao lớp 9', 'short_description' => 'Ôn thi vào 10']);
    vvPublished(['title' => 'Đại số lớp 9', 'short_description' => 'Phương trình']);

    expect(vvSlugs(vvCatalogGet('/courses?q='.urlencode('HÌNH HỌC lớp 9'))))->toBe([$a->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?q=hinh+hoc')))->toBe([$a->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?q='.urlencode('ôn thi'))))->toBe([$a->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?q=dai+so')))->not->toContain($a->slug);
    expect(vvSlugs(vvCatalogGet('/courses?q=khongco')))->toBe([]);
});

test('tu khoa co ky tu dac biet LIKE/SQL duoc escape, khong 500', function () {
    $a = vvPublished(['title' => 'Giảm 100% nhanh', 'short_description' => 'x']);
    vvPublished(['title' => 'Toán lớp 6', 'short_description' => 'y']);

    expect(vvSlugs(vvCatalogGet('/courses?q='.urlencode('%'))))->toBe([$a->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?q='.urlencode('_'))))->toBe([]);
    expect(vvSlugs(vvCatalogGet('/courses?q='.urlencode('\\'))))->toBe([]);
    vvCatalogGet('/courses?q='.urlencode("'; DROP TABLE courses; --"))->assertOk()->assertJsonPath('meta.total', 0);
    expect(Course::query()->whereIn('id', [$a->id])->exists())->toBeTrue();
    expect(Schema::hasTable('courses'))->toBeTrue();
});

test('tham so sai tra 422 envelope tieng Viet', function (string $qs) {
    vvCatalogGet('/courses?'.$qs)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
})->with(['grade=99', 'grade=abc', 'sort=hack', 'page=0', 'page=abc', 'subject_ids=1', 'subject_ids[]=x']);

test('q dai hon 100 ky tu bi tu choi', function () {
    vvCatalogGet('/courses?q='.str_repeat('a', 101))->assertStatus(422);
});

test('sap xep newest / popular / featured co tie-break on dinh (AC7, BR6)', function () {
    $old = vvPublished(['title' => 'Cũ']);
    $old->forceFill(['published_at' => now()->subDays(10), 'enrollments_count' => 50])->save();
    $mid = vvPublished(['title' => 'Giữa']);
    $mid->forceFill(['published_at' => now()->subDays(5), 'enrollments_count' => 10, 'manual_order' => 2])->save();
    $new = vvPublished(['title' => 'Mới']);
    $new->forceFill(['published_at' => now()->subDay(), 'enrollments_count' => 10, 'manual_order' => 1])->save();
    $none = vvPublished(['title' => 'Chưa xếp']);
    $none->forceFill(['published_at' => now()->subDays(2), 'enrollments_count' => 0])->save();

    expect(vvSlugs(vvCatalogGet('/courses')))->toBe([$new->slug, $none->slug, $mid->slug, $old->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?sort=newest')))->toBe([$new->slug, $none->slug, $mid->slug, $old->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?sort=popular')))->toBe([$old->slug, $new->slug, $mid->slug, $none->slug]);
    expect(vvSlugs(vvCatalogGet('/courses?sort=featured')))->toBe([$new->slug, $mid->slug, $none->slug, $old->slug]);
});

test('phan trang 25/trang khong trung hoac sot (AC6)', function () {
    $when = now();
    Course::factory()->count(27)->published()->create(['published_at' => $when]); // cùng published_at → tie-break id

    $p1 = vvCatalogGet('/courses')->assertOk();
    $p2 = vvCatalogGet('/courses?page=2')->assertOk();

    expect($p1->json('data'))->toHaveCount(25);
    expect($p2->json('data'))->toHaveCount(2);
    $p1->assertJsonPath('meta.total', 27)->assertJsonPath('meta.last_page', 2);
    expect($p1->json('links.next'))->toContain('page=2')->toStartWith('/api/v1/courses?');
    expect(array_merge(vvSlugs($p1), vvSlugs($p2)))->toHaveCount(27)->and(
        count(array_unique(array_merge(vvSlugs($p1), vvSlugs($p2))))
    )->toBe(27);
    // trang quá cuối: rỗng, không lỗi
    expect(vvCatalogGet('/courses?page=9')->json('data'))->toBe([]);
});

test('danh muc rong khong loi', function () {
    vvCatalogGet('/courses')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});

test('danh muc khong N+1: so truy van khong doi theo so khoa', function () {
    $subject = Subject::factory()->create();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        vvCatalogGet('/courses')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 2) as $i) {
        vvTeach(vvPublished([], [$subject]));
    }
    $few = $count();
    foreach (range(1, 10) as $i) {
        vvTeach(vvPublished([], [$subject]));
    }

    expect($count())->toBe($few);
});

test('truy van loc dung index, khong quet toan bang', function () {
    $subject = Subject::factory()->create();
    vvPublished(['grade_level' => 7], [$subject]);

    $plan = fn (string $sql, array $b) => collect(DB::select('EXPLAIN '.$sql, $b));

    $rows = $plan('select id from courses where status = ? and grade_level = ? and deleted_at is null', ['published', 7]);
    expect($rows->first()->key)->toBe('courses_status_grade_level_index');

    $rows = $plan('select id from courses where status = ? and deleted_at is null', ['published']);
    expect($rows->first()->type)->not->toBe('ALL');

    $rows = $plan('select course_id from course_subject where subject_id in (?)', [$subject->id]);
    expect($rows->first()->type)->not->toBe('ALL');
});

// ---------------------------------------------------------------- chi tiết

test('chi tiet theo slug: outline, giao vien, dem ghi danh, khong lo video (US-003)', function () {
    $course = vvPublished(['price' => 199000, 'enrollments_count' => 7, 'description' => '<p>Mô tả</p>']);
    $t1 = vvTeach($course, User::factory()->teacher()->create(['name' => 'Cô A', 'bio' => 'Giảng viên 10 năm']));
    vvTeach($course, User::factory()->teacher()->create(['name' => 'Thầy B']));
    $ch2 = Chapter::factory()->for($course)->create(['position' => 2, 'title' => 'Chương 2']);
    $ch1 = Chapter::factory()->for($course)->create(['position' => 1, 'title' => 'Chương 1']);
    Lesson::factory()->create(['chapter_id' => $ch1->id, 'position' => 2, 'title' => 'B2', 'duration_seconds' => 60]);
    $l1 = Lesson::factory()->create(['chapter_id' => $ch1->id, 'position' => 1, 'title' => 'B1', 'duration_seconds' => 120, 'is_preview' => true,
        'video_source' => VideoSource::ExternalLink, 'external_provider' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ']);
    Lesson::factory()->create(['chapter_id' => $ch2->id, 'position' => 1, 'title' => 'B3', 'duration_seconds' => null]);
    // đã xoá mềm: không lộ
    Lesson::factory()->create(['chapter_id' => $ch2->id, 'position' => 2, 'title' => 'Đã xoá'])->delete();
    Chapter::factory()->for($course)->create(['position' => 3, 'title' => 'Chương xoá'])->delete();

    $r = vvCatalogGet('/courses/'.$course->slug)->assertOk();

    $r->assertJsonPath('title', $course->title)->assertJsonPath('price', 199000)->assertJsonPath('is_free', false)
        ->assertJsonPath('enrollments_count', 7)->assertJsonPath('description', '<p>Mô tả</p>')
        ->assertJsonPath('lessons_count', 3)->assertJsonPath('total_duration_seconds', 180)->assertJsonPath('has_preview', true);
    expect(collect($r->json('teachers'))->pluck('name')->all())->toBe(['Cô A', 'Thầy B']);
    expect($r->json('teachers.0.bio'))->toBe('Giảng viên 10 năm');
    expect(collect($r->json('outline'))->pluck('title')->all())->toBe(['Chương 1', 'Chương 2']);
    expect(collect($r->json('outline.0.lessons'))->pluck('title')->all())->toBe(['B1', 'B2']);
    expect($r->json('outline.0.lessons.0'))->toBe(['id' => $l1->id, 'title' => 'B1', 'position' => 1, 'duration_seconds' => 120, 'is_preview' => true]);

    $raw = $r->getContent();
    foreach (['dQw4w9WgXcQ', 'external', 'video_asset', 'video_source', 'youtube', 'search_text', 'created_by'] as $leak) {
        expect($raw)->not->toContain($leak);
    }
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    expect($r->headers->getCookies())->toBe([]);
    expect($t1->email)->not->toBeNull();
    expect($raw)->not->toContain((string) $t1->email);
});

test('chi tiet khoa mien phi va khoa rong (khong chuong/bai)', function () {
    $course = vvPublished();
    vvCatalogGet('/courses/'.$course->slug)->assertOk()->assertJsonPath('is_free', true)
        ->assertJsonPath('outline', [])->assertJsonPath('lessons_count', 0)->assertJsonPath('has_preview', false)
        ->assertJsonPath('teachers', []);
});

test('chi tiet 404 cho draft, unpublished, da xoa, khong ton tai; loi khong duoc cache public', function () {
    $draft = Course::factory()->create();
    $unpub = Course::factory()->unpublished()->create();
    $deleted = vvPublished();
    $deleted->delete();

    foreach ([$draft->slug, $unpub->slug, $deleted->slug, 'khong-ton-tai'] as $slug) {
        $r = vvCatalogGet('/courses/'.$slug)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        expect((string) $r->headers->get('Cache-Control'))->not->toContain('public');
    }
});

test('chi tiet khong N+1 theo so chuong/bai', function () {
    $course = vvPublished();
    vvTeach($course);
    $count = function () use ($course): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        vvCatalogGet('/courses/'.$course->slug)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $ch = Chapter::factory()->for($course)->create();
    Lesson::factory()->create(['chapter_id' => $ch->id]);
    $few = $count();
    foreach (range(1, 4) as $i) {
        $c = Chapter::factory()->for($course)->create();
        Lesson::factory()->count(3)->create(['chapter_id' => $c->id]);
    }

    expect($count())->toBe($few);
});

test('route cong khai tren host admin-api khong ton tai', function () {
    test()->getJson('http://'.config('app.admin_api_host').'/api/v1/courses', ['Origin' => config('app.admin_url')])->assertNotFound();
});

// ---------------------------------------------------------------- viewer-state

test('viewer-state can dang nhap hoc sinh, khong cache', function () {
    $course = vvPublished(['price' => 100000]);

    vvCatalogGet('/courses/'.$course->slug.'/viewer-state')->assertUnauthorized();

    vvActAsStudent(User::factory()->student()->create());
    $r = test()->getJson(vvApiUrl('/courses/'.$course->slug.'/viewer-state'), vvWebHeaders())->assertOk();
    $r->assertExactJson(['viewer_state' => 'can_buy', 'resume_lesson_id' => null]);
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

test('viewer-state: mien phi, cho duyet, bi tu choi/thu hoi roi, da so huu', function () {
    $free = vvPublished();
    $paid = vvPublished(['price' => 50000]);
    $student = vvActAsStudent(User::factory()->student()->create());
    $state = fn (Course $c) => test()->getJson(vvApiUrl('/courses/'.$c->slug.'/viewer-state'), vvWebHeaders())->assertOk()->json('viewer_state');

    expect($state($free))->toBe('can_register_free');
    expect($state($paid))->toBe('can_buy');

    Enrollment::factory()->rejected()->create(['user_id' => $student->id, 'course_id' => $free->id]);
    Enrollment::factory()->revoked()->create(['user_id' => $student->id, 'course_id' => $paid->id]);
    expect($state($free))->toBe('can_register_free');
    expect($state($paid))->toBe('can_buy');

    Enrollment::factory()->pendingApproval()->create(['user_id' => $student->id, 'course_id' => $free->id]);
    expect($state($free))->toBe('pending_approval');

    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $paid->id]);
    expect($state($paid))->toBe('owned');
});

test('viewer-state owned: resume theo last_accessed_at, khong thi bai dau', function () {
    $course = vvPublished(['price' => 1000]);
    $ch2 = Chapter::factory()->for($course)->create(['position' => 2]);
    $ch1 = Chapter::factory()->for($course)->create(['position' => 1]);
    $second = Lesson::factory()->create(['chapter_id' => $ch1->id, 'position' => 2]);
    $first = Lesson::factory()->create(['chapter_id' => $ch1->id, 'position' => 1]);
    $other = Lesson::factory()->create(['chapter_id' => $ch2->id, 'position' => 1]);

    $student = vvActAsStudent(User::factory()->student()->create());
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    $get = fn () => test()->getJson(vvApiUrl('/courses/'.$course->slug.'/viewer-state'), vvWebHeaders())->assertOk();

    $get()->assertJsonPath('resume_lesson_id', $first->id);

    LessonProgress::factory()->create(['user_id' => $student->id, 'lesson_id' => $other->id, 'course_id' => $course->id, 'last_accessed_at' => now()->subDay()]);
    LessonProgress::factory()->create(['user_id' => $student->id, 'lesson_id' => $second->id, 'course_id' => $course->id, 'last_accessed_at' => now()]);
    $get()->assertJsonPath('resume_lesson_id', $second->id);

    // bài gần nhất đã bị xoá → bỏ qua, lấy bài gần nhất còn lại
    $second->delete();
    $get()->assertJsonPath('resume_lesson_id', $other->id);
});

test('viewer-state: tien do cua nguoi khac khong anh huong', function () {
    $course = vvPublished(['price' => 1000]);
    $ch = Chapter::factory()->for($course)->create();
    $mine = Lesson::factory()->create(['chapter_id' => $ch->id, 'position' => 1]);
    $theirs = Lesson::factory()->create(['chapter_id' => $ch->id, 'position' => 2]);
    $someone = User::factory()->student()->create();
    LessonProgress::factory()->create(['user_id' => $someone->id, 'lesson_id' => $theirs->id, 'course_id' => $course->id, 'last_accessed_at' => now()]);

    $student = vvActAsStudent(User::factory()->student()->create());
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    test()->getJson(vvApiUrl('/courses/'.$course->slug.'/viewer-state'), vvWebHeaders())->assertOk()->assertJsonPath('resume_lesson_id', $mine->id);
});

test('viewer-state khoa unpublished: nguoi da so huu van thay, nguoi khac 404', function () {
    $course = Course::factory()->unpublished()->paid()->create();
    $draft = Course::factory()->create();
    $student = vvActAsStudent(User::factory()->student()->create());
    $url = vvApiUrl('/courses/'.$course->slug.'/viewer-state');

    test()->getJson($url, vvWebHeaders())->assertNotFound();
    test()->getJson(vvApiUrl('/courses/'.$draft->slug.'/viewer-state'), vvWebHeaders())->assertNotFound();

    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    test()->getJson($url, vvWebHeaders())->assertOk()->assertJsonPath('viewer_state', 'owned');
});

// ---------------------------------------------------------------- review R1-R4

test('chi tiet loc description khi doc (script, onerror)', function () {
    $course = vvPublished();
    DB::table('courses')->where('id', $course->id)->update([
        'description' => '<p onclick="x()">Chào</p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">l</a>',
    ]);

    $desc = (string) vvCatalogGet('/courses/'.$course->slug)->assertOk()->json('description');

    expect($desc)->toContain('Chào')
        ->not->toContain('<script')->not->toContain('onerror')->not->toContain('onclick')
        ->not->toContain('<img')->not->toContain('javascript:');
});

test('3 route cong khai: khong Set-Cookie, khong Vary Cookie, 4xx khong public, co Vary Origin khi CORS', function () {
    $course = vvPublished();
    $origin = ['Origin' => config('app.frontend_url')];

    $cases = [
        ['/subjects', 200], ['/courses', 200], ['/courses/'.$course->slug, 200],
        ['/courses/khong-co', 404], ['/courses?grade=99', 422],
    ];

    foreach ($cases as [$path, $status]) {
        foreach ([[], $origin] as $headers) {
            $r = test()->getJson(vvApiUrl($path), $headers)->assertStatus($status);
            expect($r->headers->getCookies())->toBe([], "$path có Set-Cookie");
            expect($r->headers->has('Set-Cookie'))->toBeFalse();
            expect((string) $r->headers->get('Vary'))->not->toContain('Cookie');
            if ($status !== 200) {
                expect((string) $r->headers->get('Cache-Control'))->not->toContain('public');
            }
        }
        // Có Origin hợp lệ: nếu CORS trả ACAO thì BẮT BUỘC Vary: Origin (cache không lẫn giữa origin).
        $r = test()->getJson(vvApiUrl($path), $origin);
        if ($r->headers->has('Access-Control-Allow-Origin')) {
            expect((string) $r->headers->get('Vary'))->toContain('Origin');
        }
    }
});

test('links phan trang la duong dan tuong doi, khong phu thuoc Host client', function () {
    Course::factory()->count(26)->published()->create(['grade_level' => 8]);

    $r = vvCatalogGet('/courses?grade=8&sort=popular&evil=1')->assertOk();

    $next = $r->json('links.next');
    expect($next)->toStartWith('/api/v1/courses?')->toContain('page=2')->toContain('grade=8')->toContain('sort=popular')
        ->not->toContain('evil')->not->toContain('http');
    $r->assertJsonPath('links.prev', null);

    $p2 = vvCatalogGet(substr($next, strlen('/api/v1')))->assertOk();
    expect($p2->json('links.prev'))->toContain('page=1')->and($p2->json('links.next'))->toBeNull();
});

test('StaticUrl chan path nguy hiem va STATIC_URL rong', function () {
    config(['app.static_url' => 'https://static.example.com/']);

    expect(StaticUrl::to('abc.webp'))->toBe('https://static.example.com/abc.webp');
    expect(StaticUrl::to('/a/b.webp'))->toBe('https://static.example.com/a/b.webp');
    foreach ([null, '', '../x.webp', 'a/../b', 'http://evil/x', '//evil/x', "a\nb", 'a b', 'a\\b', "a\0b"] as $bad) {
        expect(StaticUrl::to($bad))->toBeNull();
    }

    config(['app.static_url' => '']);
    expect(StaticUrl::to('abc.webp'))->toBeNull();
    config(['app.static_url' => null]);
    expect(StaticUrl::to('abc.webp'))->toBeNull();
});
