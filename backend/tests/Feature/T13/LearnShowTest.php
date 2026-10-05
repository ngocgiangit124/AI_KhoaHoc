<?php

use App\Enums\CourseStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Learning\CourseProgressService;

require_once __DIR__.'/helpers.php';

function vvLearnTree(): array
{
    $s = vvLearnSet(duration: 10);
    $c2 = Chapter::factory()->for($s['course'])->create(['position' => 2]);
    $l2 = vvAddLesson($s['course'], $s['chapter'], 2, ['duration_seconds' => 10]);
    $l3 = vvAddLesson($s['course'], $c2, 1, ['duration_seconds' => 10]);

    return $s + ['c2' => $c2, 'l2' => $l2, 'l3' => $l3];
}

test('outline: chuong/bai theo thu tu, trang thai tung bai, khong co URL/ID video, resume la bai dau khi chua hoc', function () {
    $t = vvLearnTree();

    $res = vvLearnGet("/learn/courses/{$t['course']->id}")->assertOk()
        ->assertJsonPath('course.id', $t['course']->id)
        ->assertJsonPath('course_percent', 0)
        ->assertJsonPath('resume_lesson_id', $t['lesson']->id)
        ->assertJsonPath('chapters.0.lessons.0.status', 'not_started')
        ->assertJsonPath('chapters.0.lessons.0.video_ready', true)
        ->assertJsonPath('chapters.1.lessons.0.id', $t['l3']->id);

    $raw = json_encode($res->json());
    expect($raw)->not->toContain($t['asset']->provider_video_id)
        ->and($raw)->not->toContain('video_asset')->and($raw)->not->toContain('external_video_id')->and($raw)->not->toContain('http');
    expect(array_map(fn ($l) => $l['id'], $res->json('chapters.0.lessons')))->toBe([$t['lesson']->id, $t['l2']->id]);
});

test('AC6: resume = bai chua xong ke tiep sau bai gan nhat da xong; dang hoc do thi chinh bai do', function () {
    $t = vvLearnTree();

    vvHeartbeat($t['lesson'], 10, 10)->assertOk()->assertJsonPath('completed', true);
    vvLearnGet("/learn/courses/{$t['course']->id}")->assertJsonPath('resume_lesson_id', $t['l2']->id)
        ->assertJsonPath('chapters.0.lessons.0.status', 'completed')->assertJsonPath('course_percent', 33);

    $this->travel(20)->seconds();
    vvHeartbeat($t['l2'], 3, 3)->assertOk();
    vvLearnGet("/learn/courses/{$t['course']->id}")->assertJsonPath('resume_lesson_id', $t['l2']->id)
        ->assertJsonPath('chapters.0.lessons.1.status', 'in_progress');

    // Xong het -> bai gan nhat.
    $this->travel(20)->seconds();
    vvHeartbeat($t['l2'], 10, 10);
    $this->travel(20)->seconds();
    vvHeartbeat($t['l3'], 10, 10)->assertJsonPath('course_percent', 100);
    vvLearnGet("/learn/courses/{$t['course']->id}")->assertJsonPath('resume_lesson_id', $t['l3']->id);
});

test('outline bo qua bai/chuong da xoa mem; khoa khong co bai -> resume null', function () {
    $t = vvLearnTree();
    $t['l2']->delete();
    $t['c2']->lessons()->delete();
    $t['c2']->delete();

    $res = vvLearnGet("/learn/courses/{$t['course']->id}")->assertOk();
    expect($res->json('chapters'))->toHaveCount(1)->and($res->json('chapters.0.lessons'))->toHaveCount(1);

    $empty = Course::factory()->published()->create();
    Enrollment::factory()->create(['user_id' => $t['student']->id, 'course_id' => $empty->id]);
    vvLearnGet("/learn/courses/{$empty->id}")->assertOk()->assertJsonPath('resume_lesson_id', null)->assertJsonPath('chapters', []);
});

test('outline: chua so huu -> 403 COURSE_NOT_OWNED (khoa published), 404 (khoa nhap / khong co); khoa nhap cua chu khoa van vao duoc', function () {
    $s = vvLearnSet(owned: false);
    vvLearnGet("/learn/courses/{$s['course']->id}")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');

    $s['course']->forceFill(['status' => CourseStatus::Unpublished])->save();
    vvLearnGet("/learn/courses/{$s['course']->id}")->assertNotFound();
    vvLearnGet('/learn/courses/999999')->assertNotFound();

    Enrollment::factory()->create(['user_id' => $s['student']->id, 'course_id' => $s['course']->id]);
    vvLearnGet("/learn/courses/{$s['course']->id}")->assertOk();
});

test('chi tiet bai: prev/next xuyen chuong, progress, quiz gan kem KHONG co dap an/giai thich', function () {
    $t = vvLearnTree();
    $quiz = Quiz::factory()->create(['course_id' => $t['course']->id, 'chapter_id' => null, 'lesson_id' => $t['l2']->id, 'title' => 'Kiem tra nhanh']);
    $q = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'explanation' => 'GIAI-THICH-BI-MAT']);
    QuizOption::factory()->create(['question_id' => $q->id, 'is_correct' => true]);

    vvHeartbeat($t['l2'], 4, 4)->assertOk();

    $res = vvLearnGet("/learn/lessons/{$t['l2']->id}")->assertOk()
        ->assertJsonPath('lesson.id', $t['l2']->id)
        ->assertJsonPath('prev.id', $t['lesson']->id)
        ->assertJsonPath('next.id', $t['l3']->id)
        ->assertJsonPath('can_track', true)
        ->assertJsonPath('progress.status', 'in_progress')
        ->assertJsonPath('progress.last_position_seconds', 4)
        ->assertJsonPath('quizzes.0.title', 'Kiem tra nhanh')
        ->assertJsonPath('quizzes.0.question_count', 1);

    expect(json_encode($res->json()))->not->toContain('is_correct')->not->toContain('GIAI-THICH-BI-MAT')->not->toContain('explanation');

    vvLearnGet("/learn/lessons/{$t['lesson']->id}")->assertJsonPath('prev', null)->assertJsonPath('progress', null);
    vvLearnGet("/learn/lessons/{$t['l3']->id}")->assertJsonPath('next', null);
});

test('chi tiet bai: nguoi chua mua chi xem bai preview, prev/next chi trong cac bai preview, can_track=false', function () {
    $t = vvLearnTree();
    Enrollment::query()->where('user_id', $t['student']->id)->delete();
    $t['lesson']->forceFill(['is_preview' => true])->save();
    $t['l3']->forceFill(['is_preview' => true])->save();

    vvLearnGet("/learn/lessons/{$t['lesson']->id}")->assertOk()
        ->assertJsonPath('can_track', false)->assertJsonPath('next.id', $t['l3']->id)->assertJsonPath('prev', null);
    vvLearnGet("/learn/lessons/{$t['l2']->id}")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');

    $t['lesson']->delete();
    vvLearnGet("/learn/lessons/{$t['lesson']->id}")->assertNotFound();
});

test('chi tiet bai: bai khoa nhap cua nguoi khac -> 404', function () {
    $s = vvLearnSet(owned: false);
    $s['course']->forceFill(['status' => CourseStatus::Draft])->save();
    vvLearnGet("/learn/lessons/{$s['lesson']->id}")->assertNotFound();
});

test('CourseProgressService: phan tram theo lo cho nhieu khoa (cho T23)', function () {
    $t = vvLearnTree();
    $other = Course::factory()->published()->create();
    vvHeartbeat($t['lesson'], 10, 10)->assertOk();

    $svc = app(CourseProgressService::class);
    expect($svc->percentsFor($t['student']->id, [$t['course']->id, $other->id]))->toBe([$t['course']->id => 33, $other->id => 0])
        ->and($svc->percentsFor($t['student']->id, []))->toBe([])
        ->and($svc->completedLessonIds($t['student']->id, $t['course']->id))->toBe([$t['lesson']->id])
        ->and($svc->percent(User::factory()->create()->id, $t['course']->id))->toBe(0);
});
