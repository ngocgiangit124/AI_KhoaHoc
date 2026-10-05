<?php

use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;

require_once __DIR__.'/helpers.php';

/** @return array{0: Course, 1: Chapter, 2: Chapter, 3: list<Lesson>} */
function vvTwoChapters(): array
{
    $course = Course::factory()->create();
    $c1 = Chapter::factory()->for($course)->create(['position' => 1]);
    $c2 = Chapter::factory()->for($course)->create(['position' => 2]);
    $lessons = [
        Lesson::factory()->for($course)->for($c1)->create(['position' => 1]),
        Lesson::factory()->for($course)->for($c1)->create(['position' => 2]),
        Lesson::factory()->for($course)->for($c2)->create(['position' => 1]),
    ];

    return [$course, $c1, $c2, $lessons];
}

test('reorder: doi thu tu chuong, chuyen bai sang chuong khac, position lien tuc tu 1, audit', function (string $state) {
    vvCourseActor($state);
    [$course, $c1, $c2, [$l1, $l2, $l3]] = vvTwoChapters();

    $res = vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", [
        ['chapter_id' => $c2->id, 'lesson_ids' => [$l2->id, $l3->id]],
        ['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id]],
    ])->assertOk();

    expect(array_column($res->json('chapters'), 'id'))->toBe([$c2->id, $c1->id])
        ->and(array_column($res->json('chapters.0.lessons'), 'id'))->toBe([$l2->id, $l3->id]);
    expect($c2->fresh()->position)->toBe(1)->and($c1->fresh()->position)->toBe(2)
        ->and($l2->fresh()->chapter_id)->toBe($c2->id)->and($l2->fresh()->position)->toBe(1)
        ->and($l3->fresh()->position)->toBe(2)->and($l1->fresh()->position)->toBe(1)
        ->and($l2->fresh()->course_id)->toBe($course->id);
    expect(AuditLog::query()->where('action', 'curriculum.reorder')->count())->toBe(1);

    // chương rỗng được phép
    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", [
        ['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id, $l3->id]],
        ['chapter_id' => $c2->id, 'lesson_ids' => []],
    ])->assertOk();
})->with(['admin', 'pageManager']);

test('reorder S5: thieu/thua/trung/ID khoa khac/da xoa -> 422, khong doi gi', function (string $case) {
    vvCourseActor();
    [$course, $c1, $c2, [$l1, $l2, $l3]] = vvTwoChapters();
    [, $foreignChapter, , [$foreignLesson]] = vvTwoChapters();
    $gone = Lesson::factory()->for($course)->for($c1)->create(['position' => 9, 'deleted_at' => now()]);

    $payload = match ($case) {
        'thieu bai' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id]], ['chapter_id' => $c2->id, 'lesson_ids' => [$l3->id]]],
        'thieu chuong' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id, $l3->id]]],
        'bai khoa khac' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id, $foreignLesson->id]], ['chapter_id' => $c2->id, 'lesson_ids' => [$l3->id]]],
        'chuong khoa khac' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id]], ['chapter_id' => $foreignChapter->id, 'lesson_ids' => [$l3->id]]],
        'bai da xoa' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id, $gone->id]], ['chapter_id' => $c2->id, 'lesson_ids' => [$l3->id]]],
        'bai trung' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l1->id, $l2->id]], ['chapter_id' => $c2->id, 'lesson_ids' => [$l3->id]]],
        'chuong trung' => [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id]], ['chapter_id' => $c1->id, 'lesson_ids' => [$l3->id]]],
        'sai hinh dang' => ['chapters' => []],
        'chuoi' => [['chapter_id' => 'x', 'lesson_ids' => 'y']],
    };

    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", $payload)->assertStatus(422);

    expect($c1->fresh()->position)->toBe(1)->and($l2->fresh()->position)->toBe(2)->and($l3->fresh()->chapter_id)->toBe($c2->id)
        ->and($foreignLesson->fresh()->chapter_id)->toBe($foreignChapter->id);
})->with(['thieu bai', 'thieu chuong', 'bai khoa khac', 'chuong khoa khac', 'bai da xoa', 'bai trung', 'chuong trung', 'sai hinh dang', 'chuoi']);

test('reorder: mismatch tra ma CURRICULUM_MISMATCH (nguoi kia vua them/xoa bai)', function () {
    vvCourseActor();
    [$course, $c1, $c2, [$l1, $l2, $l3]] = vvTwoChapters();
    $stale = [['chapter_id' => $c1->id, 'lesson_ids' => [$l1->id, $l2->id]], ['chapter_id' => $c2->id, 'lesson_ids' => [$l3->id]]];

    Lesson::factory()->for($course)->for($c2)->create(['position' => 2]); // người khác vừa thêm bài

    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", $stale)
        ->assertStatus(422)->assertJsonPath('code', 'CURRICULUM_MISMATCH');
});

test('reorder: khoa chua co chuong, gui mang rong -> OK', function () {
    vvCourseActor();
    $course = Course::factory()->create();

    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", [])->assertOk()->assertJsonPath('chapters', []);
});

test('reorder: query string khong lam hong payload', function () {
    vvCourseActor();
    [$course, $c1, $c2, [$l1, $l2, $l3]] = vvTwoChapters();

    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order?x=1", [
        ['chapter_id' => $c1->id, 'lesson_ids' => [$l2->id, $l1->id]],
        ['chapter_id' => $c2->id, 'lesson_ids' => [$l3->id]],
    ])->assertOk();
    expect($l2->fresh()->position)->toBe(1);
});

test('reorder: body khong phai JSON -> 422; qua 500 chuong -> 422', function () {
    vvCourseActor();
    $course = Course::factory()->create();
    $url = vvAdminUrl("/admin/courses/{$course->id}/curriculum/order");

    test()->call('PUT', $url, [], [], [], test()->transformHeadersToServerVars(vvAdminHeaders(['Accept' => 'application/json'])))
        ->assertStatus(422)->assertJsonValidationErrors('body');
    test()->call('PUT', $url, [], [], [], test()->transformHeadersToServerVars(vvAdminHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])), '{hong')
        ->assertStatus(422)->assertJsonValidationErrors('body');

    $many = array_map(fn ($i) => ['chapter_id' => $i, 'lesson_ids' => []], range(1, 501));
    vvCourseJson('PUT', "/admin/courses/{$course->id}/curriculum/order", $many)->assertStatus(422)->assertJsonValidationErrors('body');
});
