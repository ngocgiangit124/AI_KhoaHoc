#!/usr/bin/env bash
# QA FA11: bổ sung dữ liệu cho e2e/ho-so-giao-vien-qa-real.spec.ts, chạy SAU `seed-e2e-profiles.sh --reset`.
# Thêm admin e2e-fa11-adm1, khóa đang bán cho gv1/gv3/gv4/gv5/gv6/gv7 (tên "E2E FA11 QA ..." nên `seed-e2e-profiles.sh --clean` dọn luôn)
# và hồ sơ đầy đủ (đã đồng ý, tắt hiển thị) cho gv3. Chỉ local/testing.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW'" >&2; exit 1 ;; esac
PHP='
use App\Models\{Course, TeacherProfile, User};
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
if (! User::where("email", "e2e-fa11-adm1@example.com")->exists()) {
  User::factory()->admin()->create(["email" => "e2e-fa11-adm1@example.com", "name" => "E2E FA11 Admin Một", "password" => $pw]);
}
$u = fn ($n) => User::where("email", "e2e-fa11-$n@example.com")->firstOrFail();
foreach (["gv1" => 6, "gv3" => 7, "gv4" => 8, "gv5" => 9, "gv6" => 10, "gv7" => 11] as $n => $grade) {
  $title = "E2E FA11 QA Khóa $n";
  if (! Course::where("title", $title)->exists()) {
    Course::factory()->published()->create(["title" => $title, "grade_level" => $grade, "price" => 100000])->teachers()->attach([$u($n)->id]);
  }
}
$gv3 = $u("gv3");
if (! TeacherProfile::where("user_id", $gv3->id)->exists()) {
  TeacherProfile::factory()->withContent()->consented()->create(["user_id" => $gv3->id]);
}
echo "qa seed ok";'
docker compose exec -T php php artisan tinker --execute="$PHP"
echo
