#!/usr/bin/env bash
# Dữ liệu cho e2e GL-A2 (web + admin): đăng nhập sai nhiều lần -> captcha Turnstile. Idempotent, chạy trên máy host, cần Docker local (infra):
#   frontend/apps/web/e2e/seed-e2e-gla2.sh --reset     # dọn rồi tạo lại + xoá bộ đếm limiter của tài khoản e2e (dùng trước MỖI lần chạy)
#   frontend/apps/web/e2e/seed-e2e-gla2.sh --limiter   # chỉ xoá bộ đếm limiter của tài khoản e2e (giữa các lần chạy)
#   frontend/apps/web/e2e/seed-e2e-gla2.sh --clean     # dọn sạch (tài khoản + bộ đếm)
# Chỉ local/testing. Tài khoản: học sinh gla2-hs-1@example.com (mật khẩu matkhau-123, đã xác thực); Admin e2e-gla2-admin1@example.com
# (mật khẩu Password123!, MFA qua Mailpit). Chỉ đụng khoá limiter `login-fail:u:<id>` / `staff-login-fail:u:<id>` của 2 tài khoản này.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
MODE="${1:---reset}"
case "$MODE" in --reset|--clean|--limiter) ;; *) echo "Dùng: $0 [--reset|--clean|--limiter]" >&2; exit 2 ;; esac

LIM='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
foreach (User::whereIn("email", ["gla2-hs-1@example.com","e2e-gla2-admin1@example.com"])->get() as $u) {
  RateLimiter::clear("login-fail:u:".$u->id); RateLimiter::clear("staff-login-fail:u:".$u->id);
}
echo "limiter ok";'
CLEAN='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\User;
use Illuminate\Support\Facades\{DB,RateLimiter};
$users = User::whereIn("email", ["gla2-hs-1@example.com","e2e-gla2-admin1@example.com"])->get();
foreach ($users as $u) { RateLimiter::clear("login-fail:u:".$u->id); RateLimiter::clear("staff-login-fail:u:".$u->id); }
$ids = $users->pluck("id");
DB::transaction(function () use ($ids) {
  DB::table("staff_devices")->whereIn("user_id", $ids)->delete();
  foreach (["otp_codes","consents"] as $t) { try { DB::table($t)->whereIn("user_id", $ids)->delete(); } catch (Throwable $e) {} }
  User::whereIn("id", $ids)->delete();
});
echo "clean ok";'
SEED='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\User;
use Illuminate\Support\Facades\Hash;
User::factory()->student()->verified()->create(["name"=>"E2E GLA2 HS","email"=>"gla2-hs-1@example.com","password"=>Hash::make("matkhau-123"),"grade_level"=>9,"phone"=>"09".random_int(10000000,99999999)]);
User::factory()->admin()->create(["name"=>"E2E GLA2 Admin","email"=>"e2e-gla2-admin1@example.com","password"=>Hash::make("Password123!")]);
echo "seed ok";'

case "$MODE" in
  --limiter) docker compose exec -T php php artisan tinker --execute="$LIM" ;;
  --clean) docker compose exec -T php php artisan tinker --execute="$CLEAN" ;;
  --reset) docker compose exec -T php php artisan tinker --execute="$CLEAN"; echo; docker compose exec -T php php artisan tinker --execute="$SEED" ;;
esac
echo
