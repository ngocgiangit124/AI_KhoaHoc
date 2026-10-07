#!/usr/bin/env bash
# Dữ liệu cho e2e/home-real.spec.ts (FW8 trang chủ + FW9 khu giáo viên) — chỉ chạy ở APP_ENV local/testing, cần Docker local (infra):
#   frontend/apps/web/e2e/seed-e2e-home.sh            # tạo (chưa có thì tạo) dữ liệu tiền tố "e2e-fw8-"
#   frontend/apps/web/e2e/seed-e2e-home.sh --reset    # dọn rồi tạo lại (dùng trước MỖI lần chạy spec)
#   frontend/apps/web/e2e/seed-e2e-home.sh --clean    # dọn sạch: khóa, giáo viên, hồ sơ, đồng ý "e2e-fw8-*"
# Khóa nổi bật (sort=featured, manual_order 1..4, đứng trước mọi khóa khác): e2e-fw8-khoa-1 (lớp 9, miễn phí), -2 (lớp 10, 299.000đ),
#   -3 (lớp 8), -4 (lớp 9); thêm e2e-fw8-khoa-ngung-ban (unpublished, manual_order 1 -> KHÔNG được hiện).
# Giáo viên (mật khẩu không dùng, không đăng nhập): gv1 tên 150 ký tự (bật #1), gv2 (#2), gv3 bio chứa HTML/script (#3), gv7 (#4) -> hiện;
#   gv4 chưa đồng ý, gv5 khóa duy nhất bị ngừng bán, gv6 bị khoá tài khoản -> KHÔNG hiện dù bật trang chủ.
# Từ chối nếu ngoài e2e-fw8 còn hồ sơ đang bật trang chủ (kết quả sẽ không xác định). Ảnh đại diện trỏ tới file không tồn tại (404) -> thẻ phải rơi về chữ cái đầu.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\DB;
$users = App\Models\User::where("email", "like", "e2e-fw8-%")->pluck("id");
$ids = App\Models\Course::withTrashed()->where("slug", "like", "e2e-fw8-%")->pluck("id");
DB::transaction(function () use ($users, $ids) {
  DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
  DB::table("course_subject")->whereIn("course_id", $ids)->delete();
  App\Models\Lesson::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Chapter::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  DB::table("consents")->whereIn("user_id", $users)->delete();
  DB::table("teacher_profiles")->whereIn("user_id", $users)->delete();
  DB::table("course_teacher")->whereIn("user_id", $users)->delete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan, ", count($ids), " khoa";'

SEED_PHP='
use App\Models\{Course, TeacherProfile, User};
$others = TeacherProfile::where("show_on_homepage", true)->whereNotIn("user_id", User::where("email", "like", "e2e-fw8-%")->pluck("id"))->count();
if ($others > 0) { echo "TU CHOI: con ", $others, " ho so ngoai e2e-fw8 dang bat trang chu"; return; }
$mk = fn ($n, $name, $status = null) => User::where("email", "e2e-fw8-$n@example.com")->first()
  ?? User::factory()->teacher()->create(["email" => "e2e-fw8-$n@example.com", "name" => $name]);
$long = "E2E FW8 ".trim(str_repeat("Nguyễn Thị Hoàng Phương Thảo ", 5));
$gv1 = $mk("gv1", mb_substr($long, 0, 150));
$gv2 = $mk("gv2", "E2E FW8 Cô Hoa");
$gv3 = $mk("gv3", "E2E FW8 Thầy Nam");
$gv4 = $mk("gv4", "E2E FW8 Chưa đồng ý");
$gv5 = $mk("gv5", "E2E FW8 Ngừng bán");
$gv6 = $mk("gv6", "E2E FW8 Bị khoá");
$gv7 = $mk("gv7", "E2E FW8 Cô Mai");
$gv6->forceFill(["status" => App\Enums\UserStatus::Locked])->save();
$prof = fn (User $u, int $order, array $extra = [], bool $consent = true) => TeacherProfile::where("user_id", $u->id)->exists()
  ? null
  : ($consent ? TeacherProfile::factory()->withContent()->consented() : TeacherProfile::factory()->withContent())->onHomepage($order)->create(array_merge(["user_id" => $u->id], $extra));
$prof($gv1, 1, ["headline" => "Giáo viên Toán THPT chuyên, luyện thi vào lớp 10", "bio" => "Dòng một của phần giới thiệu.\nDòng hai xuống dòng.\nDòng ba.\nDòng bốn bị cắt khi quá ba dòng.\nDòng năm."]);
$prof($gv2, 2, ["headline" => null, "bio" => "Giảng chậm, rõ từng bước."]);
$prof($gv3, 3, ["headline" => "Hình học", "bio" => "<script>window.__fw8xss=1</script>\n<b>không đậm</b> <img src=x onerror=\"window.__fw8xss=1\">"]);
$prof($gv7, 4, ["headline" => "Đại số và giải tích", "bio" => "Cô Mai dạy lớp 12."]);
$prof($gv4, 5, [], false);
$prof($gv5, 6); $prof($gv6, 7);
$mkc = function (string $slug, string $title, int $grade, int $price, $order, array $teachers, bool $published = true) use ($gv2) {
  if (Course::withTrashed()->where("slug", $slug)->exists()) { return; }
  $f = Course::factory(); $f = $published ? $f->published() : $f->unpublished();
  $c = $f->create(["title" => $title, "slug" => $slug, "grade_level" => $grade, "price" => $price, "manual_order" => $order, "short_description" => "Mô tả ngắn: $title", "enrollments_count" => 0, "created_by" => $gv2->id]);
  foreach ($teachers as $t) { $c->teachers()->attach($t->id, ["added_by" => $gv2->id]); }
};
$mkc("e2e-fw8-khoa-1", "E2E FW8 Khóa nổi bật 1", 9, 0, 1, [$gv1, $gv2]);
$mkc("e2e-fw8-khoa-2", "E2E FW8 Khóa nổi bật 2 trả phí", 10, 299000, 2, [$gv1]);
$mkc("e2e-fw8-khoa-3", "E2E FW8 Khóa nổi bật 3", 8, 0, 3, [$gv2]);
$mkc("e2e-fw8-khoa-4", "E2E FW8 Khóa nổi bật 4", 9, 0, 4, [$gv3]);
$mkc("e2e-fw8-khoa-ngung-ban", "E2E FW8 Khóa đã ngừng bán", 9, 0, 1, [$gv1], false);
$mkc("e2e-fw8-khoa-gv7", "E2E FW8 Khóa của cô Mai", 12, 0, null, [$gv7]);
$mkc("e2e-fw8-khoa-gv4", "E2E FW8 Khóa của giáo viên chưa đồng ý", 7, 0, null, [$gv4]);
$mkc("e2e-fw8-khoa-gv5", "E2E FW8 Khóa ngừng bán của gv5", 7, 0, null, [$gv5], false);
$mkc("e2e-fw8-khoa-gv6", "E2E FW8 Khóa của giáo viên bị khoá", 7, 0, null, [$gv6]);
echo "seed ok: ", User::where("email", "like", "e2e-fw8-%")->count(), " tai khoan, ", Course::where("slug", "like", "e2e-fw8-%")->count(), " khoa";'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  OUT="$(docker compose exec -T php php artisan tinker --execute="$SEED_PHP")"
  echo "$OUT"
  case "$OUT" in *"TU CHOI"*) exit 1 ;; esac
fi
