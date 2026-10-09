#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/don-hang-real.spec.ts (FA8, US-022). Idempotent, chạy trên máy host, cần Docker local đang chạy:
#   frontend/apps/admin/e2e/seed-e2e-fa8.sh            # tạo (chưa có thì tạo) tài khoản + khóa + đơn "fa8-*"
#   frontend/apps/admin/e2e/seed-e2e-fa8.sh --reset    # dọn rồi tạo lại từ đầu (dùng trước MỖI lần chạy spec: spec duyệt/huỷ/hoàn tiền)
#   frontend/apps/admin/e2e/seed-e2e-fa8.sh --clean    # dọn: xoá đơn/học sinh fa8-*, tài khoản staff e2e-fa8-*, khóa "E2E FA8 ..."
# Chỉ chạy ở APP_ENV local/testing (từ chối ở môi trường khác). Chỉ dùng tinker (không migrate/seed). Mật khẩu mọi tài khoản: `Password123!`.
# KHÔNG đụng dữ liệu khác: mọi thứ nhận diện theo tiền tố email `fa8-%` / `e2e-fa8-%` và tên khóa `E2E FA8 %`.
# Trạng thái sau seed:
#   e2e-fa8-admin1 Admin, e2e-fa8-qlt1 Quản lý trang (đăng nhập MFA qua Mailpit)   e2e-fa8-gv1 giáo viên (đăng nhập thẳng; không vào được Đơn hàng)
#   Đơn CHỜ (mỗi học sinh 1 đơn chờ, học sinh "Fa8 HS n" email fa8-hsN@example.com):
#     hs1 cũ nhất, hạn còn ~3 giờ ("Sắp hết hạn"), 2 khóa (1 khóa ngừng bán), ghi chú HS chứa HTML  hs2 để duyệt   hs3 để 2 tab cùng duyệt
#     hs4 để huỷ   hs5 để "đơn vừa bị huỷ ở tab khác" + duyệt muộn   hs6 có khóa đã xoá (409 COURSE_UNAVAILABLE)   hs7 có ghi chú nội bộ để thêm ghi chú
#   Đơn HUỶ: hs9 hết hạn hôm qua, đã sở hữu khóa (cảnh báo ALREADY_OWNED)   hs10 huỷ 40 ngày trước (quá hạn duyệt muộn)   hs11 huỷ 2 ngày trước (duyệt muộn được)
#   Đơn ĐÃ DUYỆT: hs12 (để đánh dấu hoàn tiền, có enrollment)   27 đơn đã duyệt của "Fa8 Bulk" (để thử phân trang cursor)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

# Chốt chặn thứ hai: chỉ chạy trên DB dev local `vitaminvui` và APP_URL localhost.
DB_NOW="$(docker compose exec -T php php -r 'echo getenv("DB_DATABASE");' 2>/dev/null | tr -d '\r')"
URL_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_URL");' 2>/dev/null | tr -d '\r')"
if [ "$DB_NOW" != "vitaminvui" ] || ! echo "$URL_NOW" | grep -q "localhost"; then
  echo "Từ chối: DB_DATABASE='$DB_NOW', APP_URL='$URL_NOW' (cần DB 'vitaminvui' và APP_URL chứa 'localhost')." >&2; exit 1
fi

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\DB;
$users = App\Models\User::where(fn ($q) => $q->where("email", "like", "fa8-%")->orWhere("email", "like", "e2e-fa8-%"))->pluck("id");
$courses = App\Models\Course::withTrashed()->where("title", "like", "E2E FA8 %")->pluck("id");
DB::transaction(function () use ($users, $courses) {
  $orders = DB::table("orders")->where(fn ($q) => $q->whereIn("user_id", $users)->orWhereIn("confirmed_by", $users)->orWhereIn("refunded_by", $users))->pluck("id");
  DB::table("enrollments")->where(fn ($q) => $q->whereIn("course_id", $courses)->orWhereIn("user_id", $users)->orWhereIn("order_id", $orders))->delete();
  DB::table("order_notes")->whereIn("order_id", $orders)->delete();
  DB::table("order_items")->whereIn("order_id", $orders)->delete();
  DB::table("order_status_logs")->whereIn("order_id", $orders)->delete();
  DB::table("orders")->whereIn("id", $orders)->delete();
  DB::table("order_notes")->whereIn("author_id", $users)->delete();
  DB::table("course_teacher")->where(fn ($q) => $q->whereIn("course_id", $courses)->orWhereIn("user_id", $users))->delete();
  App\Models\Lesson::withTrashed()->whereIn("course_id", $courses)->forceDelete();
  App\Models\Chapter::withTrashed()->whereIn("course_id", $courses)->forceDelete();
  App\Models\Course::withTrashed()->whereIn("id", $courses)->forceDelete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan, ", count($courses), " khoa";'

SEED_PHP='
use App\Models\{Course, Enrollment, Order, User};
use Illuminate\Support\Facades\{DB, Hash};
$pw = Hash::make("Password123!");
$staff = fn ($e, $name, $state) => User::where("email", "e2e-fa8-$e@example.com")->first()
  ?? User::factory()->$state()->create(["email" => "e2e-fa8-$e@example.com", "name" => $name, "password" => $pw]);
$admin = $staff("admin1", "E2E FA8 Admin", "admin");
$qlt = $staff("qlt1", "E2E FA8 QLT", "pageManager");
$gv = $staff("gv1", "E2E FA8 GV", "teacher");
$stu = fn (string $key, string $name, int $n) => User::where("email", "fa8-$key@example.com")->first()
  ?? User::factory()->student()->verified()->create(["email" => "fa8-$key@example.com", "name" => $name, "password" => $pw, "phone" => "09800080".str_pad((string) $n, 2, "0", STR_PAD_LEFT)]);
$course = function (string $title, string $slug, int $price, bool $published = true) use ($gv) {
  $c = Course::withTrashed()->where("title", $title)->first();
  if ($c) return $c;
  $f = Course::factory()->paid($price);
  return ($published ? $f->published() : $f->unpublished())->create(["title" => $title, "slug" => $slug, "grade_level" => 9, "created_by" => $gv->id]);
};
$ca = $course("E2E FA8 Khóa Toán", "e2e-fa8-toan", 300000);
$cb = $course("E2E FA8 Khóa Văn", "e2e-fa8-van", 250000);
$cs = $course("E2E FA8 Khóa Ngừng bán", "e2e-fa8-ngung-ban", 200000, false);
$cx = $course("E2E FA8 Khóa Sẽ xoá", "e2e-fa8-se-xoa", 150000);
$ck = $course("E2E FA8 Khóa Bulk", "e2e-fa8-bulk", 100000);
$seq = 0;
$mk = function (User $s, array $courses, array $attrs, array $logs) use (&$seq) {
  if (Order::where("user_id", $s->id)->exists()) return Order::where("user_id", $s->id)->orderBy("id")->first();
  $seq++;
  $sub = array_sum(array_map(fn ($c) => $c->price, $courses));
  $created = $attrs["created_at"];
  $o = Order::factory()->manual()->create(array_merge([
    "code" => sprintf("VVFA8%02d%s", $seq, strtoupper(substr(md5($s->email), 0, 6))),
    "user_id" => $s->id, "subtotal_amount" => $sub, "discount_amount" => 0, "total_amount" => $sub,
    "expires_at" => $created->copy()->addHours(72),
  ], $attrs));
  foreach ($courses as $c) DB::table("order_items")->insert(["order_id" => $o->id, "course_id" => $c->id, "course_title" => $c->title, "unit_price" => $c->price, "discount_amount" => 0, "final_amount" => $c->price]);
  DB::table("order_status_logs")->insert(array_merge([["order_id" => $o->id, "from_status" => null, "to_status" => "pending", "reason" => null, "actor_type" => "user", "actor_id" => $s->id, "meta" => json_encode(["items" => count($courses)]), "created_at" => $created]], $logs));
  return $o;
};

// --- Đơn chờ ---
$h = fn ($n) => now()->subHours($n);
$hs = [];
foreach ([1 => "Một", 2 => "Hai", 3 => "Ba", 4 => "Bốn", 5 => "Năm", 6 => "Sáu", 7 => "Bảy", 8 => "Tám", 9 => "Chín", 10 => "Mười", 11 => "Mười một", 12 => "Mười hai"] as $n => $w) $hs[$n] = $stu("hs$n", "Fa8 HS $w", $n);
$mk($hs[1], [$ca, $cs], ["created_at" => $h(69), "expires_at" => now()->addHours(3), "customer_note" => "<b>Gọi sau 18h</b> <img src=x onerror=alert(1)> https://example.com/x"], []);
$mk($hs[2], [$ca], ["created_at" => $h(30)], []);
$mk($hs[3], [$cb], ["created_at" => $h(20)], []);
$mk($hs[4], [$cb], ["created_at" => $h(10)], []);
$mk($hs[5], [$ca, $cb], ["created_at" => $h(6)], []);
$mk($hs[6], [$cx], ["created_at" => $h(5)], []);
$o7 = $mk($hs[7], [$ca], ["created_at" => $h(2)], []);
if (!DB::table("order_notes")->where("order_id", $o7->id)->exists()) DB::table("order_notes")->insert(["order_id" => $o7->id, "author_id" => $admin->id, "body" => "Đã gọi 9h, hẹn chuyển khoản chiều nay", "created_at" => $h(1)]);
$cx->delete();

// --- Đơn huỷ ---
$cancelled = function (User $s, array $courses, int $daysAgo) use ($mk) {
  $at = now()->subDays($daysAgo);
  $o = $mk($s, $courses, ["created_at" => $at->copy()->subHours(73), "status" => "cancelled", "status_reason" => "expired", "cancelled_at" => $at], []);
  if (!DB::table("order_status_logs")->where("order_id", $o->id)->where("to_status", "cancelled")->exists())
    DB::table("order_status_logs")->insert(["order_id" => $o->id, "from_status" => "pending", "to_status" => "cancelled", "reason" => "expired", "actor_type" => "system", "actor_id" => null, "meta" => null, "created_at" => $at]);
  return $o;
};
$cancelled($hs[9], [$ca], 1);
Enrollment::where("user_id", $hs[9]->id)->where("course_id", $ca->id)->exists() || Enrollment::factory()->create(["user_id" => $hs[9]->id, "course_id" => $ca->id]);
$cancelled($hs[10], [$ca], 40);
$cancelled($hs[11], [$cb], 2);

// --- Đơn đã duyệt ---
$paid = function (User $s, array $courses, int $daysAgo, ?string $code = null) use ($mk, $admin) {
  $at = now()->subDays($daysAgo);
  $attrs = ["created_at" => $at->copy()->subHours(5), "status" => "paid", "status_reason" => "manual_confirmed", "paid_at" => $at, "confirmed_by" => $admin->id, "payment_reference" => "FT-FA8-".$s->id];
  if ($code) $attrs["code"] = $code;
  $o = $mk($s, $courses, $attrs, []);
  if (!DB::table("order_status_logs")->where("order_id", $o->id)->where("to_status", "paid")->exists())
    DB::table("order_status_logs")->insert(["order_id" => $o->id, "from_status" => "pending", "to_status" => "paid", "reason" => "manual_confirmed", "actor_type" => "staff", "actor_id" => $admin->id, "meta" => null, "created_at" => $at]);
  return $o;
};
$o12 = $paid($hs[12], [$cb], 3);
Enrollment::where("user_id", $hs[12]->id)->where("course_id", $cb->id)->exists() || Enrollment::factory()->create(["user_id" => $hs[12]->id, "course_id" => $cb->id, "order_id" => $o12->id]);

// Nhiều đơn đã duyệt của một học sinh (phân trang cursor); không ràng buộc "1 đơn chờ" vì đã paid.
$bulk = $stu("bulk", "Fa8 Bulk", 20);
if (Order::where("user_id", $bulk->id)->count() < 27) {
  for ($i = 1; $i <= 27; $i++) {
    $code = sprintf("VVFA8B%03d%s", $i, "Z");
    if (Order::where("code", $code)->exists()) continue;
    $at = now()->subHours(8 + $i);
    $o = Order::factory()->manual()->create(["code" => $code, "user_id" => $bulk->id, "status" => "paid", "status_reason" => "manual_confirmed", "subtotal_amount" => 100000, "total_amount" => 100000, "created_at" => $at->copy()->subHours(2), "expires_at" => $at->copy()->addHours(70), "paid_at" => $at, "confirmed_by" => $admin->id]);
    DB::table("order_items")->insert(["order_id" => $o->id, "course_id" => $ck->id, "course_title" => $ck->title, "unit_price" => 100000, "discount_amount" => 0, "final_amount" => 100000]);
    DB::table("order_status_logs")->insert(["order_id" => $o->id, "from_status" => null, "to_status" => "pending", "reason" => null, "actor_type" => "user", "actor_id" => $bulk->id, "meta" => null, "created_at" => $at->copy()->subHours(2)]);
  }
}
echo "seed ok: ", User::where(fn ($q) => $q->where("email", "like", "fa8-%")->orWhere("email", "like", "e2e-fa8-%"))->count(), " tai khoan; don=", Order::whereIn("user_id", User::where("email", "like", "fa8-%")->pluck("id"))->count(), " (cho duyet=", Order::whereIn("user_id", User::where("email", "like", "fa8-%")->pluck("id"))->where("status", "pending")->count(), ")";'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then echo "Nhắc: chạy xong nhớ dọn bằng: $0 --clean" >&2; fi
