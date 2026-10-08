#!/usr/bin/env bash
# Dữ liệu cho e2e/khoa-hoc-cua-toi-real.spec.ts (FW6) — idempotent, chạy trên máy host, cần Docker local (infra) đang chạy:
#   frontend/apps/web/e2e/seed-e2e-progress.sh           # tạo (hoặc tạo lại) dữ liệu tiền tố "e2e-fw6-"
#   frontend/apps/web/e2e/seed-e2e-progress.sh --reset   # như trên (xoá rồi tạo lại)
#   frontend/apps/web/e2e/seed-e2e-progress.sh --clean   # dọn sạch (khóa, quiz, lượt làm, tiến độ, học sinh fw6-*)
# Chỉ chạy ở môi trường local/testing (tinker kiểm tra app()->environment). KHÔNG chạy migrate/seed của Laravel.
# Học sinh (mật khẩu matkhau-123, đã xác thực):
#   fw6-hs-own@example.com   khóa "e2e-fw6-tiendo" đang học (2/4 bài xong = 50%, bài tiếp theo = l3), 1 khóa chờ duyệt, 1 khóa bị từ chối
#   fw6-hs-none@example.com  chưa ghi danh khóa nào (trạng thái rỗng + 403 COURSE_NOT_OWNED khi vào tiến độ khóa của người khác)
#   fw6-hs-many@example.com  13 khóa đang học (phân trang 12/trang)
# Khóa tiến độ: 2 chương x 2 bài (link ngoài); quiz qa (gắn bài 1; 2 lượt đã nộp: 5 và 8,33 -> cao nhất 8,33), qb (gắn chương 2, chưa làm).
# In ra: course=<id> l1=<id> l2=<id> l3=<id> l4=<id> qa=<id> qb=<id> pend=<slug> rej=<slug>
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

CLEAN="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Enrollment,Quiz,QuizQuestion};
use Illuminate\Support\Facades\DB;
foreach (Course::withTrashed()->where("slug","like","e2e-fw6-%")->get() as $c) {
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
foreach (User::where("email","like","fw6-%@example.com")->get() as $u) {
  DB::table("quiz_attempts")->where("user_id",$u->id)->delete();
  DB::table("lesson_progress")->where("user_id",$u->id)->delete();
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
use App\Models\{Course,Chapter,Lesson,User,Enrollment,Quiz,QuizQuestion,QuizOption,QuizAttempt,LessonProgress};
use Illuminate\Support\Facades\{DB,Hash};
$gv = User::factory()->teacher()->verified()->create(["name"=>"E2E FW6 GV","email"=>"fw6-gv@example.com"]);
$mk = fn ($slug, $title) => Course::factory()->published()->create(["title"=>$title,"slug"=>$slug,"grade_level"=>9,"price"=>0,"created_by"=>$gv->id,"enrollments_count"=>0]);
$c = $mk("e2e-fw6-tiendo", "E2E FW6 Tiến độ");
$ch1 = Chapter::factory()->create(["course_id"=>$c->id,"title"=>"Chương 1","position"=>1]);
$ch2 = Chapter::factory()->create(["course_id"=>$c->id,"title"=>"Chương 2","position"=>2]);
$ls = [];
foreach ([[$ch1,1,"Bài 1"],[$ch1,2,"Bài 2"],[$ch2,1,"Bài 3"],[$ch2,2,"Bài 4"]] as [$ch,$pos,$t]) {
  $ls[] = Lesson::factory()->external()->create(["chapter_id"=>$ch->id,"course_id"=>$c->id,"title"=>$t,"position"=>$pos,"duration_seconds"=>600]);
}
[$l1,$l2,$l3,$l4] = $ls;

function addQuestions($quiz, int $n) {
  $ids = [];
  for ($i = 1; $i <= $n; $i++) {
    $q = new QuizQuestion(["content"=>"Câu số $i","explanation"=>null]);
    $q->quiz_id = $quiz->id; $q->position = $i; $q->save(); $ids[] = $q->id;
    foreach (["A","B","C","D"] as $k => $text) {
      $o = new QuizOption(["content"=>$text,"is_correct"=>$k === 0,"position"=>$k + 1]);
      $o->question_id = $q->id; $o->save();
    }
  }
  return $ids;
}
$qa = Quiz::factory()->forLesson($l1)->create(["title"=>"Trắc nghiệm bài 1","time_limit_minutes"=>null,"position"=>1]);
$qaIds = addQuestions($qa, 2);
$qb = Quiz::factory()->create(["chapter_id"=>$ch2->id,"lesson_id"=>null,"course_id"=>$c->id,"title"=>"Đề chương 2","time_limit_minutes"=>null,"position"=>2]);
addQuestions($qb, 2);

$pw = Hash::make("matkhau-123");
$own = User::factory()->student()->verified()->create(["name"=>"FW6 HS Sở hữu","email"=>"fw6-hs-own@example.com","password"=>$pw,"grade_level"=>9]);
User::factory()->student()->verified()->create(["name"=>"FW6 HS Trống","email"=>"fw6-hs-none@example.com","password"=>$pw,"grade_level"=>9]);
$many = User::factory()->student()->verified()->create(["name"=>"FW6 HS Nhiều","email"=>"fw6-hs-many@example.com","password"=>$pw,"grade_level"=>9]);

$e = Enrollment::factory()->create(["user_id"=>$own->id,"course_id"=>$c->id]);
DB::table("enrollments")->where("id",$e->id)->update(["last_accessed_at"=>now()]);
foreach ([$l1,$l2] as $i => $l) {
  LessonProgress::factory()->completed()->create(["user_id"=>$own->id,"lesson_id"=>$l->id,"course_id"=>$c->id,"last_accessed_at"=>now()->subMinutes(30 - $i)]);
}
LessonProgress::factory()->create(["user_id"=>$own->id,"lesson_id"=>$l3->id,"course_id"=>$c->id,"watched_seconds"=>120,"last_position_seconds"=>120,"last_accessed_at"=>now()]);
QuizAttempt::factory()->submitted(1, 5.0)->create(["user_id"=>$own->id,"quiz_id"=>$qa->id,"course_id"=>$c->id,"question_ids"=>$qaIds,"started_at"=>now()->subHours(2),"submitted_at"=>now()->subHours(2)]);
QuizAttempt::factory()->submitted(2, 8.33)->create(["user_id"=>$own->id,"quiz_id"=>$qa->id,"course_id"=>$c->id,"question_ids"=>$qaIds,"started_at"=>now()->subHour(),"submitted_at"=>now()->subHour()]);

$pend = $mk("e2e-fw6-cho-duyet", "E2E FW6 Chờ duyệt");
Enrollment::factory()->pendingApproval()->create(["user_id"=>$own->id,"course_id"=>$pend->id]);
$rej = $mk("e2e-fw6-bi-tu-choi", "E2E FW6 Bị từ chối");
Enrollment::factory()->rejected("Khóa dành cho lớp chọn của trường.")->create(["user_id"=>$own->id,"course_id"=>$rej->id]);

for ($i = 1; $i <= 13; $i++) {
  $m = $mk("e2e-fw6-nhieu-$i", "E2E FW6 Nhiều $i");
  $ch = Chapter::factory()->create(["course_id"=>$m->id,"title"=>"Chương","position"=>1]);
  Lesson::factory()->external()->create(["chapter_id"=>$ch->id,"course_id"=>$m->id,"title"=>"Bài","position"=>1,"duration_seconds"=>60]);
  $me = Enrollment::factory()->create(["user_id"=>$many->id,"course_id"=>$m->id]);
  DB::table("enrollments")->where("id",$me->id)->update(["last_accessed_at"=>now()->subMinutes($i)]);
}
echo "SEED course=$c->id l1=$l1->id l2=$l2->id l3=$l3->id l4=$l4->id qa=$qa->id qb=$qb->id pend=$pend->slug rej=$rej->slug";
PHP
)"
OUT="$(docker compose exec -T php php artisan tinker --execute="$SEED")"
echo "$OUT" | grep -o 'course=.*' || { echo "$OUT"; exit 1; }
