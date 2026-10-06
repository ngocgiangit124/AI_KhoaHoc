<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../T13/helpers.php';

function vvMcGet(string $path = '/me/courses')
{
    return test()->getJson(vvApiUrl($path), vvWebHeaders());
}

/** Khóa published có $n bài (1 chương), HS đã ghi danh active; $done bài đầu đã hoàn thành. */
function vvMcCourse(User $student, int $n = 4, int $done = 0, array $enroll = [], array $course = []): array
{
    $c = Course::factory()->published()->create($course);
    $ch = Chapter::factory()->for($c)->create(['position' => 1]);
    $lessons = [];
    for ($i = 1; $i <= $n; $i++) {
        $lessons[] = Lesson::factory()->for($c)->for($ch)->create(['position' => $i, 'duration_seconds' => 10]);
    }
    $enrollment = Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $c->id] + $enroll);
    foreach (array_slice($lessons, 0, $done) as $k => $l) {
        vvMcProgress($student, $l, 'completed', now()->subMinutes(100 - $k));
    }

    return ['course' => $c, 'chapter' => $ch, 'lessons' => $lessons, 'enrollment' => $enrollment];
}

function vvMcProgress(User $student, Lesson $lesson, string $status, $at): void
{
    DB::table('lesson_progress')->insert([
        'user_id' => $student->id, 'lesson_id' => $lesson->id, 'course_id' => $lesson->course_id,
        'watched_seconds' => 5, 'last_position_seconds' => 5, 'status' => $status, 'last_accessed_at' => $at,
        'completed_at' => $status === 'completed' ? $at : null, 'created_at' => now(), 'updated_at' => now(),
    ]);

}

test('AC3: chua co khoa nao -> danh sach rong', function () {
    vvActAsStudent(User::factory()->create());

    vvMcGet()->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)
        ->assertJsonPath('pending', [])->assertJsonPath('rejected', [])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('can dang nhap hoc sinh', function () {
    test()->getJson(vvApiUrl('/me/courses'), vvWebHeaders())->assertUnauthorized();
});

test('AC1/AC2: 6/20 = 30%, 100% danh dau hoan thanh, khoa 0 bai khong chia cho 0', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 20, 6);
    $b = vvMcCourse($s, 3, 3);
    $c = vvMcCourse($s, 0);

    $res = vvMcGet()->assertOk();
    $by = collect($res->json('data'))->keyBy('course.id');

    expect($by[$a['course']->id]['progress'])->toMatchArray(['percent' => 30, 'completed_lessons' => 6, 'total_lessons' => 20, 'is_completed' => false, 'has_content' => true])
        ->and($by[$b['course']->id]['progress'])->toMatchArray(['percent' => 100, 'is_completed' => true])
        ->and($by[$c['course']->id]['progress'])->toMatchArray(['percent' => 0, 'total_lessons' => 0, 'is_completed' => false, 'has_content' => false])
        ->and($by[$c['course']->id]['resume_lesson_id'])->toBeNull();
});

test('AC4: sap xep hoc gan nhat len dau; chua hoc xep sau', function () {
    $s = vvActAsStudent(User::factory()->create());
    $old = vvMcCourse($s, 2, 0, ['last_accessed_at' => now()->subDays(3)]);
    $new = vvMcCourse($s, 2, 0, ['last_accessed_at' => now()->subMinute()]);
    $never = vvMcCourse($s, 2, 0, ['last_accessed_at' => null]);

    $ids = array_map(fn ($i) => $i['course']['id'], vvMcGet()->json('data'));

    expect($ids)->toBe([$new['course']->id, $old['course']->id, $never['course']->id]);
});

test('BR3/BR4: chi active; revoked an; khoa go xuat ban van hien kem is_published=false; pending/rejected tra rieng', function () {
    $s = vvActAsStudent(User::factory()->create());
    $active = vvMcCourse($s, 2);
    $unpub = vvMcCourse($s, 2, 0, [], []);
    $unpub['course']->forceFill(['status' => CourseStatus::Unpublished])->save();
    $revoked = vvMcCourse($s, 2, 0, ['status' => EnrollmentStatus::Revoked, 'revoked_at' => now()]);

    $pendingCourse = Course::factory()->published()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $s->id, 'course_id' => $pendingCourse->id]);
    $rejCourse = Course::factory()->published()->create();
    Enrollment::factory()->rejected('Chua du dieu kien')->create(['user_id' => $s->id, 'course_id' => $rejCourse->id]);
    // Bi tu choi roi xin lai (dang cho) -> khong con trong rejected.
    $reapply = Course::factory()->published()->create();
    Enrollment::factory()->rejected()->create(['user_id' => $s->id, 'course_id' => $reapply->id]);
    Enrollment::factory()->pendingApproval()->create(['user_id' => $s->id, 'course_id' => $reapply->id]);

    $res = vvMcGet()->assertOk();
    $by = collect($res->json('data'))->keyBy('course.id');

    expect($by->keys()->sort()->values()->all())->toBe(collect([$active['course']->id, $unpub['course']->id])->sort()->values()->all())
        ->and($by[$unpub['course']->id]['course']['is_published'])->toBeFalse()
        ->and($by[$active['course']->id]['course']['is_published'])->toBeTrue()
        ->and(collect($res->json('pending'))->pluck('course.id')->sort()->values()->all())->toBe(collect([$pendingCourse->id, $reapply->id])->sort()->values()->all())
        ->and(collect($res->json('rejected'))->pluck('course.id')->all())->toBe([$rejCourse->id])
        ->and($res->json('rejected.0.rejection_reason'))->toBe('Chua du dieu kien');
});

test('chi thay khoa cua chinh minh', function () {
    $other = User::factory()->create();
    $s = vvActAsStudent(User::factory()->create());
    vvMcCourse($other, 2);
    $mine = vvMcCourse($s, 2);

    expect(array_map(fn ($i) => $i['course']['id'], vvMcGet()->json('data')))->toBe([$mine['course']->id]);
});

test('bai hoc tiep (AC6) va diem quiz tot nhat theo khoa', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 3);
    vvMcProgress($s, $a['lessons'][0], 'completed', now()->subHour());
    $quiz = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $quiz->id, 'position' => 1]);
    QuizAttempt::factory()->submitted(1, 4.5)->create(['user_id' => $s->id, 'quiz_id' => $quiz->id]);
    QuizAttempt::factory()->submitted(1, 8.25)->create(['user_id' => $s->id, 'quiz_id' => $quiz->id]);
    QuizAttempt::factory()->create(['user_id' => $s->id, 'quiz_id' => $quiz->id]); // dang lam, khong tinh

    $b = vvMcCourse($s, 2);

    $by = collect(vvMcGet()->json('data'))->keyBy('course.id');

    expect($by[$a['course']->id]['resume_lesson_id'])->toBe($a['lessons'][1]->id)
        ->and($by[$a['course']->id]['best_quiz_score'])->toBe(8.25)
        ->and($by[$b['course']->id]['resume_lesson_id'])->toBe($b['lessons'][0]->id)
        ->and($by[$b['course']->id]['best_quiz_score'])->toBeNull();
});

test('phan trang: per_page, page, meta; tham so sai -> 422', function () {
    $s = vvActAsStudent(User::factory()->create());
    for ($i = 0; $i < 5; $i++) {
        vvMcCourse($s, 1, 0, ['last_accessed_at' => now()->subMinutes($i)]);
    }

    vvMcGet('/me/courses?per_page=2&page=3')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 2);
    vvMcGet('/me/courses?per_page=2&page=9')->assertOk()->assertJsonCount(0, 'data');
    vvMcGet('/me/courses?per_page=1000')->assertStatus(422);
    vvMcGet('/me/courses?page=0')->assertStatus(422);
    vvMcGet('/me/courses?per_page=abc')->assertStatus(422);
});

test('khong N+1: so truy van khong doi khi tang so khoa', function () {
    $s = vvActAsStudent(User::factory()->create());
    vvMcCourse($s, 2, 1);
    vvMcGet()->assertOk(); // làm nóng

    DB::enableQueryLog();
    vvMcGet()->assertOk();
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    for ($i = 0; $i < 8; $i++) {
        $x = vvMcCourse($s, 3, 1);
        $q = Quiz::factory()->for($x['course'])->create(['chapter_id' => $x['chapter']->id, 'position' => 1]);
        QuizAttempt::factory()->submitted(0, 3)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);
    }
    DB::flushQueryLog();
    vvMcGet('/me/courses?per_page=30')->assertOk()->assertJsonCount(9, 'data');
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few);
});

test('log thoi gian xu ly vao channel learning', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 2);

    $channel = Mockery::mock();
    $channel->shouldReceive('log')->once()->withArgs(function ($level, $msg, $ctx) use ($s) {
        return in_array($level, ['info', 'warning'], true) && $msg === 'me.courses'
            && $ctx['user_id'] === $s->id && is_int($ctx['duration_ms']) && $ctx['count'] === 1 && array_key_exists('slow', $ctx);
    });
    Log::shouldReceive('channel')->with('learning')->andReturn($channel);

    vvMcGet()->assertOk();
});

test('progress: bai theo chuong, quiz kem diem cao nhat, chua lam -> null', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 4, 2);
    $q1 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1, 'title' => 'Quiz 1']);
    $q2 = Quiz::factory()->for($a['course'])->create(['chapter_id' => null, 'lesson_id' => $a['lessons'][1]->id, 'position' => 2, 'title' => 'Quiz 2']);
    QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $q1->id, 'position' => 1, 'explanation' => 'BI-MAT']);
    QuizAttempt::factory()->submitted(1, 6)->create(['user_id' => $s->id, 'quiz_id' => $q1->id]);
    QuizAttempt::factory()->submitted(1, 9.5)->create(['user_id' => $s->id, 'quiz_id' => $q1->id]);

    $res = vvMcGet("/me/courses/{$a['course']->id}/progress")->assertOk()
        ->assertJsonPath('progress.percent', 50)
        ->assertJsonPath('progress.completed_lessons', 2)
        ->assertJsonPath('progress.total_lessons', 4)
        ->assertJsonPath('progress.is_completed', false)
        ->assertJsonPath('resume_lesson_id', $a['lessons'][2]->id)
        ->assertJsonPath('chapters.0.completed_lessons', 2)
        ->assertJsonPath('chapters.0.lessons.0.status', 'completed')
        ->assertJsonPath('chapters.0.lessons.3.status', 'not_started')
        ->assertJsonPath('quizzes.0.best_score', 9.5)
        ->assertJsonPath('quizzes.0.attempts_count', 2)
        ->assertJsonPath('quizzes.0.attempted', true)
        ->assertJsonPath('quizzes.1.best_score', null)
        ->assertJsonPath('quizzes.1.attempted', false);

    expect(json_encode($res->json()))->not->toContain('BI-MAT')->not->toContain('is_correct');
});

test('progress: quyen — chua so huu 403 COURSE_NOT_OWNED, khoa nhap 404, pending 403, khong co 404; khoa go xuat ban cua chu van xem duoc', function () {
    $s = vvActAsStudent(User::factory()->create());
    $notMine = Course::factory()->published()->create();
    vvMcGet("/me/courses/{$notMine->id}/progress")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');

    $draft = Course::factory()->create(['status' => CourseStatus::Draft]);
    vvMcGet("/me/courses/{$draft->id}/progress")->assertNotFound();
    vvMcGet('/me/courses/999999/progress')->assertNotFound();

    $pending = Course::factory()->published()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $s->id, 'course_id' => $pending->id]);
    vvMcGet("/me/courses/{$pending->id}/progress")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');

    $mine = vvMcCourse($s, 2);
    $mine['course']->forceFill(['status' => CourseStatus::Unpublished])->save();
    vvMcGet("/me/courses/{$mine['course']->id}/progress")->assertOk()->assertJsonPath('course.is_published', false);

    // Bi thu hoi -> mat quyen.
    Enrollment::query()->whereKey($mine['enrollment']->id)->update(['status' => EnrollmentStatus::Revoked->value]);
    vvMcGet("/me/courses/{$mine['course']->id}/progress")->assertNotFound();
    $mine['course']->forceFill(['status' => CourseStatus::Published])->save();
    vvMcGet("/me/courses/{$mine['course']->id}/progress")->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
});

test('progress: bai da xoa mem khong tinh; khoa 0 bai -> 0%', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 4, 2);
    $a['lessons'][0]->delete();

    vvMcGet("/me/courses/{$a['course']->id}/progress")->assertOk()
        ->assertJsonPath('progress.total_lessons', 3)->assertJsonPath('progress.completed_lessons', 1)->assertJsonPath('progress.percent', 33);

    $e = vvMcCourse($s, 0);
    vvMcGet("/me/courses/{$e['course']->id}/progress")->assertOk()
        ->assertJsonPath('progress.percent', 0)->assertJsonPath('progress.has_content', false)->assertJsonPath('progress.is_completed', false)
        ->assertJsonPath('chapters.0.total_lessons', 0)->assertJsonPath('resume_lesson_id', null);
});

test('khop voi heartbeat: hoan thanh bai that -> danh sach cap nhat', function () {
    $s = vvLearnSet(duration: 10);
    vvHeartbeat($s['lesson'], 10, 10)->assertOk()->assertJsonPath('completed', true);

    vvMcGet()->assertOk()->assertJsonPath('data.0.progress.percent', 100)->assertJsonPath('data.0.progress.is_completed', true);
});

test('R1: quiz bi xoa mem -> diem khong con trong best_quiz_score (khop trang chi tiet)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 2);
    $quiz = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    QuizAttempt::factory()->submitted(1, 9.5)->create(['user_id' => $s->id, 'quiz_id' => $quiz->id]);

    vvMcGet()->assertJsonPath('data.0.best_quiz_score', 9.5);

    $quiz->delete();

    vvMcGet()->assertOk()->assertJsonPath('data.0.best_quiz_score', null);
    vvMcGet("/me/courses/{$a['course']->id}/progress")->assertOk()->assertJsonPath('quizzes', []);
});

test('R2: khoa xoa mem an khoi data/pending/rejected, progress 404', function () {
    $s = vvActAsStudent(User::factory()->create());
    $active = vvMcCourse($s, 2);
    $pending = Course::factory()->published()->create();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $s->id, 'course_id' => $pending->id]);
    $rejected = Course::factory()->published()->create();
    Enrollment::factory()->rejected()->create(['user_id' => $s->id, 'course_id' => $rejected->id]);

    vvMcGet()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'pending')->assertJsonCount(1, 'rejected');

    $active['course']->delete();
    $pending->delete();
    $rejected->delete();

    vvMcGet()->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', [])
        ->assertJsonPath('pending', [])->assertJsonPath('rejected', []);
    vvMcGet("/me/courses/{$active['course']->id}/progress")->assertNotFound();
});

test('R2: giao vien / admin goi 2 route -> 403', function () {
    $course = Course::factory()->published()->create();

    foreach ([User::factory()->teacher()->create(), User::factory()->admin()->create()] as $user) {
        vvActAsStudent($user);
        vvMcGet()->assertForbidden();
        vvMcGet("/me/courses/{$course->id}/progress")->assertForbidden();
    }
});

test('R2: throttle me-courses 60/phut -> 429', function () {
    vvActAsStudent(User::factory()->create());

    for ($i = 0; $i < 60; $i++) {
        vvMcGet()->assertOk();
    }
    vvMcGet()->assertStatus(429);
});

test('R2: bai/chuong xoa mem khong tinh o danh sach (total, percent, resume)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvMcCourse($s, 4, 2);
    $ch2 = Chapter::factory()->for($a['course'])->create(['position' => 2]);
    $l5 = Lesson::factory()->for($a['course'])->for($ch2)->create(['position' => 1]);
    $a['lessons'][0]->delete(); // bai da xong bi xoa
    $a['lessons'][2]->delete(); // bai chua hoc bi xoa
    $l5->delete();
    $ch2->delete();

    vvMcGet()->assertOk()
        ->assertJsonPath('data.0.progress.total_lessons', 2)
        ->assertJsonPath('data.0.progress.completed_lessons', 1)
        ->assertJsonPath('data.0.progress.percent', 50)
        ->assertJsonPath('data.0.resume_lesson_id', $a['lessons'][3]->id);
});
