#!/usr/bin/env bash
# Dữ liệu cho e2e/gio-hang-thanh-toan-real.spec.ts (FW3, US-022) — idempotent, chạy trên máy host, cần Docker local (infra) đang chạy:
#   frontend/apps/web/e2e/seed-e2e-fw3.sh           # tạo (hoặc tạo lại) dữ liệu tiền tố "e2e-fw3-" / "fw3-"
#   frontend/apps/web/e2e/seed-e2e-fw3.sh --reset   # như trên (xoá rồi tạo lại)
#   frontend/apps/web/e2e/seed-e2e-fw3.sh --clean   # dọn sạch (đơn, giỏ, ghi danh, mã giảm giá, khóa, học sinh)
# Chỉ chạy ở local/testing (tinker kiểm tra app()->environment). KHÔNG chạy migrate/seed của Laravel. Chỉ đụng: học sinh fw3-*@example.com,
# khóa slug e2e-fw3-*, mã giảm giá E2EFW3*, đơn của các học sinh đó. Không đụng khóa 285/287, fw4-/fw5-/fw7-, demo *@vitaminvui.test.
# Học sinh (mật khẩu matkhau-123, đã xác thực email trừ fw3-hs-unv). Khóa có phí: e2e-fw3-toan 300.000đ, e2e-fw3-van 250.000đ, e2e-fw3-anh 199.000đ.
#   fw3-hs-buy      giỏ trống, không đơn: thêm khóa từ trang chi tiết, áp mã, đặt đơn (luồng chính)
#   fw3-hs-cart     giỏ [toan, van], chưa có đơn: giỏ + thanh toán + gửi đơn + dùng lại đơn
#   fw3-hs-tabs     giỏ [toan]: gửi đơn từ 2 tab -> đúng 1 đơn
#   fw3-hs-replace  có đơn chờ [toan] (VVFW3REPL001) + giỏ [toan, van]: 409 PENDING_ORDER_EXISTS -> hộp thay đơn
#   fw3-hs-change   giỏ [toan] + mã E2EFW3 (đổi giỏ giữa chừng bằng e2e -> 409 CHECKOUT_CHANGED)
#   fw3-hs-orders   12 đơn (pending, paid, admin_cancelled có lý do, expired, superseded -> VVFW3USRC001, user_cancelled...) -> 2 trang
#   fw3-hs-cancel   1 đơn chờ VVFW3CANC001 (tự huỷ); fw3-hs-race 1 đơn chờ VVFW3RACE001 (huỷ gặp 409 giả lập bằng route)
#   fw3-hs-limit    5 đơn manual huỷ trong hôm nay + giỏ [toan]: 429 MANUAL_ORDER_LIMIT
#   fw3-hs-zero     giỏ [anh]; mã E2EFW3FREE giảm 100% -> đơn 0đ paid ngay
#   fw3-hs-unv      CHƯA xác thực + giỏ [toan]: checkout 403 ACCOUNT_NOT_VERIFIED
#   fw3-hs-other    học sinh khác (xem đơn của fw3-hs-orders -> 404)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

CLEAN="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Coupon};
use Illuminate\Support\Facades\{DB,Schema};
$uids = User::where("email","like","fw3-%@example.com")->pluck("id")->all();
$cids = Course::withTrashed()->where("slug","like","e2e-fw3-%")->pluck("id")->all();
$oids = DB::table("orders")->whereIn("user_id",$uids)->pluck("id")->all();
$oids = array_values(array_unique(array_merge($oids, $cids ? DB::table("order_items")->whereIn("course_id",$cids)->pluck("order_id")->all() : [])));
foreach (["order_items","order_status_logs","order_notes","payment_attempts","coupon_usages"] as $t) {
  if ($oids && Schema::hasTable($t)) { try { DB::table($t)->whereIn("order_id",$oids)->delete(); } catch (Throwable $e) { echo "skip $t: ".substr($e->getMessage(),0,100)."\n"; } }
}
if ($oids) { DB::table("enrollments")->whereIn("order_id",$oids)->delete(); DB::table("orders")->whereIn("id",$oids)->delete(); }
if ($uids) {
  $carts = DB::table("carts")->whereIn("user_id",$uids)->pluck("id")->all();
  if ($carts) { DB::table("cart_items")->whereIn("cart_id",$carts)->delete(); DB::table("carts")->whereIn("id",$carts)->delete(); }
  foreach (["enrollments","otp_codes","consents"] as $t) { try { if (Schema::hasTable($t)) DB::table($t)->whereIn("user_id",$uids)->delete(); } catch (Throwable $e) {} }
}
if ($cids) {
  DB::table("cart_items")->whereIn("course_id",$cids)->delete();
  DB::table("enrollments")->whereIn("course_id",$cids)->delete();
  foreach (Course::withTrashed()->whereIn("id",$cids)->get() as $c) { $c->forceDelete(); }
}
foreach (Coupon::where("code","like","E2EFW3%")->get() as $cp) { DB::table("coupon_usages")->where("coupon_id",$cp->id)->delete(); $cp->delete(); }
foreach (User::whereIn("id",$uids)->get() as $u) { try { $u->delete(); } catch (Throwable $e) { echo "skip user {$u->id}: ".substr($e->getMessage(),0,100)."\n"; } }
User::where("email","fw3-gv@example.com")->delete();
echo "clean ok";
PHP
)"
docker compose exec -T php php artisan tinker --execute="$CLEAN"
echo
if [ "${1:-}" = "--clean" ]; then exit 0; fi

SEED="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Order,Cart,Coupon};
use Illuminate\Support\Facades\{DB,Hash};
$gv = User::factory()->teacher()->verified()->create(["name"=>"E2E FW3 GV","email"=>"fw3-gv@example.com"]);
$mk = fn ($slug, $title, $price) => Course::factory()->published()->create(["title"=>$title,"slug"=>$slug,"grade_level"=>9,"price"=>$price,"created_by"=>$gv->id,"enrollments_count"=>0]);
$toan = $mk("e2e-fw3-toan", "E2E FW3 Toán 9 nâng cao", 300000);
$van = $mk("e2e-fw3-van", "E2E FW3 Ngữ văn 9", 250000);
$anh = $mk("e2e-fw3-anh", "E2E FW3 Tiếng Anh 9", 199000);
$admin = User::factory()->admin()->create(["email"=>"fw3-gv-admin-unused@example.com"]);
$cpPct = Coupon::factory()->percent(10)->create(["code"=>"E2EFW3","name"=>"E2E FW3 giảm 10%","created_by"=>$admin->id]);
$cpFree = Coupon::factory()->percent(100)->create(["code"=>"E2EFW3FREE","name"=>"E2E FW3 học bổng 100%","created_by"=>$admin->id,"max_uses"=>50,"valid_until"=>now()->addYear()]);
Coupon::factory()->expired()->create(["code"=>"E2EFW3EXP","name"=>"E2E FW3 hết hạn","created_by"=>$admin->id]);
$pw = Hash::make("matkhau-123");
$mkUser = fn ($key, $extra = []) => User::factory()->student()->verified()->create(array_merge(["name"=>"E2E FW3 $key","email"=>"fw3-hs-$key@example.com","password"=>$pw,"grade_level"=>9,"phone"=>"09".random_int(10000000,99999999)], $extra));
$cartFor = function ($u, array $courses, $coupon = null) {
  $c = Cart::factory()->withCourses($courses)->create(["user_id"=>$u->id]);
  if ($coupon) { $c->forceFill(["coupon_id"=>$coupon->id])->save(); }
  return $c;
};
$mkOrder = function ($u, $course, string $code, string $state, array $over = []) {
  $o = Order::factory()->{$state}()->create(array_merge(["user_id"=>$u->id,"code"=>$code,"payment_method"=>"manual","subtotal_amount"=>$course->price,"discount_amount"=>0,"total_amount"=>$course->price], $over));
  DB::table("order_items")->insert(["order_id"=>$o->id,"course_id"=>$course->id,"course_title"=>$course->title,"unit_price"=>$course->price,"discount_amount"=>0,"final_amount"=>$course->price]);
  return $o;
};
$mkUser("buy");
$cartFor($mkUser("cart"), [$toan, $van]);
$cartFor($mkUser("tabs"), [$toan]);
$rep = $mkUser("replace"); $mkOrder($rep, $toan, "VVFW3REPL001", "manual"); $cartFor($rep, [$toan, $van]);
$cartFor($mkUser("change"), [$toan], $cpPct);
$ord = $mkUser("orders");
// Mới nhất trước: pending (1h), paid, admin_cancelled, expired, superseded -> user_cancelled (id kế tiếp), + 6 đơn cũ huỷ.
$mkOrder($ord, $toan, "VVFW3PEND001", "manual", ["created_at"=>now()->subHour()]);
$mkOrder($ord, $van, "VVFW3PAID001", "paid", ["status_reason"=>"manual_confirmed","created_at"=>now()->subDay(),"expires_at"=>now()->subHours(1)]);
$mkOrder($ord, $anh, "VVFW3ADMC001", "cancelled", ["status_reason"=>"admin_cancelled","cancel_reason_public"=>"Không liên hệ được qua SĐT.\nBạn có thể đặt lại.","created_at"=>now()->subDays(2),"cancelled_at"=>now()->subDay()]);
$mkOrder($ord, $toan, "VVFW3EXPD001", "cancelled", ["status_reason"=>"expired","created_at"=>now()->subDays(3),"cancelled_at"=>now()->subDays(1)]);
// superseded phải có id NHỎ hơn đơn kế tiếp của học sinh: tạo theo thứ tự id (superseded rồi user_cancelled).
$mkOrder($ord, $toan, "VVFW3SUPR001", "cancelled", ["status_reason"=>"superseded","created_at"=>now()->subDays(4),"cancelled_at"=>now()->subDays(4)]);
$mkOrder($ord, $van, "VVFW3USRC001", "cancelled", ["status_reason"=>"user_cancelled","created_at"=>now()->subDays(5),"cancelled_at"=>now()->subDays(5)]);
for ($i = 1; $i <= 6; $i++) { $mkOrder($ord, $anh, "VVFW3OLD000$i", "cancelled", ["status_reason"=>"user_cancelled","created_at"=>now()->subDays(10 + $i),"cancelled_at"=>now()->subDays(10 + $i)]); }
$mkOrder($mkUser("cancel"), $toan, "VVFW3CANC001", "manual");
$mkOrder($mkUser("race"), $van, "VVFW3RACE001", "manual");
$lim = $mkUser("limit"); for ($i = 1; $i <= 5; $i++) { $mkOrder($lim, $anh, "VVFW3LIM000$i", "cancelled", ["status_reason"=>"user_cancelled","cancelled_at"=>now()]); } $cartFor($lim, [$toan]);
$cartFor($mkUser("zero"), [$anh]);
$cartFor($mkUser("unv", ["email_verified_at"=>null,"phone_verified_at"=>null]), [$toan]);
$mkUser("other");
echo "SEED ok";
PHP
)"
docker compose exec -T php php artisan tinker --execute="$SEED"
echo
