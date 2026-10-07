#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/khoa-hoc-real.spec.ts (idempotent, chạy trên máy host, cần Docker local đang chạy):
#   frontend/apps/admin/e2e/seed-e2e-courses.sh          # tạo dữ liệu "E2E FA3 ..." (chưa có thì tạo)
#   frontend/apps/admin/e2e/seed-e2e-courses.sh --clean  # xoá MỌI khóa/chuyên đề/tài khoản "E2E FA3" (kể cả do spec tạo) + file ảnh tải lên của chúng
# Dùng sẵn tài khoản e2e-qlt / e2e-gv @example.com (mật khẩu `Password123!`, seed từ QA T28).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

if [ "${1:-}" = "--clean" ]; then
  # Chỉ dọn ở môi trường local/testing (forceDelete theo tiền tố tên).
  ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
  case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối --clean: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac
  PHP='
$ids = App\Models\Course::withTrashed()->where("title", "like", "E2E FA3 %")->pluck("id");
$files = App\Models\Course::withTrashed()->whereIn("id", $ids)->whereNotNull("thumbnail_path")->pluck("thumbnail_path");
foreach ($files as $f) { Illuminate\Support\Facades\Storage::disk("uploads")->delete($f); }
Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
  App\Models\Enrollment::whereIn("course_id", $ids)->delete();
  App\Models\Lesson::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  App\Models\Chapter::withTrashed()->whereIn("course_id", $ids)->forceDelete();
  Illuminate\Support\Facades\DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
  Illuminate\Support\Facades\DB::table("course_subject")->whereIn("course_id", $ids)->delete();
  App\Models\Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  App\Models\Subject::where("name", "like", "E2E FA3 %")->delete();
  App\Models\User::where("email", "like", "e2e-fa3-%")->delete();
});
echo "da don ", count($ids), " khoa, ", count($files), " file anh";'
else
  PHP='
use App\Models\{Course, Subject, User, Chapter, Lesson, Enrollment};
$mk = fn ($name, $status = "active") => Subject::where("name", $name)->first() ?? Subject::factory()->create(["name" => $name, "slug" => str($name)->slug()->toString(), "status" => $status]);
$s1 = $mk("E2E FA3 Đại số"); $s2 = $mk("E2E FA3 Hình học"); $s3 = $mk("E2E FA3 Đã ẩn", "hidden");
$gv = User::where("email", "e2e-gv@example.com")->firstOrFail();
$gv2 = User::where("email", "e2e-fa3-gv2@example.com")->first() ?? User::factory()->teacher()->create(["email" => "e2e-fa3-gv2@example.com", "name" => "E2E FA3 GV Hai"]);
$gv3 = User::where("email", "e2e-fa3-gv3@example.com")->first() ?? User::factory()->teacher()->create(["email" => "e2e-fa3-gv3@example.com", "name" => "E2E FA3 GV Ba"]);
$locked = User::where("email", "e2e-fa3-gv-khoa@example.com")->first() ?? User::factory()->teacher()->create(["email" => "e2e-fa3-gv-khoa@example.com", "name" => "E2E FA3 GV Khoa"]);
$locked->forceFill(["status" => App\Enums\UserStatus::Locked])->save();
foreach ([1, 2, 3] as $i) { // QLT riêng cho spec: đăng nhập MFA bị giới hạn OTP theo tài khoản, dùng FA3_STAFF=fa3-qlt{i}
  if (! User::where("email", "e2e-fa3-qlt$i@example.com")->exists()) {
    User::factory()->pageManager()->create(["email" => "e2e-fa3-qlt$i@example.com", "name" => "E2E FA3 QLT $i", "password" => Illuminate\Support\Facades\Hash::make("Password123!")]);
  }
}
$hs = User::where("email", "e2e-fa3-hs@example.com")->first() ?? User::factory()->student()->create(["email" => "e2e-fa3-hs@example.com"]);
$course = function ($title, $state, $teachers, $subjects, $extra = []) {
  $c = Course::withTrashed()->where("title", $title)->first();
  if ($c) return $c;
  $f = $state === "published" ? Course::factory()->published() : Course::factory();
  $c = $f->create(array_merge(["title" => $title, "grade_level" => 8, "price" => 100000], $extra));
  $c->teachers()->attach($teachers); $c->subjects()->attach($subjects);
  return $c;
};
$a = $course("E2E FA3 Nháp trống", "draft", [$gv->id], [$s1->id], []);
$b = $course("E2E FA3 Có nội dung", "draft", [$gv->id], [$s1->id], []);
if ($b->chapters()->count() == 0) { $ch = Chapter::factory()->create(["course_id" => $b->id]); Lesson::factory()->create(["chapter_id" => $ch->id]); }
$c = $course("E2E FA3 Đã xuất bản", "published", [$gv->id], [$s1->id], ["grade_level" => 9]);
if ($c->chapters()->count() == 0) { $ch2 = Chapter::factory()->create(["course_id" => $c->id]); Lesson::factory()->create(["chapter_id" => $ch2->id]); } // có nội dung để "Xuất bản lại" thành công
if ($c->enrollments()->count() == 0) { Enrollment::factory()->create(["user_id" => $hs->id, "course_id" => $c->id]); $c->forceFill(["enrollments_count" => 1])->save(); }
$d = $course("E2E FA3 Của GV khác", "draft", [$gv2->id], [$s2->id], []);
$e = $course("E2E FA3 Có GV bị khóa và chuyên đề ẩn", "draft", [$gv->id, $locked->id], [$s1->id, $s3->id], []);
echo "seed ok: ", Course::where("title", "like", "E2E FA3 %")->count(), " khoa; gv2=", $gv2->id, " locked=", $locked->id;'
fi
docker compose exec -T php php artisan tinker --execute="$PHP"
echo
