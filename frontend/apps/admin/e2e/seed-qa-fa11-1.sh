#!/usr/bin/env bash
# QA FA11-1: dữ liệu bổ sung cho e2e/ho-so-giao-vien-cu-qa-real.spec.ts. Chạy SAU `seed-e2e-legacy-profile.sh --reset`; dọn bằng `seed-qa-fa11-1.sh --clean` TRƯỚC `seed-e2e-legacy-profile.sh --clean`.
# Tài khoản e2e-fa11b-qa-* (mật khẩu `Password123!`): adm (admin), av (QLT chỉ còn ảnh), co (QLT chỉ còn đồng ý), fl (QLT đủ ảnh+đồng ý, dùng cho 375px/bàn phím),
# pub (giáo viên hiện trang chủ, có khóa published; admin đổi vai trò giữa phiên). Ảnh là file WebP thật trong kho uploads. Chỉ local/testing.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW'" >&2; exit 1 ;; esac
MODE="${1:-}"
case "$MODE" in ""|--clean) ;; *) echo "Dùng: $0 [--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\{DB, Storage};
$ids = DB::table("courses")->where("title", "E2E FA11B QA Khoa")->pluck("id");
DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
DB::table("courses")->whereIn("id", $ids)->delete();
$users = App\Models\User::where("email", "like", "e2e-fa11b-qa-%")->pluck("id");
foreach (DB::table("teacher_profiles")->whereIn("user_id", $users)->whereNotNull("avatar_path")->pluck("avatar_path") as $f) { Storage::disk("uploads")->delete($f); }
DB::table("course_teacher")->whereIn("user_id", $users)->delete();
DB::table("consents")->whereIn("user_id", $users)->delete();
DB::table("teacher_profiles")->whereIn("user_id", $users)->delete();
App\Models\User::whereIn("id", $users)->delete();
echo "da don qa ", count($users);'

SEED_PHP='
use App\Models\{Course, TeacherProfile, User};
use Illuminate\Support\Facades\{Hash, Storage};
$pw = Hash::make("Password123!");
$webp = base64_decode("UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==");
$mk = function ($n, $name, $state) use ($pw) {
  return User::where("email", "e2e-fa11b-qa-$n@example.com")->first()
    ?? User::factory()->$state()->create(["email" => "e2e-fa11b-qa-$n@example.com", "name" => $name, "password" => $pw]);
};
$img = function () use ($webp) { $f = (string) Illuminate\Support\Str::uuid().".webp"; Storage::disk("uploads")->put($f, $webp); return $f; };
$mk("adm", "E2E FA11B QA Admin", "admin");
$av = $mk("av", "E2E FA11B QA Chi Anh", "pageManager");
$co = $mk("co", "E2E FA11B QA Chi Dong Y", "pageManager");
$fl = $mk("fl", "E2E FA11B QA Day Du", "pageManager");
$pub = $mk("pub", "E2E FA11B QA GV Cong Khai", "teacher");
$p = fn ($u, $f) => TeacherProfile::where("user_id", $u->id)->exists() || $f()->create(["user_id" => $u->id, "avatar_path" => null]);
$p($av, fn () => TeacherProfile::factory()->withContent());
TeacherProfile::where("user_id", $av->id)->update(["avatar_path" => $img(), "public_consent_at" => null, "public_consent_version" => null]);
$p($co, fn () => TeacherProfile::factory()->withContent()->consented());
TeacherProfile::where("user_id", $co->id)->update(["avatar_path" => null]);
$p($fl, fn () => TeacherProfile::factory()->withContent()->consented());
TeacherProfile::where("user_id", $fl->id)->update(["avatar_path" => $img()]);
$p($pub, fn () => TeacherProfile::factory()->withContent()->consented()->onHomepage(1));
TeacherProfile::where("user_id", $pub->id)->update(["avatar_path" => $img()]);
if (! Course::where("title", "E2E FA11B QA Khoa")->exists()) {
  Course::factory()->published()->create(["title" => "E2E FA11B QA Khoa", "price" => 100000])->teachers()->attach([$pub->id]);
}
echo "qa seed ok: pub=", $pub->id, " av=", $av->id, " co=", $co->id, " fl=", $fl->id;'

if [ "$MODE" = "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"; echo
else
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"; echo
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"; echo
fi
