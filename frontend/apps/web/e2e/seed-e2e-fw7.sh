#!/usr/bin/env bash
# Dữ liệu cho e2e/quyen-du-lieu-real.spec.ts (FW7) — idempotent, chạy trên máy host, cần Docker local (infra) đang chạy:
#   frontend/apps/web/e2e/seed-e2e-fw7.sh           # tạo (hoặc tạo lại) tài khoản "fw7-*@example.com"
#   frontend/apps/web/e2e/seed-e2e-fw7.sh --reset   # như trên (xoá rồi tạo lại)
#   frontend/apps/web/e2e/seed-e2e-fw7.sh --clean   # dọn sạch
# Chỉ chạy ở local/testing (tinker kiểm tra app()->environment). KHÔNG chạy migrate/seed của Laravel. Chỉ đụng tài khoản fw7-*.
# Học sinh (mật khẩu matkhau-123, đã xác thực email, tên "E2E FW7 ..."):
#   fw7-main@example.com        đồng ý ở phiên bản HIỆN HÀNH; có email phụ huynh fw7-main-ph@example.com + SĐT phụ huynh (xem/sửa/xoá)
#   fw7-old@example.com         đồng ý ở phiên bản cũ 2026-09 -> hiện banner chấp nhận lại
#   fw7-export@example.com      tải dữ liệu (hạn mức 2 lần/ngày: chạy --reset trước mỗi lần chạy e2e)
#   fw7-delete@example.com      xoá tài khoản (OTP qua Mailpit); sau khi xoá bị ẩn danh
#   fw7-unverified@example.com  CHƯA xác thực email (xoá tài khoản -> 403 ACCOUNT_NOT_VERIFIED)
#   fw7-unsub@example.com       có email phụ huynh; seed in ra token huỷ nhận
# In ra: unsub=<token> unsubmain=<token>
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
STATE="${TMPDIR:-/tmp}/vv-fw7-deleted-ids"

CLEAN="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\User;
use Illuminate\Support\Facades\DB;
$ids = User::where("email","like","fw7-%@example.com")->pluck("id")->all();
$extra = array_filter(array_map("intval", explode(",", getenv("FW7_EXTRA_IDS") ?: "")));
foreach ($extra as $id) { $u = User::find($id); if ($u && $u->anonymized_at !== null) { $ids[] = $id; } }
foreach (array_unique($ids) as $id) {
  foreach (["consents","otp_codes","enrollments","lesson_progress","quiz_attempts"] as $t) {
    try { if (Schema::hasTable($t)) DB::table($t)->where("user_id",$id)->delete(); } catch (Throwable $e) {}
  }
  try { User::where("id",$id)->delete(); } catch (Throwable $e) { echo "skip $id: ".substr($e->getMessage(),0,120)."\n"; }
}
echo "clean ok";
PHP
)"
EXTRA=""; [ -f "$STATE" ] && EXTRA="$(cat "$STATE")"
docker compose exec -T -e FW7_EXTRA_IDS="$EXTRA" php php artisan tinker --execute="$CLEAN"
echo
rm -f "$STATE"
if [ "${1:-}" = "--clean" ]; then exit 0; fi

SEED="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{User,Consent};
use App\Services\Privacy\ParentNoticeToken;
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("matkhau-123");
$cur = (string) config("privacy.policy_version");
$mk = fn ($name, $email, $extra = []) => User::factory()->student()->verified()->create(array_merge(["name"=>"E2E FW7 $name","email"=>$email,"password"=>$pw,"grade_level"=>9,"date_of_birth"=>"2012-05-01","parent_email"=>null,"parent_phone"=>null], $extra));
$consent = function ($u, $ver) {
  foreach (["terms","privacy_policy"] as $t) {
    Consent::create(["user_id"=>$u->id,"type"=>$t,"policy_version"=>$ver,"granted_by"=>"self","channel"=>"web_form","granted_at"=>now()->subDay(),"ip"=>"203.0.113.9","user_agent"=>"e2e-fw7"]);
  }
};
$main = $mk("Chính","fw7-main@example.com",["parent_email"=>"fw7-main-ph@example.com","parent_phone"=>"0912345678"]); $consent($main, $cur);
$old = $mk("Bản cũ","fw7-old@example.com"); $consent($old, "2026-09");
$exp = $mk("Xuất dữ liệu","fw7-export@example.com"); $consent($exp, $cur);
$del = $mk("Xoá","fw7-delete@example.com"); $consent($del, $cur);
$unv = $mk("Chưa xác thực","fw7-unverified@example.com",["email_verified_at"=>null,"phone_verified_at"=>null]); $consent($unv, $cur);
$uns = $mk("Huỷ nhận","fw7-unsub@example.com",["parent_email"=>"fw7-unsub-ph@example.com"]); $consent($uns, $cur);
echo "SEED unsub=".ParentNoticeToken::make($uns)." unsubmain=".ParentNoticeToken::make($main)." delid=$del->id";
PHP
)"
OUT="$(docker compose exec -T php php artisan tinker --execute="$SEED")"
echo "$OUT" | grep -o 'unsub=.*' || { echo "$OUT"; exit 1; }
DELID="$(echo "$OUT" | grep -o 'delid=[0-9]*' | cut -d= -f2)"
echo "$DELID" > "$STATE"
