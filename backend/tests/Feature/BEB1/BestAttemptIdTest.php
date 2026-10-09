<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T13/helpers.php';

function vvBeb1Get(string $path = '/me/courses')
{
    return test()->getJson(vvApiUrl($path), vvWebHeaders());
}

/** Khóa published có $n bài (1 chương), HS đã ghi danh active. */
function vvBeb1Course(User $student, int $n = 2): array
{
    $c = Course::factory()->published()->create();
    $ch = Chapter::factory()->for($c)->create(['position' => 1]);
    $lessons = [];
    for ($i = 1; $i <= $n; $i++) {
        $lessons[] = Lesson::factory()->for($c)->for($ch)->create(['position' => $i, 'duration_seconds' => 10]);
    }
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $c->id]);

    return ['course' => $c, 'chapter' => $ch, 'lessons' => $lessons];
}

test('BE-backlog-1 best_attempt_id: luot diem cao nhat cua chinh hoc sinh; hoa diem -> luot nop som nhat; chua lam -> null', function () {
    $s = vvActAsStudent(User::factory()->create());
    $other = User::factory()->student()->create();
    $a = vvBeb1Course($s, 2);
    $q1 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    $q2 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 2]);
    $q3 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 3]);
    QuizQuestion::factory()->withOptions(1)->create(['quiz_id' => $q1->id, 'position' => 1]);

    $low = QuizAttempt::factory()->submitted(1, 6)->create(['user_id' => $s->id, 'quiz_id' => $q1->id, 'submitted_at' => now()->subHours(3)]);
    $tieLate = QuizAttempt::factory()->submitted(1, 9.5)->create(['user_id' => $s->id, 'quiz_id' => $q1->id, 'submitted_at' => now()->subHour()]);
    $tieEarly = QuizAttempt::factory()->submitted(1, 9.5)->create(['user_id' => $s->id, 'quiz_id' => $q1->id, 'submitted_at' => now()->subHours(2)]);
    // Lượt đang làm (chưa nộp) và lượt của người khác (điểm cao hơn) không được chọn.
    QuizAttempt::factory()->create(['user_id' => $s->id, 'quiz_id' => $q2->id]);
    QuizAttempt::factory()->submitted(1, 10)->create(['user_id' => $other->id, 'quiz_id' => $q1->id]);
    QuizAttempt::factory()->submitted(1, 3)->create(['user_id' => $s->id, 'quiz_id' => $q3->id, 'submitted_at' => now()->subDay()]);

    $res = vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();

    expect($low->id)->not->toBe($tieEarly->id);
    $res->assertJsonPath('quizzes.0.best_attempt_id', $tieEarly->id)
        ->assertJsonPath('quizzes.0.best_score', 9.5)
        ->assertJsonPath('quizzes.1.best_attempt_id', null)
        ->assertJsonPath('quizzes.2.best_attempt_id', fn ($v) => is_int($v) && $v !== $tieLate->id);
});

test('BE-backlog-1 best_attempt_id: so truy van khong tang theo so quiz (khong N+1)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvBeb1Course($s, 2);

    $count = function () use ($a): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $q = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    QuizAttempt::factory()->submitted(1, 5)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);
    $before = $count();

    foreach (range(2, 6) as $i) {
        $q = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => $i]);
        QuizAttempt::factory()->submitted(1, 5)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);
    }

    expect($count())->toBe($before);
});
