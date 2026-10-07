#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/duyet-dang-ky-real.spec.ts (FA6, US-012). Idempotent, chạy trên máy host, cần Docker local đang chạy:
#   frontend/apps/admin/e2e/seed-e2e-requests.sh            # tạo (chưa có thì tạo) tài khoản + khóa + yêu cầu "E2E FA6 ..."
#   frontend/apps/admin/e2e/seed-e2e-requests.sh --reset    # dọn rồi tạo lại từ đầu (dùng trước MỖI lần chạy spec: spec duyệt/từ chối)
#   frontend/apps/admin/e2e/seed-e2e-requests.sh --clean    # dọn: xoá MỌI tài khoản e2e-fa6-*, khóa "E2E FA6 ..." và enrollment của chúng
# Chỉ chạy ở APP_ENV local/testing (từ chối ở môi trường khác). Mật khẩu mọi tài khoản: `Password123!`.
# Trạng thái sau seed:
#   e2e-fa6-qlt1  Quản lý trang (đăng nhập MFA qua Mailpit)
#   e2e-fa6-gv1   giáo viên phụ trách Khóa A và Khóa Nhiều         e2e-fa6-gv2  giáo viên phụ trách Khóa B
#   "E2E FA6 Khóa A": chờ duyệt HS1 (cũ nhất) HS2 HS3 HS4 HS9; đã từ chối HS7 (có lý do); đã duyệt HS8
#   "E2E FA6 Khóa B": chờ duyệt HS5 HS6 (gv1 KHÔNG thấy)
#   "E2E FA6 Khóa Nhiều": 27 yêu cầu chờ duyệt (HS20..HS46) để thử phân trang 25/trang
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\DB;
$users = App\Models\User::where("email", "like", "e2e-fa6-%")->pluck("id");
$ids = App\Models\Course::withTrashed()->where("title", "like", "E2E FA6 %")->pluck("id");
DB::transaction(function () use ($users, $ids) {
  DB::table("enrollments")->where(fn ($q) => $q->whereIn("course_id", $ids)->orWhereIn("user_id", $users)->orWhereIn("approved_by", $users))->delete();
  DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
  DB::table("course_subject")->whereIn("course_id", $ids)->delete();
  DB::table("course_teacher")->whereIn("user_id", $users)->delete();
  App\Models\Lesson::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Chapter::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan, ", count($ids), " khoa";'

SEED_PHP='
use App\Models\{Course, Enrollment, User};
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($e, $name, $state) => User::where("email", "e2e-fa6-$e@example.com")->first()
  ?? User::factory()->$state()->create(["email" => "e2e-fa6-$e@example.com", "name" => $name, "password" => $pw]);
$qlt = $mk("qlt1", "E2E FA6 QLT Một", "pageManager");
$gv1 = $mk("gv1", "E2E FA6 GV Một", "teacher");
$gv2 = $mk("gv2", "E2E FA6 GV Hai", "teacher");
$hs = fn (int $n) => $mk("hs$n", "E2E FA6 HS $n", "student");
$course = function (string $title, User $gv) {
  $c = Course::where("title", $title)->first() ?? Course::factory()->published()->create(["title" => $title, "grade_level" => 9, "price" => 0]);
  $c->teachers()->syncWithoutDetaching([$gv->id]);
  return $c;
};
$a = $course("E2E FA6 Khóa A", $gv1);
$b = $course("E2E FA6 Khóa B", $gv2);
$m = $course("E2E FA6 Khóa Nhiều", $gv1);
$enroll = function (Course $c, User $u, string $state, int $minutesAgo, array $extra = []) {
  if (Enrollment::where("course_id", $c->id)->where("user_id", $u->id)->exists()) return;
  Enrollment::factory()->$state()->create(array_merge(["course_id" => $c->id, "user_id" => $u->id, "requested_at" => now()->subMinutes($minutesAgo)], $extra));
};
foreach ([1 => 500, 2 => 400, 3 => 300, 4 => 200, 9 => 100] as $n => $min) $enroll($a, $hs($n), "pendingApproval", $min);
$enroll($a, $hs(7), "rejected", 1000, ["rejection_reason" => "Chưa đủ điều kiện"]);
$enroll($a, $hs(8), "pendingApproval", 900);
Enrollment::where("course_id", $a->id)->where("user_id", $hs(8)->id)->where("status", "pending_approval")->update(["status" => "active", "approved_by" => $qlt->id, "approved_at" => now(), "activated_at" => now()]);
foreach ([5 => 250, 6 => 150] as $n => $min) $enroll($b, $hs($n), "pendingApproval", $min);
for ($n = 20; $n <= 46; $n++) $enroll($m, $hs($n), "pendingApproval", 5000 - $n);
echo "seed ok: ", User::where("email", "like", "e2e-fa6-%")->count(), " tai khoan; yeu cau cho duyet=", Enrollment::whereIn("course_id", [$a->id, $b->id, $m->id])->where("status", "pending_approval")->count();'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"
  echo
fi
