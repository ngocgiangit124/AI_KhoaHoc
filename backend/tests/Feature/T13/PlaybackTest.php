<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\VideoAssetStatus;
use App\Enums\VideoSource;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T08/helpers.php';

test('chu khoa: playback hls co han, khong lo field la, resume 0 khi chua hoc', function () {
    $s = vvLearnSet();

    $res = vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertOk()
        ->assertJsonPath('kind', 'hls')
        ->assertJsonPath('resume_at_seconds', 0)
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(array_keys($res->json()))->toEqualCanonicalizing(['kind', 'url', 'expires_at', 'resume_at_seconds'])
        ->and($res->json('url'))->toStartWith('https://fake-video.vitaminvui.test/cdn/')
        ->and(strtotime($res->json('expires_at')))->toBeBetween(time() + 14 * 60, time() + 15 * 60 + 5);
});

test('resume_at_seconds theo vi tri da luu; ve 0 khi da xem gan het', function () {
    $s = vvLearnSet(duration: 100);

    vvHeartbeat($s['lesson'], 40, 20)->assertOk();
    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertJsonPath('resume_at_seconds', 40);

    $this->travel(20)->seconds();
    vvHeartbeat($s['lesson'], 98, 20)->assertOk();
    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertJsonPath('resume_at_seconds', 0);
});

test('AC3: chua mua bai khong preview -> 403 COURSE_NOT_OWNED (chua co, pending, rejected, revoked)', function () {
    $s = vvLearnSet(owned: false);
    $path = "/learn/lessons/{$s['lesson']->id}/playback";

    vvLearnGet($path)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');

    foreach (['pendingApproval', 'rejected', 'revoked'] as $state) {
        $e = Enrollment::factory()->{$state}()->create(['user_id' => $s['student']->id, 'course_id' => $s['course']->id]);
        vvLearnGet($path)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
        $e->delete();
    }
});

test('thu hoi giua phien: lan cap link tiep theo bi chan ngay', function () {
    $s = vvLearnSet();
    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertOk();

    vvSetEnrollment($s['student'], $s['course'], EnrollmentStatus::Revoked);

    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
});

test('BR4: khoa bi go xuat ban, chu khoa van xem; nguoi khac 404 (khong do duoc ID bai khoa nhap)', function () {
    $s = vvLearnSet();
    $s['course']->forceFill(['status' => CourseStatus::Unpublished])->save();

    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertOk();

    vvSetEnrollment($s['student'], $s['course'], EnrollmentStatus::Revoked);
    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertNotFound();
});

test('bai preview: hoc sinh chua mua xem duoc (khong rang IP), resume 0', function () {
    $s = vvLearnSet(owned: false);
    $s['lesson']->forceFill(['is_preview' => true])->save();

    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertOk()->assertJsonPath('kind', 'hls')->assertJsonPath('resume_at_seconds', 0);
});

test('rang IP: link bai tra phi doi theo IP; bai preview khong doi theo IP', function () {
    $s = vvLearnSet();
    $path = "/learn/lessons/{$s['lesson']->id}/playback";
    $this->freezeTime();

    $a = vvLearnGet($path, ['REMOTE_ADDR' => '10.1.1.1'])->json('url');
    $b = vvLearnGet($path, ['REMOTE_ADDR' => '10.2.2.2'])->json('url');
    expect($a)->not->toBe($b);

    $s['lesson']->forceFill(['is_preview' => true])->save();
    $c = vvLearnGet($path, ['REMOTE_ADDR' => '10.1.1.1'])->json('url');
    $d = vvLearnGet($path, ['REMOTE_ADDR' => '10.2.2.2'])->json('url');
    expect($c)->toBe($d);

    config(['video.bind_ip' => false]);
    $s['lesson']->forceFill(['is_preview' => false])->save();
    expect(vvLearnGet($path, ['REMOTE_ADDR' => '10.1.1.1'])->json('url'))->toBe(vvLearnGet($path, ['REMOTE_ADDR' => '10.2.2.2'])->json('url'));
});

test('link ngoai: tra embed dung lai tu ID, khong tra URL nguoi nhap', function () {
    $s = vvLearnSet(owned: false);
    $lesson = vvAddLesson($s['course'], $s['chapter'], 2, ['is_preview' => true, 'video_source' => VideoSource::ExternalLink, 'external_provider' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ']);

    vvLearnGet("/learn/lessons/{$lesson->id}/playback")->assertOk()
        ->assertJsonPath('kind', 'embed')
        ->assertJsonPath('url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->assertJsonPath('expires_at', null);
});

test('video chua san sang -> 409 VIDEO_NOT_READY; bai khong co video -> 404 VIDEO_NOT_AVAILABLE; provider loi -> 503', function () {
    $s = vvLearnSet();
    $path = "/learn/lessons/{$s['lesson']->id}/playback";

    vvSetAssetStatus($s['asset'], VideoAssetStatus::Processing);
    vvLearnGet($path)->assertStatus(409)->assertJsonPath('code', 'VIDEO_NOT_READY');

    vvSetAssetStatus($s['asset'], VideoAssetStatus::Ready);
    vvVideoFake()->setUnavailable();
    vvLearnGet($path)->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
    vvVideoFake()->setUnavailable(false);

    $none = vvAddLesson($s['course'], $s['chapter'], 2);
    vvLearnGet("/learn/lessons/{$none->id}/playback")->assertNotFound()->assertJsonPath('code', 'VIDEO_NOT_AVAILABLE');

    config(['video.enabled_providers' => ['internal']]);
    vvLearnGet($path)->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
});

test('bai/khoa da xoa mem -> 404; chua dang nhap -> 401', function () {
    $s = vvLearnSet();
    $path = "/learn/lessons/{$s['lesson']->id}/playback";
    $s['lesson']->delete();
    vvLearnGet($path)->assertNotFound();
    vvLearnGet('/learn/lessons/999999/playback')->assertNotFound();
});

test('chua dang nhap -> 401', function () {
    $lesson = Lesson::factory()->create();
    test()->getJson(vvApiUrl("/learn/lessons/{$lesson->id}/playback"), vvWebHeaders())->assertUnauthorized();
});

test('throttle playback 30/phut/user', function () {
    $s = vvLearnSet();
    $path = "/learn/lessons/{$s['lesson']->id}/playback";

    for ($i = 0; $i < 30; $i++) {
        vvLearnGet($path)->assertOk();
    }
    vvLearnGet($path)->assertStatus(429);
});

test('log channel playback moi lan cap link, khong ghi URL', function () {
    $s = vvLearnSet();
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once()->withArgs(function ($msg, $ctx) use ($s) {
        return $msg === 'playback' && $ctx['user_id'] === $s['student']->id && $ctx['lesson_id'] === $s['lesson']->id && ! isset($ctx['url']);
    });
    Log::shouldReceive('channel')->with('playback')->andReturn($logger);

    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertOk();
});

test('canh bao khi 1 user nhan link tu qua 3 IP / qua 60 bai trong 1 gio (moi loai canh bao 1 lan)', function () {
    $s = vvLearnSet();
    $path = "/learn/lessons/{$s['lesson']->id}/playback";
    $logger = Mockery::mock();
    $logger->shouldReceive('info');
    $logger->shouldReceive('warning')->once()->with('playback.anomaly.ips', Mockery::type('array'));
    Log::shouldReceive('channel')->with('playback')->andReturn($logger);

    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5'] as $ip) {
        vvLearnGet($path, ['REMOTE_ADDR' => $ip])->assertOk();
    }
});

// ---- Preview cong khai ----

test('preview cong khai: bai is_preview khoa published -> 200, khong cookie, khong rang IP', function () {
    vvVideoFake();
    $s = vvLearnSet(owned: false);
    $s['lesson']->forceFill(['is_preview' => true])->save();
    auth()->forgetGuards();

    $res = test()->getJson(vvApiUrl("/preview/lessons/{$s['lesson']->id}/playback"), vvWebHeaders())->assertOk()
        ->assertJsonPath('kind', 'hls')->assertJsonPath('resume_at_seconds', 0)
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($res->headers->getCookies())->toBeEmpty();
});

test('preview cong khai: khong preview / khoa nhap / bai xoa / chuong xoa / khoa xoa / khong ton tai -> 404 nhu nhau', function () {
    $s = vvLearnSet(owned: false);
    $url = fn (Lesson $l) => vvApiUrl("/preview/lessons/{$l->id}/playback");
    $get = fn (Lesson $l) => test()->getJson($url($l), vvWebHeaders());

    $get($s['lesson'])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

    $s['lesson']->forceFill(['is_preview' => true])->save();
    $get($s['lesson'])->assertOk();

    $s['course']->forceFill(['status' => CourseStatus::Unpublished])->save();
    $get($s['lesson'])->assertNotFound();
    $s['course']->forceFill(['status' => CourseStatus::Published])->save();

    $s['chapter']->delete();
    $get($s['lesson'])->assertNotFound();
    $s['chapter']->restore();

    $s['lesson']->delete();
    $get($s['lesson'])->assertNotFound();

    $other = vvLearnSet(owned: false);
    $other['lesson']->forceFill(['is_preview' => true])->save();
    $other['course']->delete();
    $get($other['lesson'])->assertNotFound();

    test()->getJson(vvApiUrl('/preview/lessons/999999/playback'), vvWebHeaders())->assertNotFound();
});

test('preview cong khai: throttle theo IP 30/phut', function () {
    $s = vvLearnSet(owned: false);
    $s['lesson']->forceFill(['is_preview' => true])->save();
    auth()->forgetGuards();

    for ($i = 0; $i < 30; $i++) {
        test()->getJson(vvApiUrl("/preview/lessons/{$s['lesson']->id}/playback"), vvWebHeaders())->assertOk();
    }
    test()->getJson(vvApiUrl("/preview/lessons/{$s['lesson']->id}/playback"), vvWebHeaders())->assertStatus(429);
});

// ---- Admin ----

test('admin playback: admin va giao vien duoc gan xem duoc (khong rang IP, khong ghi tien do); giao vien khac 403; bai khoa khac 404', function () {
    vvVideoFake();
    $admin = vvCourseActor('admin');
    $course = Course::factory()->create(); // chua publish cung xem duoc
    $chapter = Chapter::factory()->for($course)->create();
    $lesson = Lesson::factory()->for($course)->for($chapter)->create(['video_source' => VideoSource::Upload, 'duration_seconds' => 60]);
    $asset = VideoAsset::factory()->ready(60)->create(['provider' => 'fake', 'lesson_id' => $lesson->id]);
    $lesson->forceFill(['video_asset_id' => $asset->id])->save();
    $path = "/admin/courses/{$course->id}/lessons/{$lesson->id}/playback";

    vvCourseJson('GET', $path)->assertOk()->assertJsonPath('kind', 'hls');
    expect(DB::table('lesson_progress')->count())->toBe(0);

    $other = Lesson::factory()->create();
    vvCourseJson('GET', "/admin/courses/{$course->id}/lessons/{$other->id}/playback")->assertNotFound();

    $teacher = vvCourseActor('teacher');
    vvCourseJson('GET', $path)->assertForbidden();

    vvAssign($course, $teacher);
    vvCourseJson('GET', $path)->assertOk();
});

test('admin playback: hoc sinh (host admin) khong dung duoc', function () {
    $s = vvLearnSet();
    test()->getJson(vvAdminUrl("/admin/courses/{$s['course']->id}/lessons/{$s['lesson']->id}/playback"), vvAdminHeaders())->assertForbidden();
});

test('QA: link ngoai ID hong -> 404 VIDEO_NOT_AVAILABLE, khong lo URL goc', function () {
    $s = vvLearnSet();
    $l = vvAddLesson($s['course'], $s['chapter'], 2, ['video_source' => VideoSource::ExternalLink, 'external_provider' => 'youtube', 'external_video_id' => '../x"onload=1']);
    $r = vvLearnGet("/learn/lessons/{$l->id}/playback");
    $r->assertNotFound()->assertJsonPath('code', 'VIDEO_NOT_AVAILABLE');
    expect($r->getContent())->not->toContain('onload');

    $l2 = vvAddLesson($s['course'], $s['chapter'], 3, ['video_source' => VideoSource::ExternalLink, 'external_provider' => 'youtube', 'external_video_id' => null]);
    vvLearnGet("/learn/lessons/{$l2->id}/playback")->assertNotFound()->assertJsonPath('code', 'VIDEO_NOT_AVAILABLE');
});

test('QA: enrollment pending/rejected -> 403 tren outline, lesson show, playback', function () {
    foreach ([EnrollmentStatus::PendingApproval, EnrollmentStatus::Rejected] as $st) {
        $s = vvLearnSet();
        vvSetEnrollment($s['student'], $s['course'], $st);
        vvLearnGet("/learn/courses/{$s['course']->id}")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
        vvLearnGet("/learn/lessons/{$s['lesson']->id}")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
        vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    }
});
