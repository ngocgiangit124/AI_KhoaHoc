#!/usr/bin/env bash
# QA FA6: dữ liệu thêm cho e2e/duyet-dang-ky-qa-real.spec.ts. Chạy SAU `seed-e2e-requests.sh --reset` (cùng tiền tố e2e-fa6-/"E2E FA6 " nên `seed-e2e-requests.sh --clean` dọn luôn).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW'" >&2; exit 1 ;; esac
PHP='
use App\Models\{Course, Enrollment, User};
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($e, $name, $state) => User::where("email", "e2e-fa6-$e@example.com")->first()
  ?? User::factory()->$state()->create(["email" => "e2e-fa6-$e@example.com", "name" => $name, "password" => $pw]);
$gv1 = User::where("email", "e2e-fa6-gv1@example.com")->firstOrFail();
$gv2 = User::where("email", "e2e-fa6-gv2@example.com")->firstOrFail();
$hs = fn (int $n) => $mk("hs$n", "E2E FA6 HS $n", "student");
$course = function (string $title, array $gvs) {
  $c = Course::where("title", $title)->first() ?? Course::factory()->published()->create(["title" => $title, "grade_level" => 9, "price" => 0]);
  $c->teachers()->syncWithoutDetaching(array_map(fn ($g) => $g->id, $gvs));
  return $c;
};
$enroll = function (Course $c, User $u, int $min) {
  if (Enrollment::where("course_id", $c->id)->where("user_id", $u->id)->exists()) return;
  Enrollment::factory()->pendingApproval()->create(["course_id" => $c->id, "user_id" => $u->id, "requested_at" => now()->subMinutes($min)]);
};
$spec = ["E2E FA6 QA Race" => [[$gv1], [10, 11, 12, 13]], "E2E FA6 QA Price" => [[$gv1], [14, 15]], "E2E FA6 QA Deleted" => [[$gv1], [16, 17]], "E2E FA6 QA Removed" => [[$gv1, $gv2], [18, 19]], "E2E FA6 QA Reasons" => [[$gv1], range(50, 63)]];
foreach ($spec as $title => [$gvs, $ns]) { $c = $course($title, $gvs); foreach ($ns as $i => $n) $enroll($c, $hs($n), 50 - $i); }
User::where("email", "like", "e2e-fa6-hs5%")->orWhere("email", "like", "e2e-fa6-hs6%")->update(["email_verified_at" => now()]);
echo "qa seed ok";'
docker compose exec -T php php artisan tinker --execute="$PHP"
echo
