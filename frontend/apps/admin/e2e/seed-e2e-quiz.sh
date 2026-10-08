#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/soan-quiz-real.spec.ts (FA5; idempotent, chạy trên máy host, cần Docker local đang chạy; CHỈ local/testing):
#   frontend/apps/admin/e2e/seed-e2e-quiz.sh          # tạo tài khoản + khóa "E2E FA5 ..." (đã có thì giữ nguyên)
#   frontend/apps/admin/e2e/seed-e2e-quiz.sh --reset  # xoá rồi tạo lại đúng dữ liệu ban đầu (bỏ các quiz/câu thêm trong lúc chạy spec)
#   frontend/apps/admin/e2e/seed-e2e-quiz.sh --clean  # xoá MỌI thứ "E2E FA5" (khóa, chương, bài, quiz, lượt làm, tài khoản)
# Tài khoản (mật khẩu `Password123!`): e2e-fa5-gv (giáo viên được gán), e2e-fa5-gv2 (giáo viên KHÔNG được gán),
# e2e-fa5-qlt1..2 (quản lý trang; MFA bị giới hạn OTP nên có 2 tài khoản), e2e-fa5-hs@example.com (học sinh có lượt làm).
# Khóa "E2E FA5 Khóa soạn quiz" (gv): Chương 1 (Bài 1, Bài 2), Chương 2 (Bài 3), và các quiz:
#   "E2E FA5 Quiz trống" (Bài 1, chưa có câu) · "E2E FA5 Quiz có lượt làm" (Chương 1, 2 câu; câu 1 đã có lượt nộp -> copy-on-write)
#   "E2E FA5 Quiz gần đầy" (Chương 2, 199 câu -> thêm câu 200 rồi chạm trần)
# Khóa "E2E FA5 Của GV khác" (gv2) có 1 quiz, dùng thử 403.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

WIPE='
use App\Models\{Course, Lesson, Chapter, Quiz, QuizQuestion, User};
use Illuminate\Support\Facades\DB;
$wipe = function () {
  $ids = Course::withTrashed()->where("title", "like", "E2E FA5 %")->pluck("id");
  $qids = Quiz::withTrashed()->whereIn("course_id", $ids)->pluck("id");
  DB::transaction(function () use ($ids, $qids) {
    DB::table("quiz_attempts")->whereIn("course_id", $ids)->orWhereIn("quiz_id", $qids)->delete();
    DB::table("lesson_progress")->whereIn("lesson_id", Lesson::withTrashed()->whereIn("course_id", $ids)->pluck("id"))->delete();
    DB::table("enrollments")->whereIn("course_id", $ids)->delete();
    $questionIds = QuizQuestion::withTrashed()->whereIn("quiz_id", $qids)->pluck("id");
    DB::table("quiz_options")->whereIn("question_id", $questionIds)->delete();
    DB::table("quiz_questions")->whereIn("quiz_id", $qids)->delete();
    DB::table("quizzes")->whereIn("id", $qids)->delete();
    Lesson::withTrashed()->whereIn("course_id", $ids)->forceDelete();
    Chapter::withTrashed()->whereIn("course_id", $ids)->forceDelete();
    DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
    DB::table("course_subject")->whereIn("course_id", $ids)->delete();
    Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  });
  return $ids->count();
};
'

SEED='
use App\Models\{Course, Subject, User, Chapter, Lesson, Quiz, QuizQuestion, QuizOption, QuizAttempt};
use Illuminate\Support\Facades\{DB, Hash};
$mkUser = function ($email, $name, $kind) {
  $u = User::where("email", $email)->first();
  if ($u) return $u;
  $f = User::factory();
  $f = $kind === "teacher" ? $f->teacher() : ($kind === "student" ? $f->student()->verified() : $f->pageManager());
  return $f->create(["email" => $email, "name" => $name, "password" => Hash::make("Password123!")]);
};
$gv = $mkUser("e2e-fa5-gv@example.com", "E2E FA5 GV Chinh", "teacher");
$gv2 = $mkUser("e2e-fa5-gv2@example.com", "E2E FA5 GV Hai", "teacher");
$mkUser("e2e-fa5-qlt1@example.com", "E2E FA5 QLT 1", "qlt");
$mkUser("e2e-fa5-qlt2@example.com", "E2E FA5 QLT 2", "qlt");
$hs = $mkUser("e2e-fa5-hs@example.com", "E2E FA5 HS", "student");
$subject = Subject::where("name", "E2E FA5 Chuyên đề")->first() ?? Subject::factory()->create(["name" => "E2E FA5 Chuyên đề", "slug" => "e2e-fa5-chuyen-de", "status" => "active"]);
$course = function ($title, $teacher) use ($subject) {
  $c = Course::factory()->create(["title" => $title, "grade_level" => 9, "price" => 100000, "created_by" => $teacher->id]);
  $c->teachers()->attach([$teacher->id]); $c->subjects()->attach([$subject->id]);
  return $c;
};
$chapter = fn ($c, $t, $pos) => Chapter::factory()->create(["course_id" => $c->id, "title" => $t, "position" => $pos]);
$lesson = fn ($ch, $t, $pos) => Lesson::factory()->create(["chapter_id" => $ch->id, "title" => $t, "position" => $pos]);
$addQuestion = function ($quiz, $pos, $content, $options, $correct, $explanation = null) {
  $q = new QuizQuestion(["content" => $content, "explanation" => $explanation]);
  $q->quiz_id = $quiz->id; $q->position = $pos; $q->save();
  foreach ($options as $k => $text) {
    $o = new QuizOption(["content" => $text, "is_correct" => $k === $correct, "position" => $k + 1]);
    $o->question_id = $q->id; $o->save();
  }
  return $q;
};

$c1 = $course("E2E FA5 Khóa soạn quiz", $gv);
$ch1 = $chapter($c1, "E2E FA5 Chương 1", 1); $ch2 = $chapter($c1, "E2E FA5 Chương 2", 2);
$l1 = $lesson($ch1, "E2E FA5 Bài 1", 1); $lesson($ch1, "E2E FA5 Bài 2", 2); $lesson($ch2, "E2E FA5 Bài 3", 1);

Quiz::factory()->forLesson($l1)->create(["title" => "E2E FA5 Quiz trống", "time_limit_minutes" => null, "position" => 1]);

$qa = Quiz::factory()->create(["chapter_id" => $ch1->id, "lesson_id" => null, "course_id" => $c1->id, "title" => "E2E FA5 Quiz có lượt làm", "time_limit_minutes" => 15, "position" => 2]);
$q1 = $addQuestion($qa, 1, "Câu cũ: tính \$1+1\$", ["\$1\$", "\$2\$", "\$3\$", "\$4\$"], 1, "Vì \$1+1=2\$.");
$addQuestion($qa, 2, "Câu chưa ai làm: \$2+2\$", ["\$3\$", "\$4\$", "\$5\$", "\$6\$"], 1);
QuizAttempt::factory()->submitted(1)->create(["user_id" => $hs->id, "quiz_id" => $qa->id, "course_id" => $c1->id, "question_ids" => [$q1->id]]);

$qf = Quiz::factory()->create(["chapter_id" => $ch2->id, "lesson_id" => null, "course_id" => $c1->id, "title" => "E2E FA5 Quiz gần đầy", "time_limit_minutes" => null, "position" => 3]);
$now = now()->toDateTimeString();
DB::table("quiz_questions")->insert(array_map(fn ($i) => ["quiz_id" => $qf->id, "content" => "Câu số $i", "explanation" => null, "position" => $i, "created_at" => $now, "updated_at" => $now], range(1, 199)));
$ids = DB::table("quiz_questions")->where("quiz_id", $qf->id)->pluck("id");
$rows = [];
foreach ($ids as $qid) { foreach ([1, 2, 3, 4] as $p) { $rows[] = ["question_id" => $qid, "content" => "Đáp án $p", "is_correct" => $p === 1, "position" => $p, "created_at" => $now, "updated_at" => $now]; } }
foreach (array_chunk($rows, 400) as $chunk) { DB::table("quiz_options")->insert($chunk); }

$c2 = $course("E2E FA5 Của GV khác", $gv2);
$chx = $chapter($c2, "E2E FA5 Chương X", 1);
Quiz::factory()->create(["chapter_id" => $chx->id, "lesson_id" => null, "course_id" => $c2->id, "title" => "E2E FA5 Quiz của GV khác", "position" => 1]);
echo "seed ok: ", Course::where("title", "like", "E2E FA5 %")->count(), " khoa, qa=", $qa->id, " qf=", $qf->id;
'

run() { docker compose exec -T php php artisan tinker --execute="$1"; echo; }
case "${1:-}" in
  --clean)
    run "$WIPE"'
echo "da don ", $wipe(), " khoa";
App\Models\Subject::where("name", "like", "E2E FA5 %")->delete();
App\Models\User::where("email", "like", "e2e-fa5-%")->delete();'
    ;;
  --reset)
    run "$WIPE"'$wipe();'
    run "$SEED"
    ;;
  *)
    if [ "$(docker compose exec -T php php artisan tinker --execute='echo App\Models\Course::where("title", "like", "E2E FA5 %")->count();' | tr -d '\r\n ')" = "0" ]; then run "$SEED"; else echo "da co du lieu E2E FA5 (dung --reset de dua ve ban dau)"; fi
    ;;
esac
