#!/usr/bin/env bash
# Dữ liệu QA bổ sung cho FW6 (e2e/fw6-qa.spec.ts): tiền tố "e2e-fw6q-", học sinh fw6q-*@example.com (mật khẩu matkhau-123).
#   seed-qa-fw6.sh [--reset|--clean]   Chỉ tinker, không migrate/seed. Chỉ local/testing.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
CLEAN="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Enrollment};
use Illuminate\Support\Facades\DB;
foreach (Course::withTrashed()->where("slug","like","e2e-fw6q-%")->get() as $c) {
  Enrollment::where("course_id",$c->id)->delete();
  foreach ($c->lessons()->withTrashed()->get() as $l) { DB::table("lesson_progress")->where("lesson_id",$l->id)->delete(); $l->forceDelete(); }
  foreach ($c->chapters()->withTrashed()->get() as $ch) { $ch->forceDelete(); }
  $c->forceDelete();
}
foreach (User::where("email","like","fw6q-%@example.com")->get() as $u) {
  DB::table("lesson_progress")->where("user_id",$u->id)->delete();
  Enrollment::where("user_id",$u->id)->delete();
  $u->forceDelete();
}
echo "clean ok";
PHP
)"
docker compose exec -T php php artisan tinker --execute="$CLEAN"
echo
[ "${1:-}" = "--clean" ] && exit 0
SEED="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,Chapter,Lesson,User,Enrollment,LessonProgress};
use Illuminate\Support\Facades\{DB,Hash};
$gv = User::factory()->teacher()->verified()->create(["name"=>"QA FW6 GV","email"=>"fw6q-gv@example.com"]);
$mk = fn ($slug, $title, $pub = true) => Course::factory()->{$pub ? "published" : "unpublished"}()->create(["title"=>$title,"slug"=>$slug,"grade_level"=>9,"price"=>0,"created_by"=>$gv->id,"enrollments_count"=>0]);
$pw = Hash::make("matkhau-123");
$mix = User::factory()->student()->verified()->create(["name"=>"QA Mix","email"=>"fw6q-mix@example.com","password"=>$pw,"grade_level"=>9]);
$oth = User::factory()->student()->verified()->create(["name"=>"QA Other","email"=>"fw6q-other@example.com","password"=>$pw,"grade_level"=>9]);
$withLessons = function ($c, int $n) {
  $ch = Chapter::factory()->create(["course_id"=>$c->id,"title"=>"Chương 1","position"=>1]);
  $ls = [];
  for ($i=1;$i<=$n;$i++) $ls[] = Lesson::factory()->external()->create(["chapter_id"=>$ch->id,"course_id"=>$c->id,"title"=>"Bài $i","position"=>$i,"duration_seconds"=>60]);
  return $ls;
};
$ids = [];
for ($i = 1; $i <= 13; $i++) {
  $title = "QA Khóa $i";
  $pub = true;
  if ($i === 11) $title = "QA Khóa tiêu đề cực kỳ dài ".str_repeat("Nguyễn Thị Ánh Tuyết ", 6);
  if ($i === 12) $pub = false;
  $c = $mk("e2e-fw6q-$i", $title, $pub);
  if ($i === 13) { /* 0 bài */ }
  else {
    $ls = $withLessons($c, 2);
    if ($i === 10) foreach ($ls as $l) LessonProgress::factory()->completed()->create(["user_id"=>$mix->id,"lesson_id"=>$l->id,"course_id"=>$c->id,"last_accessed_at"=>now()]);
  }
  $e = Enrollment::factory()->create(["user_id"=>$mix->id,"course_id"=>$c->id]);
  DB::table("enrollments")->where("id",$e->id)->update(["last_accessed_at"=>now()->subMinutes($i)]);
  $ids[$i] = $c->id;
}
$p = $mk("e2e-fw6q-cho", "QA Chờ duyệt");
Enrollment::factory()->pendingApproval()->create(["user_id"=>$mix->id,"course_id"=>$p->id]);
$r = $mk("e2e-fw6q-tuchoi", "QA Bị từ chối");
Enrollment::factory()->rejected("Lý do <b>thử</b> HTML & dài.")->create(["user_id"=>$mix->id,"course_id"=>$r->id]);
$rv = $mk("e2e-fw6q-thuhoi", "QA Bị thu hồi"); $withLessons($rv, 1);
Enrollment::factory()->revoked()->create(["user_id"=>$mix->id,"course_id"=>$rv->id]);
$oc = $mk("e2e-fw6q-other", "QA Khóa của người khác"); $withLessons($oc, 1);
Enrollment::factory()->create(["user_id"=>$oth->id,"course_id"=>$oc->id]);
echo "SEED full={$ids[10]} long={$ids[11]} unpub={$ids[12]} zero={$ids[13]} revoked=$rv->id other=$oc->id";
PHP
)"
OUT="$(docker compose exec -T php php artisan tinker --execute="$SEED")"
echo "$OUT" | grep -o 'full=.*' || { echo "$OUT"; exit 1; }
