<?php

use App\Enums\EnrollmentStatus;
use App\Exceptions\DomainException;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Courses\CurriculumService;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

test('heartbeat dau tien: tao dong tien do, cong toi da theo thoi gian, tra status/completed/course_percent', function () {
    $s = vvLearnSet(duration: 600);

    vvHeartbeat($s['lesson'], 20, 20)->assertOk()
        ->assertExactJson(['status' => 'in_progress', 'completed' => false, 'course_percent' => 0]);

    $row = vvProgressRow($s['student'], $s['lesson']);
    expect($row->watched_seconds)->toBe(20)
        ->and($row->last_position_seconds)->toBe(20)
        ->and($row->course_id)->toBe($s['course']->id)
        ->and($row->status)->toBe('in_progress');
});

test('chong gian lan: gui don dap khong cong them; cong toi da 2x thoi gian that + 5s', function () {
    $s = vvLearnSet(duration: 600);
    $this->freezeTime();

    vvHeartbeat($s['lesson'], 60, 60)->assertOk();
    // Lan dau coi nhu 20s truoc: cap 2*20+5 = 45.
    expect(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(45);

    // Don dap: cung giay -> cap = 0.
    vvHeartbeat($s['lesson'], 120, 60)->assertOk();
    vvHeartbeat($s['lesson'], 180, 60)->assertOk();
    expect(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(45);

    // 10 giay that: toi da 2*10+5 = 25 (du client bao 60).
    $this->travel(10)->seconds();
    vvHeartbeat($s['lesson'], 240, 60)->assertOk();
    expect(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(45 + 25);

    // 1 giay: khong duoc cong phan bu (chi 2*1).
    $this->travel(1)->seconds();
    vvHeartbeat($s['lesson'], 241, 60)->assertOk();
    expect(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(45 + 25 + 2);
});

test('idempotent: gui lai dung heartbeat (retry mang) khong cong them, ket qua giong nhau', function () {
    $s = vvLearnSet(duration: 600);
    $this->freezeTime();

    $a = vvHeartbeat($s['lesson'], 30, 20)->assertOk()->json();
    $b = vvHeartbeat($s['lesson'], 30, 20)->assertOk()->json();
    $this->travel(1)->seconds();
    $c = vvHeartbeat($s['lesson'], 30, 20)->assertOk()->json();

    expect($b)->toBe($a)->and($c)->toBe($a)
        ->and(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(20);
});

test('AC2/BR2: xem >= 90% thoi luong -> completed + completed_at, course_percent cap nhat; khong quay lai in_progress', function () {
    $s = vvLearnSet(duration: 100);
    $second = vvAddLesson($s['course'], $s['chapter'], 2, ['duration_seconds' => 100]);

    vvHeartbeat($s['lesson'], 30, 30)->assertOk()->assertJsonPath('completed', false);
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 60, 30)->assertOk()->assertJsonPath('completed', false);
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 90, 30)->assertOk()
        ->assertJsonPath('status', 'completed')->assertJsonPath('completed', true)->assertJsonPath('course_percent', 50);

    $row = vvProgressRow($s['student'], $s['lesson']);
    expect($row->completed_at)->not->toBeNull();
    $completedAt = $row->completed_at;

    // Xem lai (tua ve dau): van completed, completed_at khong doi.
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 5, 5)->assertOk()->assertJsonPath('status', 'completed');
    $row = vvProgressRow($s['student'], $s['lesson']);
    expect($row->status)->toBe('completed')->and($row->completed_at)->toBe($completedAt)->and($row->last_position_seconds)->toBe(5);

    expect(vvProgressRow($s['student'], $second))->toBeNull();
});

test('ranh gioi 90%: 89/100 chua xong, 90/100 xong', function () {
    $s = vvLearnSet(duration: 100);
    DB::table('lesson_progress')->insert(['user_id' => $s['student']->id, 'lesson_id' => $s['lesson']->id, 'course_id' => $s['course']->id,
        'watched_seconds' => 84, 'last_position_seconds' => 84, 'status' => 'in_progress', 'last_accessed_at' => now(), 'last_heartbeat_at' => now()->subSeconds(60), 'created_at' => now(), 'updated_at' => now()]);

    vvHeartbeat($s['lesson'], 89, 5)->assertOk()->assertJsonPath('completed', false);
    expect(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(89);
    $this->travel(10)->seconds();
    vvHeartbeat($s['lesson'], 90, 1)->assertOk()->assertJsonPath('completed', true);
});

test('bai chua co thoi luong khong tu hoan thanh; watched/position bi kep theo thoi luong', function () {
    $s = vvLearnSet(duration: 100);
    $noDuration = vvAddLesson($s['course'], $s['chapter'], 2, ['duration_seconds' => null]);

    for ($i = 0; $i < 4; $i++) {
        vvHeartbeat($noDuration, 500, 60)->assertOk()->assertJsonPath('completed', false);
        $this->travel(30)->seconds();
    }
    expect(vvProgressRow($s['student'], $noDuration)->status)->toBe('in_progress');

    vvHeartbeat($s['lesson'], 5000, 60)->assertOk();
    $row = vvProgressRow($s['student'], $s['lesson']);
    expect($row->last_position_seconds)->toBe(100)->and($row->watched_seconds)->toBeLessThanOrEqual(100);
});

test('validation: thieu/am/qua lon/khong phai so nguyen -> 422', function () {
    $s = vvLearnSet();
    $post = fn (array $body) => test()->postJson(vvApiUrl("/learn/lessons/{$s['lesson']->id}/heartbeat"), $body, vvWebHeaders());

    $post([])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    $post(['position_seconds' => -1, 'watched_delta_seconds' => 5])->assertStatus(422)->assertJsonValidationErrors('position_seconds', 'errors');
    $post(['position_seconds' => 5, 'watched_delta_seconds' => 61])->assertStatus(422);
    $post(['position_seconds' => 5, 'watched_delta_seconds' => -3])->assertStatus(422);
    $this->travel(61)->seconds(); // qua cua so throttle 6/phut
    $post(['position_seconds' => 86401, 'watched_delta_seconds' => 5])->assertStatus(422);
    $post(['position_seconds' => 'abc', 'watched_delta_seconds' => 5])->assertStatus(422);
    $post(['position_seconds' => 1.5, 'watched_delta_seconds' => 5])->assertStatus(422);

    expect(vvProgressRow($s['student'], $s['lesson']))->toBeNull();
});

test('khong co quyen: chua mua / pending / preview-only -> 403 COURSE_NOT_OWNED va khong tao dong tien do', function () {
    $s = vvLearnSet(owned: false);
    $s['lesson']->forceFill(['is_preview' => true])->save();

    vvHeartbeat($s['lesson'], 5, 5)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    Enrollment::factory()->pendingApproval()->create(['user_id' => $s['student']->id, 'course_id' => $s['course']->id]);
    vvHeartbeat($s['lesson'], 5, 5)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');

    expect(DB::table('lesson_progress')->count())->toBe(0);
});

test('thu hoi giua phien: heartbeat tiep theo bi chan, tien do da luu giu nguyen', function () {
    $s = vvLearnSet(duration: 600);
    vvHeartbeat($s['lesson'], 20, 20)->assertOk();
    vvSetEnrollment($s['student'], $s['course'], EnrollmentStatus::Revoked);
    $this->travel(20)->seconds();

    vvHeartbeat($s['lesson'], 40, 20)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    expect(vvProgressRow($s['student'], $s['lesson'])->watched_seconds)->toBe(20);
});

test('IDOR: bai cua khoa khac chua mua -> 403, bai khong ton tai/da xoa -> 404 va khong tao dong tien do (T09-2)', function () {
    $s = vvLearnSet();
    $other = vvLearnSet(owned: false);
    // vvLearnSet doi nguoi dang nhap: dang nhap lai hoc sinh dau.
    vvActAsStudent($s['student']);

    vvHeartbeat($other['lesson'], 5, 5)->assertForbidden();
    vvHeartbeat(999999, 5, 5)->assertNotFound();

    $s['lesson']->delete();
    vvHeartbeat($s['lesson'], 5, 5)->assertNotFound();

    expect(DB::table('lesson_progress')->count())->toBe(0);
});

test('bai trong chuong da xoa mem (bi xoa theo chuong) -> 404', function () {
    $s = vvLearnSet();
    $s['chapter']->lessons()->delete();
    $s['chapter']->delete();

    vvHeartbeat($s['lesson'], 5, 5)->assertNotFound();
});

test('chua dang nhap -> 401', function () {
    $lesson = Lesson::factory()->create();
    test()->postJson(vvApiUrl("/learn/lessons/{$lesson->id}/heartbeat"), ['position_seconds' => 1, 'watched_delta_seconds' => 1], vvWebHeaders())
        ->assertUnauthorized();
});

test('enrollments.last_accessed_at chi cap nhat toi da 1 lan/5 phut', function () {
    $s = vvLearnSet(duration: 600);
    $enrollment = fn () => DB::table('enrollments')->where('user_id', $s['student']->id)->where('course_id', $s['course']->id)->value('last_accessed_at');

    vvHeartbeat($s['lesson'], 10, 10)->assertOk();
    $first = $enrollment();
    expect($first)->not->toBeNull();

    $this->travel(60)->seconds();
    vvHeartbeat($s['lesson'], 70, 20)->assertOk();
    expect($enrollment())->toBe($first);

    $this->travel(5)->minutes();
    vvHeartbeat($s['lesson'], 100, 20)->assertOk();
    expect($enrollment())->not->toBe($first);
});

test('throttle heartbeat 6/phut/user/bai; bai khac khong bi anh huong', function () {
    $s = vvLearnSet(duration: 600);
    $other = vvAddLesson($s['course'], $s['chapter'], 2, ['duration_seconds' => 100]);

    for ($i = 0; $i < 6; $i++) {
        vvHeartbeat($s['lesson'], 10, 0)->assertOk();
    }
    vvHeartbeat($s['lesson'], 10, 0)->assertStatus(429);
    vvHeartbeat($other, 10, 0)->assertOk();
});

test('course_percent tinh theo bai chua xoa; bai xoa mem khong tinh vao mau so', function () {
    $s = vvLearnSet(duration: 10);
    $l2 = vvAddLesson($s['course'], $s['chapter'], 2, ['duration_seconds' => 10]);
    $l3 = vvAddLesson($s['course'], $s['chapter'], 3, ['duration_seconds' => 10]);

    vvHeartbeat($s['lesson'], 10, 10)->assertOk()->assertJsonPath('course_percent', 33);
    vvHeartbeat($l2, 10, 10)->assertOk()->assertJsonPath('course_percent', 66);
    $l3->delete();
    $this->travel(20)->seconds();
    vvHeartbeat($l2, 10, 1)->assertOk()->assertJsonPath('course_percent', 100);
});

test('xoa bai da co tien do bi chan (T09); bai moi chua co tien do xoa duoc roi heartbeat 404', function () {
    $s = vvLearnSet();
    $extra = vvAddLesson($s['course'], $s['chapter'], 2);
    vvHeartbeat($s['lesson'], 5, 5)->assertOk();

    expect(fn () => app(CurriculumService::class)->deleteLesson($s['course'], $s['chapter'], $s['lesson']))
        ->toThrow(DomainException::class);

    app(CurriculumService::class)->deleteLesson($s['course'], $s['chapter'], $extra);
    vvHeartbeat($extra, 5, 5)->assertNotFound();
});

test('chi cap nhat tien do cua chinh minh (user khac cung bai khong bi anh huong)', function () {
    $s = vvLearnSet(duration: 600);
    vvHeartbeat($s['lesson'], 20, 20)->assertOk();

    $b = vvActAsStudent(User::factory()->create());
    Enrollment::factory()->create(['user_id' => $b->id, 'course_id' => $s['course']->id]);
    vvHeartbeat($s['lesson'], 50, 20)->assertOk();

    expect(vvProgressRow($s['student'], $s['lesson'])->last_position_seconds)->toBe(20)
        ->and(vvProgressRow($b, $s['lesson'])->last_position_seconds)->toBe(50);
});

test('QA: position > duration kep ve duration; bai duration 0 khong hoan thanh; gui tiep sau completed van completed', function () {
    $s = vvLearnSet(duration: 100);
    $this->freezeTime();
    vvHeartbeat($s['lesson'], 9999, 60)->assertOk();
    expect(vvProgressRow($s['student'], $s['lesson'])->last_position_seconds)->toBe(100);

    $this->travel(60)->seconds();
    vvHeartbeat($s['lesson'], 100, 60)->assertOk()->assertJsonPath('status', 'completed');
    $this->travel(60)->seconds();
    vvHeartbeat($s['lesson'], 5, 0)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('completed', true);
    expect(vvProgressRow($s['student'], $s['lesson'])->status)->toBe('completed');

    $zero = vvAddLesson($s['course'], $s['chapter'], 2, ['duration_seconds' => 0]);
    $this->travel(100)->seconds();
    vvHeartbeat($zero, 50, 60)->assertOk()->assertJsonPath('status', 'in_progress');
});

test('QA: so thuc va chuoi so thuc -> 422', function () {
    $s = vvLearnSet();
    foreach ([[10.5, 5], [10, 5.5], ['10.0', 5], [10, '5.5']] as [$p, $d]) {
        $this->postJson(vvApiUrl("/learn/lessons/{$s['lesson']->id}/heartbeat"), ['position_seconds' => $p, 'watched_delta_seconds' => $d], vvWebHeaders())
            ->assertStatus(422);
    }
    expect(vvProgressRow($s['student'], $s['lesson']))->toBeNull();
});

test('QA: phien bi thay the -> 401 SESSION_REPLACED tren learn show/lesson/playback/heartbeat', function () {
    $s = vvLearnSet();
    User::query()->whereKey($s['student']->id)->update(['current_session_id' => 'other-session', 'current_device_id' => (string) Str::uuid()]);

    // actingAs giữ bản User cũ trong guard; nạp lại bản mới trước mỗi request (guard bị logout sau mỗi 401).
    $again = function () use ($s) {
        app('auth')->forgetGuards();
        test()->actingAs(User::query()->findOrFail($s['student']->id));
    };
    $again();
    vvLearnGet("/learn/courses/{$s['course']->id}")->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
    $again();
    vvLearnGet("/learn/lessons/{$s['lesson']->id}")->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
    $again();
    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
    $again();
    vvHeartbeat($s['lesson'], 5, 5)->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
    expect(vvProgressRow($s['student'], $s['lesson']))->toBeNull();
});
