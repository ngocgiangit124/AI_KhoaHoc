<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../T13/helpers.php';
require_once __DIR__.'/../T09/helpers.php';
require_once __DIR__.'/../T21/helpers.php';

/** QA GL-A34 (A4): ngưỡng, thông báo 429, Retry-After, độc lập giữa người dùng, không 429 oan. */
function vvQaFreeEnroll()
{
    return test()->postJson(vvApiUrl('/courses/999999/free-enrollments'), [], vvWebHeaders());
}

test('AC-A4: free-enrollments 10 lần OK, lần 11 -> 429 tiếng Việt + Retry-After số dương', function () {
    vvActAsStudent(User::factory()->verified()->create());
    for ($i = 0; $i < 10; $i++) {
        vvQaFreeEnroll()->assertNotFound();
    }
    $res = vvQaFreeEnroll()->assertStatus(429);
    expect($res->json('message'))->toBe('Bạn thao tác quá nhanh, vui lòng thử lại sau.')
        ->and($res->json('code'))->toBe('TOO_MANY_ATTEMPTS');
    $retry = $res->headers->get('Retry-After');
    expect($retry)->not->toBeNull()->and((int) $retry)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

test('AC-A4: hai học sinh khác nhau cùng IP không chung bộ đếm free-enrollments', function () {
    vvActAsStudent(User::factory()->verified()->create());
    for ($i = 0; $i < 11; $i++) {
        vvQaFreeEnroll();
    }
    vvQaFreeEnroll()->assertStatus(429);

    vvActAsStudent(User::factory()->verified()->create());
    vvQaFreeEnroll()->assertNotFound(); // người thứ 2 chưa bị tính
});

test('AC-A4: trần 30/ngày; qua ngày mới thì mở lại', function () {
    vvActAsStudent(User::factory()->verified()->create());
    for ($i = 0; $i < 30; $i++) {
        if ($i > 0 && $i % 10 === 0) {
            test()->travel(61)->seconds();
        }
        vvQaFreeEnroll()->assertNotFound();
    }
    test()->travel(61)->seconds();
    vvQaFreeEnroll()->assertStatus(429);
    test()->travel(25)->hours();
    vvQaFreeEnroll()->assertNotFound();
});

test('AC-A4: learn lesson 429 có Retry-After và thông báo tiếng Việt; hai người cùng IP độc lập', function () {
    $s = vvLearnSet();
    $url = vvApiUrl("/learn/lessons/{$s['lesson']->id}");
    for ($i = 0; $i < 120; $i++) {
        test()->getJson($url, vvWebHeaders())->assertOk();
    }
    $res = test()->getJson($url, vvWebHeaders())->assertStatus(429);
    expect($res->json('message'))->toBe('Bạn thao tác quá nhanh, vui lòng thử lại sau.')
        ->and((int) $res->headers->get('Retry-After'))->toBeGreaterThan(0);

    $other = vvActAsStudent(User::factory()->create());
    Enrollment::factory()->create(['user_id' => $other->id, 'course_id' => $s['course']->id]);
    test()->getJson($url, vvWebHeaders())->assertOk();
});

test('AC-A4: lesson và course dùng chung 1 bộ đếm learn-read/người (121 gộp)', function () {
    $s = vvLearnSet();
    for ($i = 0; $i < 60; $i++) {
        test()->getJson(vvApiUrl("/learn/courses/{$s['course']->id}"), vvWebHeaders())->assertOk();
        test()->getJson(vvApiUrl("/learn/lessons/{$s['lesson']->id}"), vvWebHeaders())->assertOk();
    }
    test()->getJson(vvApiUrl("/learn/lessons/{$s['lesson']->id}"), vvWebHeaders())->assertStatus(429);
});

test('Không 429 oan: học sinh mở/chuyển bài liên tục (50 bài x 2 request) rồi qua phút mới thì mở lại', function () {
    $s = vvLearnSet();
    $lessons = [$s['lesson']];
    for ($p = 2; $p <= 20; $p++) {
        $lessons[] = vvAddLesson($s['course'], $s['chapter'], $p);
    }
    // 100 request trong 1 phút (người học rất nhanh) vẫn qua.
    for ($i = 0; $i < 50; $i++) {
        test()->getJson(vvApiUrl("/learn/courses/{$s['course']->id}"), vvWebHeaders())->assertOk();
        test()->getJson(vvApiUrl('/learn/lessons/'.$lessons[$i % 20]->id), vvWebHeaders())->assertOk();
    }
    test()->travel(61)->seconds();
    test()->getJson(vvApiUrl('/learn/lessons/'.$s['lesson']->id), vvWebHeaders())->assertOk();
});

/* ---------------- admin-write ---------------- */

test('admin-write: 300 GET không bị đếm, rồi vẫn ghi được', function () {
    vvCourseActor('admin');
    [$course] = vvContentSet();
    for ($i = 0; $i < 300; $i++) {
        vvCourseJson('GET', "/admin/courses/{$course->id}/chapters")->assertOk();
    }
    vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'Chương mới'])->assertCreated();
});

test('admin-write: kéo-thả sắp xếp 100 lần liên tục không 429; ghi thứ 121 -> 429 có Retry-After tiếng Việt', function () {
    vvCourseActor('admin');
    $course = Course::factory()->create();
    $c1 = Chapter::factory()->for($course)->create(['position' => 1]);
    $c2 = Chapter::factory()->for($course)->create(['position' => 2]);
    $path = "/admin/courses/{$course->id}/curriculum/order";

    for ($i = 0; $i < 120; $i++) {
        $order = $i % 2 === 0 ? [$c2, $c1] : [$c1, $c2];
        $res = vvCourseJson('PUT', $path, array_map(fn ($c) => ['chapter_id' => $c->id, 'lesson_ids' => []], $order));
        expect($res->status())->not->toBe(429, "request #{$i}");
    }
    $res = vvCourseJson('PUT', $path, [['chapter_id' => $c1->id, 'lesson_ids' => []], ['chapter_id' => $c2->id, 'lesson_ids' => []]]);
    $res->assertStatus(429);
    expect($res->json('message'))->toBe('Bạn thao tác quá nhanh, vui lòng thử lại sau.')
        ->and((int) $res->headers->get('Retry-After'))->toBeGreaterThan(0);

    // GET vẫn đọc được khi đang bị chặn ghi.
    vvCourseJson('GET', "/admin/courses/{$course->id}/chapters")->assertOk();
});

test('admin-write: soạn quiz liên tục 60 câu hỏi + 60 sửa không 429', function () {
    vvCourseActor('admin');
    [$course, $chapter] = vvContentSet();
    $quiz = Quiz::factory()->for($course)->create(['chapter_id' => $chapter->id, 'position' => 1]);
    for ($i = 0; $i < 60; $i++) {
        $r = vvCourseJson('POST', vvQuestionsPath($course, $quiz), vvQuestionPayload());
        expect($r->status())->toBe(201, "câu #{$i}");
    }
    $id = $quiz->questions()->first()->id;
    for ($i = 0; $i < 60; $i++) {
        $r = vvCourseJson('PUT', vvQuestionsPath($course, $quiz)."/{$id}", vvQuestionPayload());
        expect($r->status())->not->toBe(429);
    }
});

test('admin-write: hai staff khác nhau độc lập bộ đếm', function () {
    vvCourseActor('admin');
    $course = Course::factory()->create();
    $c1 = Chapter::factory()->for($course)->create(['position' => 1]);
    $path = "/admin/courses/{$course->id}/curriculum/order";
    $body = [['chapter_id' => $c1->id, 'lesson_ids' => []]];
    for ($i = 0; $i < 121; $i++) {
        vvCourseJson('PUT', $path, $body);
    }
    vvCourseJson('PUT', $path, $body)->assertStatus(429);

    vvCourseActor('admin'); // staff khác
    expect(vvCourseJson('PUT', $path, $body)->status())->not->toBe(429);
});

/* ---------------- kiến trúc ---------------- */

test('Kiến trúc: mọi route ghi có throttle ở CẢ HAI host; hai host đều có route ghi được kiểm', function () {
    $api = $admin = 0;
    $missing = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            continue;
        }
        $domain = (string) $route->getDomain();
        $isApi = $domain === config('app.api_host');
        $isAdmin = $domain === config('app.admin_api_host');
        $api += (int) $isApi;
        $admin += (int) $isAdmin;
        if (! $isApi && ! $isAdmin) {
            continue;
        }
        $ok = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
        if (! $ok && ! in_array($route->getName(), ['api.auth.logout', 'admin.auth.logout'], true)) {
            $missing[] = $route->getName() ?? $route->uri();
        }
    }
    expect($api)->toBeGreaterThan(20)->and($admin)->toBeGreaterThan(30)->and($missing)->toBe([]);
});
