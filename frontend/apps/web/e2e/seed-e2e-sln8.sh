#!/usr/bin/env bash
# Dữ liệu cho e2e/sln8-real.spec.ts (SLN8) — chỉ tạo/xoá dữ liệu tiền tố "e2e-sln8-" và sln8-*@example.com; không đụng dữ liệu khác.
#   frontend/apps/web/e2e/seed-e2e-sln8.sh --reset   # xoá dữ liệu SLN8 cũ rồi tạo lại, in ra: course=<id> l1=<id> l2=<id>
#   frontend/apps/web/e2e/seed-e2e-sln8.sh --clean   # chỉ dọn
# Khóa miễn phí đã xuất bản "e2e-sln8-khoa" (bài "Bài SLN8 một", "Bài SLN8 hai", không video), học sinh sln8-hs@example.com / matkhau-123 đã ghi danh active.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

CLEAN='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Enrollment};
foreach (Course::withTrashed()->where("slug","like","e2e-sln8-%")->get() as $c) {
  Enrollment::where("course_id",$c->id)->delete();
  foreach ($c->lessons()->withTrashed()->get() as $l) { \DB::table("lesson_progress")->where("lesson_id",$l->id)->delete(); $l->forceDelete(); }
  foreach ($c->chapters()->withTrashed()->get() as $ch) { $ch->forceDelete(); }
  $c->forceDelete();
}
foreach (User::where("email","like","sln8-%@example.com")->get() as $u) { Enrollment::where("user_id",$u->id)->delete(); $u->delete(); }
'
docker compose exec -T php php artisan tinker --execute="$CLEAN"
if [ "${1:-}" = "--clean" ]; then echo "clean ok"; exit 0; fi

SEED='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,Chapter,Lesson,User,Enrollment};
use Illuminate\Support\Facades\Hash;
$gv = User::factory()->teacher()->verified()->create(["name"=>"E2E SLN8 GV","email"=>"sln8-gv@example.com"]);
$c = Course::factory()->published()->create(["title"=>"E2E SLN8 Khóa","slug"=>"e2e-sln8-khoa","grade_level"=>9,"price"=>0,"created_by"=>$gv->id,"enrollments_count"=>0]);
$ch = Chapter::factory()->create(["course_id"=>$c->id,"title"=>"Chương 1","position"=>1]);
$l1 = Lesson::factory()->create(["chapter_id"=>$ch->id,"course_id"=>$c->id,"title"=>"Bài SLN8 một","position"=>1]);
$l2 = Lesson::factory()->create(["chapter_id"=>$ch->id,"course_id"=>$c->id,"title"=>"Bài SLN8 hai","position"=>2]);
$hs = User::factory()->student()->verified()->create(["name"=>"SLN8 HS","email"=>"sln8-hs@example.com","password"=>Hash::make("matkhau-123"),"grade_level"=>9]);
Enrollment::factory()->create(["user_id"=>$hs->id,"course_id"=>$c->id]);
echo "SLN8RESULT course=".$c->id." l1=".$l1->id." l2=".$l2->id;
'
docker compose exec -T php php artisan tinker --execute="$SEED" | grep -o 'SLN8RESULT.*' | sed 's/^SLN8RESULT //'
