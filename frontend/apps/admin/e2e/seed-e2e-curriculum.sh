#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/chuong-bai-real.spec.ts (FA4; idempotent, chạy trên máy host, cần Docker local đang chạy):
#   frontend/apps/admin/e2e/seed-e2e-curriculum.sh          # tạo tài khoản + khóa "E2E FA4 ..." (chưa có thì tạo)
#   frontend/apps/admin/e2e/seed-e2e-curriculum.sh --reset  # đưa các khóa "E2E FA4 ..." về đúng dữ liệu ban đầu (xoá chương/bài/video thêm trong lúc chạy spec)
#   frontend/apps/admin/e2e/seed-e2e-curriculum.sh --clean  # xoá MỌI thứ "E2E FA4" (khóa, chương, bài, video ở VideoLab, tài khoản)
# Tài khoản riêng cho FA4 (mật khẩu `Password123!`): e2e-fa4-gv (giáo viên được gán), e2e-fa4-gv2 (giáo viên không được gán),
# e2e-fa4-qlt1..2 (quản lý trang; đăng nhập MFA bị giới hạn OTP nên có 2 tài khoản).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

WIPE='
use App\Models\{Course, Lesson, Chapter, VideoAsset};
$wipe = function () {
  $ids = Course::withTrashed()->where("title", "like", "E2E FA4 %")->pluck("id");
  $lessonIds = Lesson::withTrashed()->whereIn("course_id", $ids)->pluck("id");
  $mgr = app(App\Services\Video\VideoProviderManager::class);
  foreach (VideoAsset::whereIn("lesson_id", $lessonIds)->get() as $a) {
    try { if (! str_starts_with($a->provider_video_id, App\Services\Video\VideoUploadService::PENDING_PREFIX)) { $mgr->driver($a->provider)->deleteVideo($a->provider_video_id); } } catch (Throwable $e) { echo "bo qua video ", $a->id, ": ", $e->getMessage(), "\n"; }
  }
  Illuminate\Support\Facades\DB::transaction(function () use ($ids, $lessonIds) {
    Illuminate\Support\Facades\DB::table("lessons")->whereIn("id", $lessonIds)->update(["video_asset_id" => null]);
    VideoAsset::whereIn("lesson_id", $lessonIds)->delete();
    Lesson::withTrashed()->whereIn("course_id", $ids)->forceDelete();
    Chapter::withTrashed()->whereIn("course_id", $ids)->forceDelete();
    Illuminate\Support\Facades\DB::table("course_teacher")->whereIn("course_id", $ids)->delete();
    Illuminate\Support\Facades\DB::table("course_subject")->whereIn("course_id", $ids)->delete();
    Course::withTrashed()->whereIn("id", $ids)->forceDelete();
  });
  return $ids->count();
};
'

SEED='
use App\Models\{Course, Subject, User, Chapter, Lesson};
$mkUser = function ($email, $name, $kind) {
  $u = User::where("email", $email)->first();
  if ($u) return $u;
  $f = User::factory();
  $f = $kind === "teacher" ? $f->teacher() : $f->pageManager();
  return $f->create(["email" => $email, "name" => $name, "password" => Illuminate\Support\Facades\Hash::make("Password123!")]);
};
$gv = $mkUser("e2e-fa4-gv@example.com", "E2E FA4 GV Chinh", "teacher");
$gv2 = $mkUser("e2e-fa4-gv2@example.com", "E2E FA4 GV Hai", "teacher");
$mkUser("e2e-fa4-qlt1@example.com", "E2E FA4 QLT 1", "qlt");
$mkUser("e2e-fa4-qlt2@example.com", "E2E FA4 QLT 2", "qlt");
$subject = Subject::where("name", "E2E FA4 Chuyên đề")->first() ?? Subject::factory()->create(["name" => "E2E FA4 Chuyên đề", "slug" => "e2e-fa4-chuyen-de", "status" => "active"]);
$course = function ($title, $teacher, $chapters) use ($subject) {
  $c = Course::factory()->create(["title" => $title, "grade_level" => 9, "price" => 100000, "created_by" => $teacher->id]);
  $c->teachers()->attach([$teacher->id]); $c->subjects()->attach([$subject->id]);
  foreach ($chapters as $i => [$ct, $lessons]) {
    $ch = Chapter::factory()->create(["course_id" => $c->id, "title" => $ct, "position" => $i + 1]);
    foreach ($lessons as $j => $lt) { Lesson::factory()->create(["chapter_id" => $ch->id, "title" => $lt, "position" => $j + 1]); }
  }
  return $c;
};
$course("E2E FA4 Khóa dựng cây", $gv, [["E2E FA4 Chương A", ["E2E FA4 Bài A1", "E2E FA4 Bài A2", "E2E FA4 Bài A3"]], ["E2E FA4 Chương B", []]]);
$course("E2E FA4 Khóa video", $gv, [["E2E FA4 Chương V", ["E2E FA4 V1", "E2E FA4 V2", "E2E FA4 V3", "E2E FA4 V4", "E2E FA4 V5", "E2E FA4 V6"]]]);
$course("E2E FA4 Của GV khác", $gv2, [["E2E FA4 Chương X", ["E2E FA4 Bài X1"]]]);
echo "seed ok: ", Course::where("title", "like", "E2E FA4 %")->count(), " khoa";
'

run() { docker compose exec -T php php artisan tinker --execute="$1"; echo; }
case "${1:-}" in
  --clean)
    run "$WIPE"'
echo "da don ", $wipe(), " khoa";
App\Models\Subject::where("name", "like", "E2E FA4 %")->delete();
App\Models\User::where("email", "like", "e2e-fa4-%")->delete();'
    ;;
  --reset)
    run "$WIPE"'$wipe();'
    run "$SEED"
    ;;
  *)
    if [ "$(docker compose exec -T php php artisan tinker --execute='echo App\Models\Course::where("title", "like", "E2E FA4 %")->count();' | tr -d '\r\n ')" = "0" ]; then run "$SEED"; else echo "da co du lieu E2E FA4 (dung --reset de dua ve ban dau)"; fi
    ;;
esac
