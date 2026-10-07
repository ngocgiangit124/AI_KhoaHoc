#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/ho-so-giao-vien-real.spec.ts (FA11, US-020). Idempotent, chạy trên máy host, cần Docker local đang chạy:
#   frontend/apps/admin/e2e/seed-e2e-profiles.sh            # tạo (chưa có thì tạo) tài khoản + hồ sơ "E2E FA11 ..."
#   frontend/apps/admin/e2e/seed-e2e-profiles.sh --reset    # dọn rồi tạo lại từ đầu (dùng trước MỖI lần chạy spec: spec ghi vào hồ sơ)
#   frontend/apps/admin/e2e/seed-e2e-profiles.sh --clean    # dọn: xoá MỌI tài khoản e2e-fa11-*, hồ sơ, đồng ý, khóa "E2E FA11 ..." + file ảnh đã tải lên
# Chỉ chạy ở APP_ENV local/testing (từ chối ở môi trường khác). Mật khẩu mọi tài khoản: `Password123!`.
# Trạng thái sau seed (6 người đang bật trang chủ = đủ giới hạn, để thử bật người thứ 7 → 409):
#   e2e-fa11-gv1   giáo viên chưa có hồ sơ (tự sửa "Hồ sơ của tôi")        e2e-fa11-qlt1  Quản lý trang (đăng nhập MFA qua Mailpit)
#   e2e-fa11-gv2   đã đồng ý + đủ nội dung + khóa đang bán, bật #1, vừa được QLT sửa hộ (hiện "Đang hiện")
#   e2e-fa11-gv3   giáo viên chưa có hồ sơ, tắt hiển thị (bật được khi còn chỗ)
#   e2e-fa11-gv4   bị khoá, đang bật #2 (vẫn hiện trong danh sách để tắt)
#   e2e-fa11-gv5..gv7  đủ hồ sơ, đang bật #3..#5 (không có khóa đang bán → "Chưa hiện")
#   e2e-fa11-cu    đã đổi sang Quản lý trang nhưng còn hồ sơ + cờ bật #6
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\{DB, Storage};
$users = App\Models\User::where("email", "like", "e2e-fa11-%")->pluck("id");
$files = DB::table("teacher_profiles")->whereIn("user_id", $users)->whereNotNull("avatar_path")->pluck("avatar_path");
foreach ($files as $f) { Storage::disk("uploads")->delete($f); }
$ids = App\Models\Course::withTrashed()->where("title", "like", "E2E FA11 %")->pluck("id");
DB::transaction(function () use ($users, $ids) {
  DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
  DB::table("course_subject")->whereIn("course_id", $ids)->delete();
  App\Models\Lesson::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Chapter::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  DB::table("consents")->whereIn("user_id", $users)->delete();
  DB::table("teacher_profiles")->whereIn("user_id", $users)->delete();
  DB::table("course_teacher")->whereIn("user_id", $users)->delete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan, ", count($ids), " khoa, ", count($files), " file anh";'

SEED_PHP='
use App\Models\{Course, TeacherProfile, User};
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($n, $name, $state = null) => User::where("email", "e2e-fa11-$n@example.com")->first()
  ?? ($state ? User::factory()->$state() : User::factory()->teacher())->create(["email" => "e2e-fa11-$n@example.com", "name" => $name, "password" => $pw]);
$qlt = $mk("qlt1", "E2E FA11 QLT Một", "pageManager");
$gv1 = $mk("gv1", "E2E FA11 GV Một");
$gv2 = $mk("gv2", "E2E FA11 GV Hai");
$gv3 = $mk("gv3", "E2E FA11 GV Ba");
$gv4 = $mk("gv4", "E2E FA11 GV Bốn");
$gv4->forceFill(["status" => App\Enums\UserStatus::Locked])->save();
$gv5 = $mk("gv5", "E2E FA11 GV Năm");
$gv6 = $mk("gv6", "E2E FA11 GV Sáu");
$gv7 = $mk("gv7", "E2E FA11 GV Bảy");
$cu = $mk("cu", "E2E FA11 Cô Cũ");
$cu->forceFill(["role" => App\Enums\UserRole::PageManager])->save();
$full = fn (User $u, int $order, array $extra = []) => TeacherProfile::where("user_id", $u->id)->exists()
  ? null
  : TeacherProfile::factory()->withContent()->consented()->onHomepage($order)->create(array_merge(["user_id" => $u->id], $extra));
$full($gv2, 1, ["profile_updated_by" => $qlt->id, "profile_updated_at" => now()]);
$full($gv4, 2); $full($gv5, 3); $full($gv6, 4); $full($gv7, 5); $full($cu, 6);
if (! Course::where("title", "E2E FA11 Khóa đang bán")->exists()) {
  $c = Course::factory()->published()->create(["title" => "E2E FA11 Khóa đang bán", "grade_level" => 9, "price" => 100000]);
  $c->teachers()->attach([$gv2->id]);
}
echo "seed ok: ", User::where("email", "like", "e2e-fa11-%")->count(), " tai khoan; dang bat toan he thong=", TeacherProfile::where("show_on_homepage", true)->count();'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"
  echo
fi
