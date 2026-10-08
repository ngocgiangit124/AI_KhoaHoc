#!/usr/bin/env bash
# Dữ liệu cho e2e/lam-quiz-real.spec.ts (FW5) — idempotent, chạy trên máy host, cần Docker local (infra) đang chạy:
#   frontend/apps/web/e2e/seed-e2e-quiz.sh           # tạo (hoặc tạo lại) dữ liệu tiền tố "e2e-fw5-"
#   frontend/apps/web/e2e/seed-e2e-quiz.sh --reset   # như trên (xoá rồi tạo lại)
#   frontend/apps/web/e2e/seed-e2e-quiz.sh --clean   # dọn sạch (khóa, quiz, lượt làm, học sinh fw5-*)
# Chỉ chạy ở môi trường local/testing (tinker kiểm tra app()->environment).
# Học sinh (mật khẩu matkhau-123, đã xác thực): fw5-hs-own@example.com (đã ghi danh), fw5-hs-none@example.com (chưa ghi danh),
# fw5-hs-other@example.com (đã ghi danh, dùng để thử xem lượt của người khác).
# Khóa "e2e-fw5-quiz": 1 chương, bài 1 (không video), và các quiz:
#   q1 "Công thức Toán" (lesson 1, không giới hạn giờ, 4 câu: phân số, căn, công thức riêng dòng, công thức rất dài + chữ có "<b>")
#      đáp án đúng theo vị trí: câu1=B câu2=A câu3=C câu4=D
#   q2 "Có giờ" (lesson 1, 1 phút, 2 câu)         đáp án đúng: A, B
#   q3 "Chưa có câu" (lesson 1)                    -> 422 QUIZ_NOT_READY
#   q4 "Quiz của chương" (chương 1, không giờ, 2 câu)
# In ra: course=<id> l1=<id> q1=<id> q2=<id> q3=<id> q4=<id>
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

CLEAN="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Enrollment,Quiz,QuizQuestion};
use Illuminate\Support\Facades\DB;
foreach (Course::withTrashed()->where("slug","like","e2e-fw5-%")->get() as $c) {
  $qids = Quiz::withTrashed()->where("course_id",$c->id)->pluck("id");
  DB::table("quiz_attempts")->whereIn("quiz_id",$qids)->delete();
  $questionIds = QuizQuestion::withTrashed()->whereIn("quiz_id",$qids)->pluck("id");
  DB::table("quiz_options")->whereIn("question_id",$questionIds)->delete();
  DB::table("quiz_questions")->whereIn("quiz_id",$qids)->delete();
  DB::table("quizzes")->whereIn("id",$qids)->delete();
  Enrollment::where("course_id",$c->id)->delete();
  foreach ($c->lessons()->withTrashed()->get() as $l) { DB::table("lesson_progress")->where("lesson_id",$l->id)->delete(); $l->forceDelete(); }
  foreach ($c->chapters()->withTrashed()->get() as $ch) { $ch->forceDelete(); }
  $c->forceDelete();
}
foreach (User::where("email","like","fw5-%@example.com")->get() as $u) {
  DB::table("quiz_attempts")->where("user_id",$u->id)->delete();
  Enrollment::where("user_id",$u->id)->delete();
  $u->delete();
}
echo "clean ok";
PHP
)"
docker compose exec -T php php artisan tinker --execute="$CLEAN"
echo
if [ "${1:-}" = "--clean" ]; then exit 0; fi

SEED="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,Chapter,Lesson,User,Enrollment,Quiz,QuizQuestion,QuizOption};
use Illuminate\Support\Facades\Hash;
$gv = User::factory()->teacher()->verified()->create(["name"=>"E2E FW5 GV","email"=>"fw5-gv@example.com"]);
$c = Course::factory()->published()->create(["title"=>"E2E FW5 Làm quiz","slug"=>"e2e-fw5-quiz","grade_level"=>9,"price"=>0,"created_by"=>$gv->id,"enrollments_count"=>0]);
$ch = Chapter::factory()->create(["course_id"=>$c->id,"title"=>"Chương 1","position"=>1]);
$l1 = Lesson::factory()->external()->create(["chapter_id"=>$ch->id,"course_id"=>$c->id,"title"=>"Bài 1 có bài tập","position"=>1,"duration_seconds"=>60]);
$l2 = Lesson::factory()->external()->create(["chapter_id"=>$ch->id,"course_id"=>$c->id,"title"=>"Bài 2","position"=>2,"duration_seconds"=>60]);

function mkQuiz($c, $l, $ch, $title, $limit, $pos) {
  $q = Quiz::factory()->forLesson($l ?? Lesson::factory()->create())->create(["title"=>$title,"time_limit_minutes"=>$limit,"position"=>$pos]);
  return $q;
}
function addQuestions($quiz, array $items) {
  foreach ($items as $i => $it) {
    $q = new QuizQuestion(["content"=>$it[0],"explanation"=>$it[1]]);
    $q->quiz_id = $quiz->id; $q->position = $i + 1; $q->save();
    foreach ($it[2] as $k => $text) {
      $o = new QuizOption(["content"=>$text,"is_correct"=>($k + 1) === $it[3],"position"=>$k + 1]);
      $o->question_id = $q->id; $o->save();
    }
  }
}
$q1 = mkQuiz($c, $l1, $ch, "Công thức Toán", null, 1);
$long = '\sum_{k=1}^{20} k = 1+2+3+4+5+6+7+8+9+10+11+12+13+14+15+16+17+18+19+20 = \dfrac{20 \cdot 21}{2} = 210';
addQuestions($q1, [
  ['Tính $\dfrac{1}{2}+\dfrac{1}{3}$ rồi rút gọn.', 'Quy đồng: $\dfrac{3}{6}+\dfrac{2}{6}=\dfrac{5}{6}$.', ['$\dfrac{2}{5}$','$\dfrac{5}{6}$','$\dfrac{1}{6}$','$1$'], 2],
  ['Rút gọn $\sqrt{18}$.', null, ['$3\sqrt{2}$','$2\sqrt{3}$','$9$','$\sqrt{9}$'], 1],
  ['Nghiệm của phương trình $$x^2-5x+6=0$$ là', 'Có $\Delta = 1$ nên $x_1=3$, $x_2=2$.', ['$x=1$ hoặc $x=6$','$x=-2$ hoặc $x=-3$','$x=2$ hoặc $x=3$','Vô nghiệm'], 3],
  ['Tính tổng: $$'.$long.'$$ Chữ <b>không</b> phải thẻ HTML.', 'Tổng $1+\cdots+20=210$.', ['$20$','$100$','$200$','$210$'], 4],
]);
$q2 = mkQuiz($c, $l1, $ch, "Có giờ", 1, 2);
addQuestions($q2, [
  ['Câu một: $2+2=$', null, ['$4$','$5$','$6$','$7$'], 1],
  ['Câu hai: $3\times 3=$', null, ['$6$','$9$','$12$','$3$'], 2],
]);
$q3 = mkQuiz($c, $l1, $ch, "Chưa có câu", null, 3);
$q4 = Quiz::factory()->create(["chapter_id"=>$ch->id,"lesson_id"=>null,"course_id"=>$c->id,"title"=>"Quiz của chương","time_limit_minutes"=>null,"position"=>4]);
addQuestions($q4, [
  ['Căn bậc hai của $16$ là', null, ['$4$','$8$','$2$','$256$'], 1],
  ['$5^2=$', null, ['$10$','$25$','$7$','$52$'], 2],
]);
$pw = Hash::make("matkhau-123");
foreach (["own"=>"Sở hữu","other"=>"Khác"] as $k => $name) {
  $u = User::factory()->student()->verified()->create(["name"=>"FW5 HS $name","email"=>"fw5-hs-$k@example.com","password"=>$pw,"grade_level"=>9]);
  Enrollment::factory()->create(["user_id"=>$u->id,"course_id"=>$c->id]);
}
User::factory()->student()->verified()->create(["name"=>"FW5 HS Chưa ghi danh","email"=>"fw5-hs-none@example.com","password"=>$pw,"grade_level"=>9]);
echo "SEED course=$c->id l1=$l1->id q1=$q1->id q2=$q2->id q3=$q3->id q4=$q4->id";
PHP
)"
OUT="$(docker compose exec -T php php artisan tinker --execute="$SEED")"
echo "$OUT" | grep -o 'course=.*' || { echo "$OUT"; exit 1; }
