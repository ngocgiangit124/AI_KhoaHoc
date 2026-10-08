#!/usr/bin/env bash
# Dữ liệu cho e2e/ho-so-giao-vien-cu-real.spec.ts (FA11-1: người đã đổi vai trò tự rút đồng ý / xoá ảnh). Idempotent, máy host, cần Docker local:
#   seed-e2e-legacy-profile.sh [--reset|--clean]   (--reset trước MỖI lần chạy spec, --clean sau cùng). Chỉ APP_ENV local/testing.
# Tài khoản (mật khẩu `Password123!`), tiền tố e2e-fa11b-*:
#   qlt-a  Quản lý trang đã đổi vai trò, còn ảnh + đồng ý đang bật (không bật trang chủ)
#   qlt-c  Quản lý trang chưa từng có hồ sơ (không thấy lối vào)
#   gv     giáo viên có hồ sơ đủ (giữ "Hồ sơ của tôi")
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac
MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\{DB, Storage};
$users = App\Models\User::where("email", "like", "e2e-fa11b-%")->pluck("id");
$files = DB::table("teacher_profiles")->whereIn("user_id", $users)->whereNotNull("avatar_path")->pluck("avatar_path");
foreach ($files as $f) { Storage::disk("uploads")->delete($f); }
DB::transaction(function () use ($users) {
  DB::table("consents")->whereIn("user_id", $users)->delete();
  DB::table("teacher_profiles")->whereIn("user_id", $users)->delete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan";'

SEED_PHP='
use App\Models\{TeacherProfile, User};
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($n, $name, $state = null) => User::where("email", "e2e-fa11b-$n@example.com")->first()
  ?? ($state ? User::factory()->$state() : User::factory()->teacher())->create(["email" => "e2e-fa11b-$n@example.com", "name" => $name, "password" => $pw]);
$a = $mk("qlt-a", "E2E FA11B Cô Cũ", "pageManager");
$c = $mk("qlt-c", "E2E FA11B QLT Không Hồ Sơ", "pageManager");
$gv = $mk("gv", "E2E FA11B GV");
foreach ([$a, $gv] as $u) {
  if (! TeacherProfile::where("user_id", $u->id)->exists()) {
    TeacherProfile::factory()->withContent()->consented()->create(["user_id" => $u->id]);
  }
}
echo "seed ok: ", User::where("email", "like", "e2e-fa11b-%")->count(), " tai khoan";'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"; echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"; echo
fi
