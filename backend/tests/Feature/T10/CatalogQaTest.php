<?php

use App\Enums\SubjectStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T04/helpers.php';

/** QA bổ sung T10: biên mà test của Dev chưa phủ. */
function qaGet(string $path, array $headers = [])
{
    return test()->getJson(vvApiUrl($path), $headers);
}

function qaPub(array $attrs = [], array $subjects = []): Course
{
    $c = Course::factory()->published()->create($attrs);
    foreach ($subjects as $s) {
        DB::table('course_subject')->insert(['course_id' => $c->id, 'subject_id' => $s->id]);
    }

    return $c;
}

test('AC1/BR3: loc lop + chuyen de + tu khoa cung luc, subject_ids lap lai khong nhan doi ket qua', function () {
    $hh = Subject::factory()->create(['name' => 'Hình học']);
    $ds = Subject::factory()->create(['name' => 'Đại số']);
    $ok = qaPub(['grade_level' => 8, 'title' => 'Hình học phẳng'], [$hh, $ds]);
    qaPub(['grade_level' => 9, 'title' => 'Hình học phẳng'], [$hh]);
    qaPub(['grade_level' => 8, 'title' => 'Đại số cơ bản'], [$ds]);

    $r = qaGet("/courses?grade=8&subject_ids[]={$hh->id}&subject_ids[]={$hh->id}&subject_ids[]={$ds->id}&q=hinh+hoc")->assertOk();
    expect(collect($r->json('data'))->pluck('slug')->all())->toBe([$ok->slug]);
    $r->assertJsonPath('meta.total', 1);
});

test('AC9: chuyen de an lan chuyen de that trong subject_ids chi loc theo chuyen de that', function () {
    $real = Subject::factory()->create();
    $hidden = Subject::factory()->create(['status' => SubjectStatus::Hidden]);
    $a = qaPub([], [$real]);
    qaPub([], [$hidden]);

    $r = qaGet("/courses?subject_ids[]={$hidden->id}&subject_ids[]={$real->id}")->assertOk();
    expect(collect($r->json('data'))->pluck('slug')->all())->toBe([$a->slug]);
});

test('AC5 bien: q co d/D, toan khoang trang, hon 8 tu, ky tu Unicode la', function () {
    $a = qaPub(['title' => 'Đường tròn và Đồ thị', 'short_description' => 'x']);

    $slugs = fn (string $q) => collect(qaGet('/courses?q='.urlencode($q))->assertOk()->json('data'))->pluck('slug')->all();
    expect($slugs('duong tron'))->toBe([$a->slug]);
    expect($slugs('ĐƯỜNG TRÒN'))->toBe([$a->slug]);
    expect($slugs('do thi'))->toBe([$a->slug]);
    expect($slugs('   '))->toContain($a->slug);                 // toàn khoảng trắng = không lọc
    expect($slugs('duong tron va do thi a b c d e f'))->toBeArray(); // >8 từ không lỗi
    expect($slugs('😀 ‮'))->toBeArray();
    expect($slugs('%%%___\\\\'))->toBe([]);
});

test('AC6 bien: page vuot last_page tra rong khong loi, meta van dung; page rat lon bi 422', function () {
    qaPub();
    $r = qaGet('/courses?page=50')->assertOk();
    expect($r->json('data'))->toBe([]);
    $r->assertJsonPath('meta.total', 1)->assertJsonPath('meta.current_page', 50);
    qaGet('/courses?page=100001')->assertStatus(422);
});

test('AC8: moi lop 6-12 loc duoc, lop ngoai khoang 422', function () {
    foreach ([6, 12] as $g) {
        $c = qaPub(['grade_level' => $g]);
        expect(collect(qaGet("/courses?grade=$g")->json('data'))->pluck('slug')->all())->toBe([$c->slug]);
    }
    foreach ([5, 13, 0, -1, '8.5'] as $g) {
        qaGet('/courses?grade='.$g)->assertStatus(422);
    }
});

test('AC4: khoa draft/unpublished/xoa mem khong xuat hien du khop tu khoa va loc', function () {
    $s = Subject::factory()->create();
    $c = Course::factory()->create(['title' => 'Bí mật nháp', 'grade_level' => 7]);
    DB::table('course_subject')->insert(['course_id' => $c->id, 'subject_id' => $s->id]);

    qaGet('/courses?q=bi+mat&grade=7&subject_ids[]='.$s->id)->assertOk()->assertJsonPath('meta.total', 0);
    qaGet('/courses?sort=popular')->assertOk()->assertJsonPath('meta.total', 0);
    qaGet('/courses?sort=featured')->assertOk()->assertJsonPath('meta.total', 0);
});

test('US-003 AC8: so hoc sinh chi dem enrollment active (cot enrollments_count) va chi tiet khong lo thong tin ca nhan', function () {
    $c = qaPub(['enrollments_count' => 3]);
    $r = qaGet('/courses/'.$c->slug)->assertOk();
    $r->assertJsonPath('enrollments_count', 3);
    expect(json_encode($r->json()))->not->toContain('email')->not->toContain('phone')->not->toContain('video');
});

test('US-003 AC6: nhieu giao vien deu hien thi, khong lo email/sdt cua giao vien', function () {
    $c = qaPub();
    // US-020: bio nằm ở teacher_profiles; t1 đã đồng ý, t2 chưa (bio null).
    $t1 = User::factory()->teacher()->create();
    $t2 = User::factory()->teacher()->create();
    TeacherProfile::factory()->consented()->create(['user_id' => $t1->id, 'bio' => 'GV A']);
    TeacherProfile::factory()->create(['user_id' => $t2->id, 'bio' => 'GV B']);
    DB::table('course_teacher')->insert([
        ['course_id' => $c->id, 'user_id' => $t1->id], ['course_id' => $c->id, 'user_id' => $t2->id],
    ]);
    $r = qaGet('/courses/'.$c->slug)->assertOk();
    expect($r->json('teachers'))->toHaveCount(2);
    foreach ($r->json('teachers') as $t) {
        expect(array_keys($t))->toEqualCanonicalizing(['id', 'name', 'bio', 'avatar_url']);
    }
    expect(collect($r->json('teachers'))->pluck('bio', 'id')->all())->toBe([$t1->id => 'GV A', $t2->id => null]);
});

test('US-003 BR4/AC5: slug cu sau khi doi slug va slug co ky tu la deu 404, khong 500', function () {
    $c = qaPub();
    $old = $c->slug;
    $c->forceFill(['slug' => 'slug-moi'])->save();

    qaGet('/courses/'.$old)->assertNotFound();
    qaGet('/courses/slug-moi')->assertOk();
    foreach (['%00', '%27%20OR%201=1', str_repeat('a', 300), '..%2f..%2fetc%2fpasswd', '%E2%80%AE'] as $bad) {
        $s = qaGet('/courses/'.$bad)->getStatusCode();
        expect($s)->toBeIn([404]);
    }
});

test('US-003: outline chuong/bai rong; chuong da xoa mem va bai da xoa mem khong lo; bai preview co co', function () {
    $c = qaPub();
    qaGet('/courses/'.$c->slug)->assertOk()->assertJsonPath('outline', [])->assertJsonPath('has_preview', false)
        ->assertJsonPath('lessons_count', 0);

    $ch = Chapter::factory()->for($c)->create(['position' => 1]);
    $gone = Chapter::factory()->for($c)->create(['position' => 2]);
    $l1 = Lesson::factory()->create(['chapter_id' => $ch->id, 'position' => 1, 'is_preview' => true, 'duration_seconds' => 60]);
    $l2 = Lesson::factory()->create(['chapter_id' => $ch->id, 'position' => 2, 'duration_seconds' => 40]);
    $l2->delete();
    $gone->delete();

    $r = qaGet('/courses/'.$c->slug)->assertOk();
    expect($r->json('outline'))->toHaveCount(1);
    expect($r->json('outline.0.lessons'))->toHaveCount(1);
    $r->assertJsonPath('has_preview', true)->assertJsonPath('lessons_count', 1)->assertJsonPath('total_duration_seconds', 60);
    expect(array_keys($r->json('outline.0.lessons.0')))->toEqualCanonicalizing(['id', 'title', 'position', 'duration_seconds', 'is_preview']);
});

test('cache: ETag doi khi du lieu doi, 304 voi If-None-Match cua chi tiet va subjects, header khac Accept-Language van 200', function () {
    $c = qaPub();
    foreach (['/subjects', '/courses/'.$c->slug] as $path) {
        $etag = qaGet($path)->assertOk()->headers->get('ETag');
        expect($etag)->not->toBeNull();
        qaGet($path, ['If-None-Match' => $etag])->assertStatus(304);
        qaGet($path, ['Accept-Language' => 'en'])->assertOk();
    }
    $etag = qaGet('/courses/'.$c->slug)->headers->get('ETag');
    DB::table('courses')->where('id', $c->id)->update(['title' => 'Tên mới']);
    $r = qaGet('/courses/'.$c->slug, ['If-None-Match' => $etag])->assertOk();
    expect($r->headers->get('ETag'))->not->toBe($etag);
});

test('viewer-state: schema chan 2 enrollment song, owned dung; khoa xoa mem 404 ke ca nguoi da so huu', function () {
    $c = qaPub(['price' => 1000]);
    $student = vvActAsStudent(User::factory()->student()->create());
    $url = '/courses/'.$c->slug.'/viewer-state';
    // Schema chặn 2 enrollment "sống" cho cùng (user, course) (live_flag unique) nên chỉ tồn tại 1 trạng thái sống.
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $c->id]);
    expect(fn () => Enrollment::factory()->pendingApproval()->create(['user_id' => $student->id, 'course_id' => $c->id]))
        ->toThrow(UniqueConstraintViolationException::class);
    qaGet($url, vvWebHeaders())->assertOk()->assertJsonPath('viewer_state', 'owned');

    $c->delete();
    qaGet($url, vvWebHeaders())->assertNotFound();
});

test('viewer-state: khoa unpublished + enrollment pending/revoked/rejected => 404; price 0 mien phi, price 1 co phi', function () {
    $student = vvActAsStudent(User::factory()->student()->create());
    $c = Course::factory()->unpublished()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $student->id, 'course_id' => $c->id]);
    qaGet('/courses/'.$c->slug.'/viewer-state', vvWebHeaders())->assertNotFound();

    $free = qaPub(['price' => 0]);
    $cheap = qaPub(['price' => 1]);
    qaGet('/courses/'.$free->slug.'/viewer-state', vvWebHeaders())->assertJsonPath('viewer_state', 'can_register_free');
    qaGet('/courses/'.$cheap->slug.'/viewer-state', vvWebHeaders())->assertJsonPath('viewer_state', 'can_buy');
    qaGet('/courses/khong-co/viewer-state', vvWebHeaders())->assertNotFound();
});

test('viewer-state: enrollment cua hoc sinh khac khong anh huong trang thai cua toi', function () {
    $c = qaPub(['price' => 1000]);
    $other = User::factory()->student()->create();
    Enrollment::factory()->create(['user_id' => $other->id, 'course_id' => $c->id]);
    vvActAsStudent(User::factory()->student()->create());
    qaGet('/courses/'.$c->slug.'/viewer-state', vvWebHeaders())->assertJsonPath('viewer_state', 'can_buy');
});

test('CORS: Origin la khong duoc phan hoi ACAO cho route cong khai', function () {
    $r = qaGet('/courses', ['Origin' => 'https://evil.example']);
    $r->assertOk();
    expect($r->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example')->not->toBe('*');
});

test('BUG-1: q chi gom ky tu khong chuyen duoc sang ASCII (CJK/emoji) phai tra rong, khong tra ca danh muc', function () {
    qaPub(['title' => 'Toán lớp 9']);
    expect(qaGet('/courses?q='.urlencode('日本語'))->assertOk()->json('data'))->toBe([]);
});
