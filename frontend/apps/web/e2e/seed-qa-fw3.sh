#!/usr/bin/env bash
# QA FW3: học sinh bổ sung fw3-qa-*@example.com (mật khẩu matkhau-123). Chạy SAU `seed-e2e-fw3.sh --reset` (cần khóa e2e-fw3-*, mã E2EFW3).
# Dọn: `seed-e2e-fw3.sh --clean` (xoá mọi fw3-%@example.com, đơn/giỏ của họ, mã E2EFW3%).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
SEED="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Order,Cart,Coupon};
use Illuminate\Support\Facades\{DB,Hash};
$toan = Course::where("slug","e2e-fw3-toan")->firstOrFail(); $van = Course::where("slug","e2e-fw3-van")->firstOrFail(); $anh = Course::where("slug","e2e-fw3-anh")->firstOrFail();
$admin = User::where("email","fw3-gv-admin-unused@example.com")->firstOrFail();
$cpPct = Coupon::where("code","E2EFW3")->firstOrFail();
Coupon::factory()->percent(20)->create(["code"=>"E2EFW3B","name"=>"E2E FW3 giảm 20%","created_by"=>$admin->id]);
$pw = Hash::make("matkhau-123");
$mkUser = fn ($key) => User::factory()->student()->verified()->create(["name"=>"QA FW3 $key","email"=>"fw3-qa-$key@example.com","password"=>$pw,"grade_level"=>9,"phone"=>"09".random_int(10000000,99999999)]);
$cartFor = function ($u, array $courses, $coupon = null) { $c = Cart::factory()->withCourses($courses)->create(["user_id"=>$u->id]); if ($coupon) { $c->forceFill(["coupon_id"=>$coupon->id])->save(); } return $c; };
$mkOrder = function ($u, $course, string $code, string $state, array $over = []) {
  $o = Order::factory()->{$state}()->create(array_merge(["user_id"=>$u->id,"code"=>$code,"payment_method"=>"manual","subtotal_amount"=>$course->price,"discount_amount"=>0,"total_amount"=>$course->price], $over));
  DB::table("order_items")->insert(["order_id"=>$o->id,"course_id"=>$course->id,"course_title"=>$course->title,"unit_price"=>$course->price,"discount_amount"=>0,"final_amount"=>$course->price]);
  return $o;
};
$cartFor($mkUser("net"), [$toan]);
$cartFor($mkUser("net2"), [$van]);
$cartFor($mkUser("two"), [$toan, $van]);
$cartFor($mkUser("coupon"), [$toan], $cpPct);
$u = $mkUser("repkeep"); $mkOrder($u, $toan, "VVQAKEEP001", "manual"); $cartFor($u, [$toan, $van]);
$u = $mkUser("repnew"); $mkOrder($u, $toan, "VVQANEW0001", "manual"); $cartFor($u, [$toan, $van]);
$u = $mkUser("limit"); for ($i = 1; $i <= 5; $i++) { $mkOrder($u, $anh, "VVQALIM000$i", "cancelled", ["status_reason"=>"user_cancelled","cancelled_at"=>now()]); } $cartFor($u, [$toan]);
$u = $mkUser("links");
$mkOrder($u, $toan, "VVQACANC001", "cancelled", ["status_reason"=>"user_cancelled","cancelled_at"=>now()->subDay(),"created_at"=>now()->subDays(2)]);
$mkOrder($u, $van, "VVQAEXPD001", "cancelled", ["status_reason"=>"expired","cancelled_at"=>now()->subDay(),"created_at"=>now()->subDays(4)]);
$mkOrder($mkUser("other"), $toan, "VVQAOTHER01", "manual");
$u = $mkUser("xss");
$mkOrder($u, $toan, "VVQAXSS0001", "manual", ["customer_note"=>"<script>window.__xss=1</script> <b>đậm</b>\nDòng 2 & \"nháy\""]);
$mkOrder($u, $van, "VVQAXSS0002", "cancelled", ["status_reason"=>"admin_cancelled","cancelled_at"=>now(),"cancel_reason_public"=>"<b>lý do</b><img src=x onerror=window.__xss=1>\nDòng 2"]);
$cartFor($mkUser("me"), [$toan, $van]);
$cartFor($mkUser("kbd"), [$toan]);
$cartFor($mkUser("kbd2"), [$toan]);
$mkOrder($mkUser("cancel"), $toan, "VVQACANCEL1", "manual");
$mkOrder($mkUser("cancel2"), $van, "VVQACANCEL2", "manual");
$mkOrder($mkUser("del"), $toan, "VVQADEL0001", "manual");
echo "QA SEED ok";
PHP
)"
docker compose exec -T php php artisan tinker --execute="$SEED"
echo
