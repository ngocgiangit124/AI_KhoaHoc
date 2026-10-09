<?php

use App\Models\Enrollment;
use App\Models\User;

require_once __DIR__.'/../T13/helpers.php';

/**
 * GL-A34 (A4) — throttle cho route học sinh đã đăng nhập: free-enrollments (10/phút), learn courses/lessons (120/phút).
 */
test('free-enrollments: request thứ 11 trong phút -> 429', function () {
    vvActAsStudent(User::factory()->verified()->create());

    for ($i = 0; $i < 10; $i++) {
        // khóa không tồn tại -> 404 nhưng vẫn bị đếm; đủ để kiểm trần mà không tạo 10 khóa.
        test()->postJson(vvApiUrl('/courses/999999/free-enrollments'), [], vvWebHeaders())->assertNotFound();
    }
    test()->postJson(vvApiUrl('/courses/999999/free-enrollments'), [], vvWebHeaders())->assertStatus(429);
});

test('free-enrollments: trần 30/ngày/người dù qua phút mới', function () {
    vvActAsStudent(User::factory()->verified()->create());

    for ($i = 0; $i < 30; $i++) {
        if ($i > 0 && $i % 10 === 0) {
            test()->travel(61)->seconds();
        }
        test()->postJson(vvApiUrl('/courses/999999/free-enrollments'), [], vvWebHeaders())->assertNotFound();
    }
    test()->travel(61)->seconds();
    test()->postJson(vvApiUrl('/courses/999999/free-enrollments'), [], vvWebHeaders())->assertStatus(429);
});

test('GET /learn/courses/{course}: 120/phút/người, request 121 -> 429; học bình thường không bị chặn', function () {
    $s = vvLearnSet();
    $url = vvApiUrl("/learn/courses/{$s['course']->id}");

    for ($i = 0; $i < 120; $i++) {
        test()->getJson($url, vvWebHeaders())->assertOk();
    }
    test()->getJson($url, vvWebHeaders())->assertStatus(429);
});

test('GET /learn/lessons/{lesson}: 120/phút/người, request 121 -> 429', function () {
    $s = vvLearnSet();
    $url = vvApiUrl("/learn/lessons/{$s['lesson']->id}");

    for ($i = 0; $i < 120; $i++) {
        test()->getJson($url, vvWebHeaders())->assertOk();
    }
    test()->getJson($url, vvWebHeaders())->assertStatus(429);
});

test('learn-read là bộ đếm riêng theo người: người khác không bị ảnh hưởng', function () {
    $s = vvLearnSet();
    $url = vvApiUrl("/learn/courses/{$s['course']->id}");
    for ($i = 0; $i < 121; $i++) {
        test()->getJson($url, vvWebHeaders());
    }
    test()->getJson($url, vvWebHeaders())->assertStatus(429);

    $other = vvActAsStudent(User::factory()->create());
    Enrollment::factory()->create(['user_id' => $other->id, 'course_id' => $s['course']->id]);
    test()->getJson($url, vvWebHeaders())->assertOk();
});
