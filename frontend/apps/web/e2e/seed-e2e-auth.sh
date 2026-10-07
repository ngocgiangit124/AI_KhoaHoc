#!/usr/bin/env bash
# Học sinh cho e2e xác thực (FW1: auth/otp/password/session/fw-v2-qa) — idempotent, chạy trên máy host, cần Docker local (infra):
#   frontend/apps/web/e2e/seed-e2e-auth.sh          # xoá rồi tạo lại (mật khẩu matkhau-123)
#   frontend/apps/web/e2e/seed-e2e-auth.sh --clean  # chỉ dọn
# - qa-t05-e2e-1..6, qa-t27-e2e-1..8: đã xác thực (session.spec, password.spec, fw-v2-qa.spec)
# - otp-e2e-1..12, qa-fwv2-ct-1: CHƯA xác thực (otp.spec, fw-v2-qa.spec); email đã đổi trong lần chạy trước (`*-moi-*`) cũng bị dọn.
# Mỗi lần chạy lại các spec này nên seed lại (test đổi email/mật khẩu của chính các user này).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

GUARD='
if (! app()->environment(["local", "testing"])) { fwrite(STDERR, "Từ chối: seed e2e chỉ chạy ở môi trường local/testing (hiện: ".app()->environment().")\n"); exit(1); }
'

CLEAN='
use App\Models\User;
foreach (User::where("email","like","qa-t05-e2e-%")->orWhere("email","like","qa-t27-e2e-%")->orWhere("email","like","otp-e2e-%")->orWhere("email","like","qa-fwv2-ct-%")->get() as $u) {
  try { $u->forceDelete(); } catch (\Throwable $e) { $u->delete(); }
}
'

SEED='
use App\Models\User;
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("matkhau-123");
$mk = function (string $email, bool $verified, string $phone) use ($pw) {
  $f = User::factory()->student();
  if ($verified) { $f = $f->verified(); }
  return $f->create(["name" => "E2E ".$email, "email" => $email, "phone" => $phone, "password" => $pw, "grade_level" => 9]);
};
$n = 0;
foreach (range(1, 6) as $i) { $mk("qa-t05-e2e-$i@example.com", true, "0911".str_pad((string) (100000 + ++$n), 6, "0", STR_PAD_LEFT)); }
foreach (range(1, 8) as $i) { $mk("qa-t27-e2e-$i@example.com", true, "0912".str_pad((string) (100000 + ++$n), 6, "0", STR_PAD_LEFT)); }
foreach (range(1, 12) as $i) { $mk("otp-e2e-$i@example.com", false, "0913".str_pad((string) (100000 + ++$n), 6, "0", STR_PAD_LEFT)); }
$mk("qa-fwv2-ct-1@example.com", false, "0914100001");
echo "seeded\n";
'

docker compose exec -T php php artisan tinker --execute="$GUARD$CLEAN"
if [[ "${1:-}" != "--clean" ]]; then
  docker compose exec -T php php artisan tinker --execute="$GUARD$SEED"
fi
