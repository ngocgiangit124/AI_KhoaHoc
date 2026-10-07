#!/usr/bin/env bash
# Dữ liệu cho e2e/danh-muc-real.spec.ts (FW2) — idempotent, chạy trên máy host, cần Docker local (infra) đang chạy:
#   frontend/apps/web/e2e/seed-e2e-catalog.sh          # tạo (hoặc tạo lại) dữ liệu tiền tố "e2e-fw2-"
#   frontend/apps/web/e2e/seed-e2e-catalog.sh --clean  # dọn sạch (khóa, chuyên đề, học sinh/giáo viên fw2-*)
# Học sinh (mật khẩu matkhau-123): fw2-hs-ok (đã xác thực), fw2-hs-unv (chưa xác thực),
# fw2-hs-own (đã mua khóa trả phí), fw2-hs-pend (đang chờ duyệt khóa miễn phí) @example.com.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

CLEAN='
use App\Models\{Course,Subject,User,Enrollment};
foreach (Course::withTrashed()->where("slug","like","e2e-fw2-%")->get() as $c) {
  Enrollment::where("course_id",$c->id)->delete();
  $c->subjects()->detach(); $c->teachers()->detach();
  foreach ($c->lessons()->withTrashed()->get() as $l) { $l->forceDelete(); }
  foreach ($c->chapters()->withTrashed()->get() as $ch) { $ch->forceDelete(); }
  $c->forceDelete();
}
Subject::where("slug","like","e2e-fw2-%")->delete();
foreach (User::where("email","like","fw2-%@example.com")->get() as $u) { Enrollment::where("user_id",$u->id)->delete(); $u->delete(); }
'

SEED='
use App\Models\{Course,Subject,Chapter,Lesson,User,Enrollment};
use App\Enums\EnrollmentStatus;
use Illuminate\Support\Facades\Hash;
$hh = Subject::factory()->create(["name"=>"E2E FW2 Hình học","slug"=>"e2e-fw2-hinh-hoc"]);
$dsx = Subject::factory()->create(["name"=>"E2E FW2 Đại số","slug"=>"e2e-fw2-dai-so"]);
$gv1 = User::factory()->teacher()->verified()->create(["name"=>"E2E FW2 Cô Lan","email"=>"fw2-gv1@example.com"]);
$gv2 = User::factory()->teacher()->verified()->create(["name"=>"E2E FW2 Thầy Minh","email"=>"fw2-gv2@example.com"]);
$desc = "<h2>Giới thiệu</h2><p>Nội dung <strong>đậm</strong> và <em>nghiêng</em>.</p><script>window.__xss=1</script><img src=x onerror=\"window.__xss=1\"><a href=\"javascript:window.__xss=1\">xấu</a><a href=\"https://example.com/tai-lieu\">tài liệu</a>";
for ($i = 1; $i <= 12; $i++) { $desc .= "<p>Đoạn mô tả số $i: học hình học phẳng từ cơ bản đến nâng cao, có bài tập minh hoạ và lời giải chi tiết cho từng dạng.</p>"; }
$mk = function (string $slug, string $title, int $grade, int $price, $subj, string $d = "<p>Mô tả.</p>") use ($gv1) {
  $c = Course::factory()->published()->create(["title"=>$title,"slug"=>$slug,"grade_level"=>$grade,"price"=>$price,"description"=>$d,"short_description"=>"Mô tả ngắn: $title","enrollments_count"=>0,"created_by"=>$gv1->id]);
  $c->subjects()->attach($subj->id);
  return $c;
};
$a = $mk("e2e-fw2-hinh-hoc-9-mien-phi","E2E FW2 Hình học lớp 9 miễn phí",9,0,$hh,$desc);
$b = $mk("e2e-fw2-dai-so-9-tra-phi","E2E FW2 Đại số lớp 9 trả phí",9,299000,$dsx);
$c8 = $mk("e2e-fw2-hinh-hoc-8-tra-phi","E2E FW2 Hình học lớp 8 trả phí",8,150000,$hh);
$empty = $mk("e2e-fw2-chua-co-noi-dung-7","E2E FW2 Chưa có nội dung",7,0,$dsx);
$a->teachers()->attach($gv1->id,["added_by"=>$gv1->id]); $a->teachers()->attach($gv2->id,["added_by"=>$gv1->id]);
$b->teachers()->attach($gv1->id,["added_by"=>$gv1->id]);
foreach ([$a,$b,$c8] as $course) {
  foreach ([1,2] as $p) {
    $ch = Chapter::factory()->create(["course_id"=>$course->id,"title"=>"Chương $p","position"=>$p]);
    foreach ([1,2,3] as $q) {
      Lesson::factory()->create(["chapter_id"=>$ch->id,"course_id"=>$course->id,"title"=>"Bài $p.$q","position"=>$q,"is_preview"=>($p==1&&$q==1),"duration_seconds"=>300*$q]);
    }
  }
}
for ($n = 1; $n <= 27; $n++) { $mk(sprintf("e2e-fw2-phan-trang-%02d",$n), sprintf("E2E FW2 Phân trang %02d",$n), 10, 100000, $dsx); }
$pw = Hash::make("matkhau-123");
$ok  = User::factory()->student()->verified()->create(["name"=>"FW2 HS OK","email"=>"fw2-hs-ok@example.com","password"=>$pw,"grade_level"=>9]);
$unv = User::factory()->student()->create(["name"=>"FW2 HS Chưa xác thực","email"=>"fw2-hs-unv@example.com","password"=>$pw,"grade_level"=>9]);
$own = User::factory()->student()->verified()->create(["name"=>"FW2 HS Sở hữu","email"=>"fw2-hs-own@example.com","password"=>$pw,"grade_level"=>9]);
$pen = User::factory()->student()->verified()->create(["name"=>"FW2 HS Chờ duyệt","email"=>"fw2-hs-pend@example.com","password"=>$pw,"grade_level"=>9]);
Enrollment::factory()->create(["user_id"=>$own->id,"course_id"=>$b->id]);
Enrollment::factory()->pendingApproval()->create(["user_id"=>$pen->id,"course_id"=>$a->id]);
echo "seed ok: free=".$a->id." paid=".$b->id." paid8=".$c8->id." empty=".$empty->id;
'

docker compose exec -T php php artisan tinker --execute="$CLEAN"
if [ "${1:-}" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED"
fi
echo
