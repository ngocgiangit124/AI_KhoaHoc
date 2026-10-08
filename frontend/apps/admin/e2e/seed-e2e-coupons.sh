#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/ma-giam-gia-real.spec.ts (FA7, US-013). Idempotent, chạy trên máy host, cần Docker local đang chạy:
#   frontend/apps/admin/e2e/seed-e2e-coupons.sh            # tạo (chưa có thì tạo) tài khoản + khóa + chuyên đề + mã "E2E-FA7-..."
#   frontend/apps/admin/e2e/seed-e2e-coupons.sh --reset    # dọn rồi tạo lại từ đầu (dùng trước MỖI lần chạy spec: spec tạo/sửa/xoá mã)
#   frontend/apps/admin/e2e/seed-e2e-coupons.sh --clean    # dọn: xoá MỌI mã "E2E-FA7-%", tài khoản e2e-fa7-*, khóa/chuyên đề "E2E FA7 ..."
# Chỉ chạy ở APP_ENV local/testing (từ chối ở môi trường khác). Chỉ dùng tinker (không migrate/seed). Mật khẩu mọi tài khoản: `Password123!`.
# Trạng thái sau seed:
#   e2e-fa7-qlt1 Quản lý trang (đăng nhập MFA qua Mailpit)     e2e-fa7-gv1 giáo viên (đăng nhập thẳng; không được vào màn Mã giảm giá)
#   Khóa "E2E FA7 Khóa A" (500.000đ) và "E2E FA7 Khóa B" (300.000đ) đang bán, "E2E FA7 Khóa Nháp"; chuyên đề "E2E FA7 Chuyên đề" (active) và "E2E FA7 Ẩn" (hidden)
#   Mã: E2E-FA7-DANGDUNG (20%, chưa dùng, xoá được) · E2E-FA7-DADUNG (30%, đã dùng 3/10 lượt: khoá mã/loại/giá trị)
#       E2E-FA7-HETLUOT (hết lượt) · E2E-FA7-HETHAN (hết hạn) · E2E-FA7-SAPTOI (sắp diễn ra) · E2E-FA7-DATAT (đã vô hiệu hoá)
#       E2E-FA7-PHAMVI (phạm vi: Khóa A + chuyên đề "E2E FA7 Chuyên đề") · E2E-FA7-PAGE01..26 (để thử phân trang 25/trang)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\DB;
$users = App\Models\User::where("email", "like", "e2e-fa7-%")->pluck("id");
$ids = App\Models\Course::withTrashed()->where("title", "like", "E2E FA7 %")->pluck("id");
$subjects = App\Models\Subject::where("name", "like", "E2E FA7 %")->pluck("id");
DB::transaction(function () use ($users, $ids, $subjects) {
  $coupons = App\Models\Coupon::where("code", "like", "E2E-FA7-%")->orWhereIn("created_by", $users)->pluck("id");
  DB::table("coupon_course")->where(fn ($q) => $q->whereIn("coupon_id", $coupons)->orWhereIn("course_id", $ids))->delete();
  DB::table("coupon_subject")->where(fn ($q) => $q->whereIn("coupon_id", $coupons)->orWhereIn("subject_id", $subjects))->delete();
  App\Models\Coupon::whereIn("id", $coupons)->delete();
  DB::table("course_teacher")->where(fn ($q) => $q->whereIn("course_id", $ids)->orWhereIn("user_id", $users))->delete();
  DB::table("course_subject")->where(fn ($q) => $q->whereIn("course_id", $ids)->orWhereIn("subject_id", $subjects))->delete();
  App\Models\Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  App\Models\Subject::whereIn("id", $subjects)->delete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan, ", count($ids), " khoa, ", count($subjects), " chuyen de";'

SEED_PHP='
use App\Models\{Coupon, Course, Subject, User};
use App\Enums\CouponStatus;
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($e, $name, $state) => User::where("email", "e2e-fa7-$e@example.com")->first()
  ?? User::factory()->$state()->create(["email" => "e2e-fa7-$e@example.com", "name" => $name, "password" => $pw]);
$qlt = $mk("qlt1", "E2E FA7 QLT Một", "pageManager");
$gv = $mk("gv1", "E2E FA7 GV Một", "teacher");
$course = function (string $title, int $price, bool $published) use ($gv) {
  $c = Course::where("title", $title)->first() ?? ($published ? Course::factory()->published() : Course::factory())->create(["title" => $title, "grade_level" => 9, "price" => $price]);
  $c->teachers()->syncWithoutDetaching([$gv->id]);
  return $c;
};
$a = $course("E2E FA7 Khóa A", 500000, true);
$course("E2E FA7 Khóa B", 300000, true);
$course("E2E FA7 Khóa Nháp", 100000, false);
$sub = Subject::where("name", "E2E FA7 Chuyên đề")->first() ?? Subject::factory()->create(["name" => "E2E FA7 Chuyên đề", "slug" => "e2e-fa7-chuyen-de"]);
$hid = Subject::where("name", "E2E FA7 Ẩn")->first() ?? Subject::factory()->hidden()->create(["name" => "E2E FA7 Ẩn", "slug" => "e2e-fa7-an"]);
$mkc = function (string $code, string $name, callable $f) use ($qlt) {
  return Coupon::where("code", $code)->first() ?? $f(Coupon::factory())->create(["code" => $code, "name" => $name, "created_by" => $qlt->id]);
};
$mkc("E2E-FA7-DANGDUNG", "FA7 đang dùng", fn ($f) => $f);
$mkc("E2E-FA7-DADUNG", "FA7 đã dùng", fn ($f) => $f->percent(30)->state(["max_uses" => 10, "used_count" => 3]));
$mkc("E2E-FA7-HETLUOT", "FA7 hết lượt", fn ($f) => $f->exhausted(5));
$mkc("E2E-FA7-HETHAN", "FA7 hết hạn", fn ($f) => $f->expired());
$mkc("E2E-FA7-SAPTOI", "FA7 sắp diễn ra", fn ($f) => $f->upcoming());
$mkc("E2E-FA7-DATAT", "FA7 đã tắt", fn ($f) => $f->inactive());
$p = $mkc("E2E-FA7-PHAMVI", "FA7 phạm vi", fn ($f) => $f->restricted());
$p->courses()->syncWithoutDetaching([$a->id]);
$p->subjects()->syncWithoutDetaching([$sub->id]);
for ($n = 1; $n <= 26; $n++) $mkc(sprintf("E2E-FA7-PAGE%02d", $n), "FA7 trang $n", fn ($f) => $f);
echo "seed ok: ", User::where("email", "like", "e2e-fa7-%")->count(), " tai khoan; ma=", Coupon::where("code", "like", "E2E-FA7-%")->count();'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"
  echo
fi
