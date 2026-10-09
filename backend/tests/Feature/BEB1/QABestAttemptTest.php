<?php

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/BestAttemptIdTest.php';

test('QA FW6 AC1: hoa diem va hoa ca submitted_at -> id nho nhat; luot cao diem hon nop sau van thang', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvBeb1Course($s, 1);
    $q1 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    $q2 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 2]);
    $t = now()->subHour()->startOfSecond();

    $first = QuizAttempt::factory()->submitted(1, 7)->create(['user_id' => $s->id, 'quiz_id' => $q1->id, 'submitted_at' => $t]);
    $second = QuizAttempt::factory()->submitted(1, 7)->create(['user_id' => $s->id, 'quiz_id' => $q1->id, 'submitted_at' => $t]);
    expect($first->id)->toBeLessThan($second->id);

    $old = QuizAttempt::factory()->submitted(1, 5)->create(['user_id' => $s->id, 'quiz_id' => $q2->id, 'submitted_at' => now()->subDays(3)]);
    $new = QuizAttempt::factory()->submitted(1, 8)->create(['user_id' => $s->id, 'quiz_id' => $q2->id, 'submitted_at' => now()->subMinute()]);

    $res = vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();
    $res->assertJsonPath('quizzes.0.best_attempt_id', $first->id)->assertJsonPath('quizzes.1.best_attempt_id', $new->id);
    expect($old->id)->not->toBe($new->id);
});

test('QA FW6: diem 0 van la luot nop hop le; luot dang lam cung diem khong duoc chon; quiz chi co luot dang lam -> null', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvBeb1Course($s, 1);
    $q1 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    $q2 = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 2]);

    $zero = QuizAttempt::factory()->submitted(0, 0)->create(['user_id' => $s->id, 'quiz_id' => $q1->id]);
    QuizAttempt::factory()->create(['user_id' => $s->id, 'quiz_id' => $q2->id]); // đang làm

    $res = vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();
    $res->assertJsonPath('quizzes.0.best_attempt_id', $zero->id)->assertJsonPath('quizzes.1.best_attempt_id', null);
});

test('QA FW6: quiz xoa mem khong xuat hien; luot cua quiz da xoa khong lo ra', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvBeb1Course($s, 1);
    $keep = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    $gone = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 2]);
    $keepAttempt = QuizAttempt::factory()->submitted(1, 4)->create(['user_id' => $s->id, 'quiz_id' => $keep->id]);
    $goneAttempt = QuizAttempt::factory()->submitted(1, 9)->create(['user_id' => $s->id, 'quiz_id' => $gone->id]);
    $gone->delete();

    $res = vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();
    $quizzes = $res->json('quizzes');
    expect($quizzes)->toHaveCount(1)->and($quizzes[0]['best_attempt_id'])->toBe($keepAttempt->id)
        ->and(json_encode($res->json()))->not->toContain('"best_attempt_id":'.$goneAttempt->id);
});

test('QA FW6 IDOR: khong bao gio tra id luot cua hoc sinh khac (du diem cao hon, nop som hon, cung quiz)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $other = User::factory()->student()->create();
    $a = vvBeb1Course($s, 1);
    vvBeb1QaEnroll($other, $a['course']);
    $q = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    $theirs = QuizAttempt::factory()->submitted(1, 10)->create(['user_id' => $other->id, 'quiz_id' => $q->id, 'submitted_at' => now()->subDays(5)]);

    // Chính mình chưa làm -> null, dù người khác có lượt tốt hơn.
    vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk()->assertJsonPath('quizzes.0.best_attempt_id', null);

    $mine = QuizAttempt::factory()->submitted(1, 2)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);
    $res = vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk();
    $res->assertJsonPath('quizzes.0.best_attempt_id', $mine->id);
    expect($theirs->id)->not->toBe($mine->id)->and(json_encode($res->json()))->not->toContain('"best_attempt_id":'.$theirs->id);
});

test('QA FW6: luot cua khoa khac cung quiz id khong lan (loc theo course_id)', function () {
    $s = vvActAsStudent(User::factory()->create());
    $a = vvBeb1Course($s, 1);
    $q = Quiz::factory()->for($a['course'])->create(['chapter_id' => $a['chapter']->id, 'position' => 1]);
    // Lượt có course_id khác (dữ liệu lệch) không được chọn.
    $stray = QuizAttempt::factory()->submitted(1, 10)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);
    $elsewhere = Course::factory()->published()->create();
    DB::table('quiz_attempts')->where('id', $stray->id)->update(['course_id' => $elsewhere->id]);
    $ok = QuizAttempt::factory()->submitted(1, 1)->create(['user_id' => $s->id, 'quiz_id' => $q->id]);

    vvBeb1Get("/me/courses/{$a['course']->id}/progress")->assertOk()->assertJsonPath('quizzes.0.best_attempt_id', $ok->id);
});

function vvBeb1QaEnroll(User $u, $course): void
{
    Enrollment::factory()->create(['user_id' => $u->id, 'course_id' => $course->id]);
}
