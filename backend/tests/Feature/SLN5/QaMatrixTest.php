<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ManualCompleteTest.php';
require_once __DIR__.'/../T22/helpers.php';

/** Gọi mọi endpoint learn có thể trả 403/404 cho khóa $s, trả [tên => response]. */
function qaLearnProbe(array $s, Lesson $ext, ?Quiz $quiz = null): array
{
    $out = [
        'course' => vvLearnGet("/learn/courses/{$s['course']->id}"),
        'lesson' => vvLearnGet("/learn/lessons/{$s['lesson']->id}"),
        'playback' => vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback"),
        'heartbeat' => vvHeartbeat($s['lesson'], 5, 5),
        'complete' => vvComplete($ext),
        'progress' => vvLearnGet("/me/courses/{$s['course']->id}/progress"),
    ];
    if ($quiz !== null) {
        $out['quiz_start'] = vvAtStart($quiz);
        $out['quiz_list'] = vvLearnGet("/learn/quizzes/{$quiz->id}/attempts");
    }

    return $out;
}

test('QA khach chua dang nhap -> 401 o complete/heartbeat/learn', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    app('auth')->forgetGuards();
    $this->app['auth']->guard('web')->logout();
    $h = vvWebHeaders();
    $this->postJson(vvApiUrl("/learn/lessons/{$ext->id}/complete"), [], $h)->assertStatus(401);
    $this->postJson(vvApiUrl("/learn/lessons/{$ext->id}/heartbeat"), ['position_seconds' => 1, 'watched_delta_seconds' => 1], $h)->assertStatus(401);
    $this->getJson(vvApiUrl("/learn/lessons/{$s['lesson']->id}"), $h)->assertStatus(401);
    expect(vvProgressRow($s['student'], $ext))->toBeNull();
});

test('QA enrollment pending/rejected/revoked -> 403 kem course khi khoa published, khong ghi tien do', function (EnrollmentStatus $status) {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    vvSetEnrollment($s['student'], $s['course'], $status);

    vvComplete($ext)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED')->assertJsonPath('errors.course.slug', $s['course']->slug);
    vvHeartbeat($s['lesson'], 5, 5)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    expect(DB::table('lesson_progress')->count())->toBe(0);
})->with([EnrollmentStatus::PendingApproval, EnrollmentStatus::Rejected, EnrollmentStatus::Revoked]);

test('QA thu hoi enrollment giua chung roi khoa bi an (unpublished/draft) -> complete/heartbeat 404, khong lo slug/title', function (CourseStatus $status) {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    vvComplete($ext)->assertOk();
    vvSetEnrollment($s['student'], $s['course'], EnrollmentStatus::Revoked);
    $s['course']->forceFill(['status' => $status])->save();

    foreach ([vvComplete($ext), vvHeartbeat($s['lesson'], 5, 5)] as $r) {
        $r->assertNotFound();
        expect($r->getContent())->not->toContain($s['course']->slug)->not->toContain($s['course']->title);
    }
})->with([CourseStatus::Draft, CourseStatus::Unpublished]);

test('QA khong lo slug/title khoa nhap/an o BAT KY endpoint learn nao (chua ghi danh)', function (CourseStatus $status) {
    $s = vvLearnSet(owned: false);
    $s['course']->forceFill(['status' => $status])->save();
    $ext = vvExternalLesson($s);
    $quiz = Quiz::factory()->for($s['course'])->create(['chapter_id' => $s['chapter']->id, 'position' => 9]);

    foreach (qaLearnProbe($s, $ext, $quiz) as $name => $r) {
        expect($r->getStatusCode())->toBe(404, "endpoint {$name}");
        expect($r->getContent())->not->toContain($s['course']->slug)->not->toContain($s['course']->title)->not->toContain('"course"');
    }
})->with([CourseStatus::Draft, CourseStatus::Unpublished]);

test('QA 403 o moi endpoint khi khoa published va chua ghi danh: co course dung id/slug/title, khong them truong nhay cam', function () {
    $s = vvLearnSet(owned: false);
    $ext = vvExternalLesson($s);
    $quiz = Quiz::factory()->for($s['course'])->create(['chapter_id' => $s['chapter']->id, 'position' => 9]);

    foreach (qaLearnProbe($s, $ext, $quiz) as $name => $r) {
        $r->assertForbidden();
        expect($r->json('errors.course'))->toBe(['id' => $s['course']->id, 'slug' => $s['course']->slug, 'title' => $s['course']->title], "endpoint {$name}");
    }
});

test('QA 404 cua ID khong ton tai giong het 404 cua ID bai/khoa nhap (cung body, cung status)', function () {
    $s = vvLearnSet(owned: false);
    $s['course']->forceFill(['status' => CourseStatus::Draft])->save();
    $ext = vvExternalLesson($s);

    $pairs = [
        'complete' => [vvComplete(999999), vvComplete($ext)],
        'heartbeat' => [vvHeartbeat(999999, 5, 5), vvHeartbeat($s['lesson'], 5, 5)],
        'lesson' => [vvLearnGet('/learn/lessons/999999'), vvLearnGet("/learn/lessons/{$s['lesson']->id}")],
        'playback' => [vvLearnGet('/learn/lessons/999999/playback'), vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")],
        'course' => [vvLearnGet('/learn/courses/999999'), vvLearnGet("/learn/courses/{$s['course']->id}")],
        'progress' => [vvLearnGet('/me/courses/999999/progress'), vvLearnGet("/me/courses/{$s['course']->id}/progress")],
    ];
    $diffs = [];
    foreach ($pairs as $name => [$missing, $draft]) {
        expect($draft->getStatusCode())->toBe($missing->getStatusCode(), $name);
        if (Arr::except($draft->json(), ['request_id']) !== Arr::except($missing->json(), ['request_id'])) {
            $diffs[$name] = [$missing->json('message'), $draft->json('message')];
        }
    }
    expect($diffs)->toBe([]);
});

test('QA 404 cua ID khong ton tai va ID khoa nhap cung status + cung code NOT_FOUND', function () {
    $s = vvLearnSet(owned: false);
    $s['course']->forceFill(['status' => CourseStatus::Draft])->save();
    $ext = vvExternalLesson($s);
    foreach ([[vvComplete(999999), vvComplete($ext)], [vvHeartbeat(999999, 5, 5), vvHeartbeat($s['lesson'], 5, 5)]] as [$missing, $draft]) {
        expect($draft->getStatusCode())->toBe(404)->and($missing->getStatusCode())->toBe(404)
            ->and($draft->json('code'))->toBe($missing->json('code'));
    }
});

test('QA khoa co ca bai video va bai link ngoai: course_percent tinh chung, progress endpoint khop', function () {
    $s = vvLearnSet(duration: 100);
    $ext = vvExternalLesson($s);

    vvComplete($ext)->assertOk()->assertJsonPath('course_percent', 50);
    // video chua xong -> khong doi
    vvHeartbeat($s['lesson'], 30, 30)->assertOk()->assertJsonPath('course_percent', 50);
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 60, 30)->assertOk();
    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 90, 30)->assertOk()->assertJsonPath('completed', true)->assertJsonPath('course_percent', 100);

    $p = vvLearnGet("/me/courses/{$s['course']->id}/progress")->assertOk();
    $lessons = collect($p->json('chapters'))->flatMap(fn ($c) => $c['lessons']);
    expect($lessons->pluck('status')->unique()->all())->toBe(['completed'])->and($lessons)->toHaveCount(2);
});

test('QA bai link ngoai khong co heartbeat: heartbeat len bai link ngoai khong lam hong complete (idempotent giu completed_at)', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    vvComplete($ext)->assertOk();
    $at = vvProgressRow($s['student'], $ext)->completed_at;
    $this->travel(30)->seconds();
    vvHeartbeat($ext, 1, 1)->assertStatus(200);
    expect(vvProgressRow($s['student'], $ext)->completed_at)->toBe($at)->and(vvProgressRow($s['student'], $ext)->status)->toBe('completed');
});

test('QA 429 cua complete khong anh huong bai khac; body co code', function () {
    $s = vvLearnSet();
    $a = vvExternalLesson($s, 2);
    $b = vvExternalLesson($s, 3);
    for ($i = 0; $i < 6; $i++) {
        vvComplete($a)->assertOk();
    }
    vvComplete($a)->assertStatus(429);
    vvComplete($b)->assertOk();
});

test('QA ID khong phai so -> 404, method GET -> 405, body gui kem bi bo qua', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    $this->postJson(vvApiUrl('/learn/lessons/abc/complete'), [], vvWebHeaders())->assertNotFound();
    $this->getJson(vvApiUrl("/learn/lessons/{$ext->id}/complete"), vvWebHeaders())->assertStatus(405);
    $this->postJson(vvApiUrl("/learn/lessons/{$ext->id}/complete"), ['user_id' => 999, 'status' => 'x', 'course_percent' => 5], vvWebHeaders())
        ->assertOk()->assertJsonPath('course_percent', 50);
    expect(DB::table('lesson_progress')->where('user_id', 999)->count())->toBe(0);
});

test('QA hoc sinh A khong complete duoc bai thay cho B; khong anh huong enrollment last_accessed_at cua B', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    $other = User::factory()->create();
    Enrollment::factory()->create(['user_id' => $other->id, 'course_id' => $s['course']->id]);
    vvComplete($ext)->assertOk();
    expect(DB::table('lesson_progress')->where('user_id', $other->id)->count())->toBe(0);
});
