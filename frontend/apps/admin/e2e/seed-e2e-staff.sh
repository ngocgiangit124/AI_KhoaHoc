#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/tai-khoan-staff-real.spec.ts (FA10, US-016). Idempotent, chạy trên máy host, cần Docker local đang chạy:
#   frontend/apps/admin/e2e/seed-e2e-staff.sh            # tạo (chưa có thì tạo) tài khoản e2e-fa10-* + 2 khóa "E2E FA10 ..."
#   frontend/apps/admin/e2e/seed-e2e-staff.sh --reset    # dọn rồi tạo lại từ đầu (dùng trước MỖI lần chạy spec: spec khóa/đổi vai trò/tạo tài khoản)
#   frontend/apps/admin/e2e/seed-e2e-staff.sh --clean    # dọn: xoá MỌI tài khoản e2e-fa10-*, khóa "E2E FA10 ..."
# Chỉ chạy ở APP_ENV local/testing (từ chối ở môi trường khác). Chỉ dùng tinker (không migrate/seed). Mật khẩu mọi tài khoản: `Password123!`.
# Trạng thái sau seed (tên hiển thị bắt đầu bằng "E2E FA10"):
#   e2e-fa10-admin1 Admin (đăng nhập MFA qua Mailpit)   e2e-fa10-qlt1 Quản lý trang   e2e-fa10-gv-doivaitro giáo viên phụ trách 2 khóa (đổi vai trò -> released_course_ids)
#   e2e-fa10-gv-khoa giáo viên đang hoạt động (khóa/mở khóa/đặt lại mật khẩu)   e2e-fa10-gv-dakhoa giáo viên đã khóa (mở khóa)
#   e2e-fa10-pg01..26 giáo viên (để thử phân trang 25/trang)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\DB;
$users = App\Models\User::where("email", "like", "e2e-fa10-%")->pluck("id");
$ids = App\Models\Course::withTrashed()->where("title", "like", "E2E FA10 %")->pluck("id");
DB::transaction(function () use ($users, $ids) {
  DB::table("course_teacher")->where(fn ($q) => $q->whereIn("course_id", $ids)->orWhereIn("user_id", $users))->delete();
  App\Models\Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan, ", count($ids), " khoa";'

SEED_PHP='
use App\Models\{Course, User};
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($e, $name, $state, $extra = []) => User::where("email", "e2e-fa10-$e@example.com")->first()
  ?? User::factory()->$state()->create(["email" => "e2e-fa10-$e@example.com", "name" => $name, "password" => $pw] + $extra);
$mk("admin1", "E2E FA10 Admin Một", "admin");
$mk("qlt1", "E2E FA10 QLT Một", "pageManager");
$gv = $mk("gv-doivaitro", "E2E FA10 GV Đổi Vai Trò", "teacher");
$mk("gv-khoa", "E2E FA10 GV Khóa", "teacher");
$mk("gv-dakhoa", "E2E FA10 GV Đã Khóa", "teacher", ["status" => "locked"]);
foreach (["A", "B"] as $k) {
  $c = Course::where("title", "E2E FA10 Khóa $k")->first() ?? Course::factory()->published()->create(["title" => "E2E FA10 Khóa $k", "grade_level" => 9]);
  $c->teachers()->syncWithoutDetaching([$gv->id]);
}
for ($n = 1; $n <= 26; $n++) $mk(sprintf("pg%02d", $n), sprintf("E2E FA10 Trang %02d", $n), "teacher");
echo "seed ok: ", User::where("email", "like", "e2e-fa10-%")->count(), " tai khoan";'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"
  echo
fi
