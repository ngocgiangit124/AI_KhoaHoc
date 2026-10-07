#!/usr/bin/env bash
# Dữ liệu cho e2e/hoc-video-real.spec.ts (FW4) — idempotent, chạy trên máy host, cần Docker local (infra) đang chạy
# và VIDEO_PROVIDER=internal (VideoLab + worker-video có ffmpeg):
#   frontend/apps/web/e2e/seed-e2e-learn.sh          # tạo (hoặc tạo lại) dữ liệu tiền tố "e2e-fw4-"
#   frontend/apps/web/e2e/seed-e2e-learn.sh --clean  # dọn sạch (khóa, video VideoLab, học sinh fw4-*)
# Học sinh (mật khẩu matkhau-123, đã xác thực): fw4-hs-own@example.com (đã ghi danh khóa A), fw4-hs-none@example.com (chưa ghi danh).
# Khóa "e2e-fw4-hoc-video": bài 1 (video 24 giây, ready), bài 2 (video 24 giây, ready), bài 3 (link YouTube), bài 4 (chưa có video).
# In ra: course=<id> l1=<id> l2=<id> l3=<id> l4=<id>
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
STORAGE="$ROOT/backend/storage/app/videolab"

CLEAN='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Enrollment,VideoAsset};
use App\VideoLab\Models\Video;
foreach (Course::withTrashed()->where("slug","like","e2e-fw4-%")->get() as $c) {
  Enrollment::where("course_id",$c->id)->delete();
  foreach ($c->lessons()->withTrashed()->get() as $l) {
    \DB::table("lesson_progress")->where("lesson_id",$l->id)->delete();
    $l->forceFill(["video_asset_id"=>null])->save();
    foreach (VideoAsset::where("lesson_id",$l->id)->get() as $a) { Video::where("guid",$a->provider_video_id)->delete(); $a->delete(); }
    $l->forceDelete();
  }
  foreach ($c->chapters()->withTrashed()->get() as $ch) { $ch->forceDelete(); }
  $c->forceDelete();
}
foreach (User::where("email","like","fw4-%@example.com")->get() as $u) { Enrollment::where("user_id",$u->id)->delete(); $u->delete(); }
'

docker compose exec -T php php artisan tinker --execute="$CLEAN"
if [ "${1:-}" = "--clean" ]; then echo "clean ok"; exit 0; fi

# 1) Hai video nguồn 24 giây (640x360, có âm thanh) tạo bằng ffmpeg trong container worker-video.
GUIDS=()
for i in 1 2; do
  G="$(uuidgen | tr 'A-Z' 'a-z')"
  GUIDS+=("$G")
  docker exec vitaminvui-worker-video-1 ffmpeg -y -loglevel error -f lavfi -i "testsrc=duration=24:size=640x360:rate=25" \
    -f lavfi -i "sine=frequency=440:duration=24" -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest -f mp4 \
    "/var/www/backend/storage/app/videolab/source/$G.bin"
done

# 2) Bản ghi VideoLab (UPLOADED) + khóa/chương/bài + học sinh; đẩy job transcode sang queue `video`.
SEED="
if (! app()->environment(['local','testing'])) { throw new RuntimeException('Chỉ chạy ở local/testing'); }
use App\Models\{Course,Chapter,Lesson,User,Enrollment,VideoAsset};
use App\Enums\{VideoAssetStatus};
use App\VideoLab\Models\Video;
use App\VideoLab\Jobs\TranscodeVideoJob;
use Illuminate\Support\Facades\Hash;
\$gv = User::factory()->teacher()->verified()->create([\"name\"=>\"E2E FW4 GV\",\"email\"=>\"fw4-gv@example.com\"]);
\$c = Course::factory()->published()->create([\"title\"=>\"E2E FW4 Học video\",\"slug\"=>\"e2e-fw4-hoc-video\",\"grade_level\"=>9,\"price\"=>0,\"created_by\"=>\$gv->id,\"enrollments_count\"=>0]);
\$ch = Chapter::factory()->create([\"course_id\"=>\$c->id,\"title\"=>\"Chương 1\",\"position\"=>1]);
\$guids = [\"${GUIDS[0]}\",\"${GUIDS[1]}\"];
\$ids = [];
foreach ([1,2] as \$n) {
  \$l = Lesson::factory()->create([\"chapter_id\"=>\$ch->id,\"course_id\"=>\$c->id,\"title\"=>\"Bài \$n video thật\",\"position\"=>\$n,\"duration_seconds\"=>24]);
  \$g = \$guids[\$n-1];
  \$v = Video::factory()->create([\"guid\"=>\$g,\"title\"=>\"e2e-fw4-\$n\",\"status\"=>Video::UPLOADED]);
  \$v->forceFill([\"upload_length\"=>1,\"upload_offset\"=>1])->save();
  TranscodeVideoJob::dispatch(\$g);
  \$ids[\$n] = \$l->id;
}
\$l3 = Lesson::factory()->external()->create([\"chapter_id\"=>\$ch->id,\"course_id\"=>\$c->id,\"title\"=>\"Bài 3 link ngoài\",\"position\"=>3,\"duration_seconds\"=>60]);
\$l4 = Lesson::factory()->create([\"chapter_id\"=>\$ch->id,\"course_id\"=>\$c->id,\"title\"=>\"Bài 4 chưa có video\",\"position\"=>4]);
\$pw = Hash::make(\"matkhau-123\");
\$own = User::factory()->student()->verified()->create([\"name\"=>\"FW4 HS Sở hữu\",\"email\"=>\"fw4-hs-own@example.com\",\"password\"=>\$pw,\"grade_level\"=>9]);
User::factory()->student()->verified()->create([\"name\"=>\"FW4 HS Chưa ghi danh\",\"email\"=>\"fw4-hs-none@example.com\",\"password\"=>\$pw,\"grade_level\"=>9]);
Enrollment::factory()->create([\"user_id\"=>\$own->id,\"course_id\"=>\$c->id]);
file_put_contents(\"/tmp/fw4-seed.json\", json_encode([\"course\"=>\$c->id,\"l1\"=>\$ids[1],\"l2\"=>\$ids[2],\"l3\"=>\$l3->id,\"l4\"=>\$l4->id,\"g1\"=>\$guids[0],\"g2\"=>\$guids[1]]));
echo \"seed ok\";
"
docker compose exec -T php php artisan tinker --execute="$SEED"
docker compose exec -T php cat /tmp/fw4-seed.json > /tmp/fw4-seed.json
echo

# 3) Chờ worker transcode xong (tối đa ~3 phút), rồi gắn asset ready vào bài.
FINISH='
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Lesson,VideoAsset,User};
use App\Enums\VideoAssetStatus;
use App\VideoLab\Models\Video;
$s = json_decode(file_get_contents("/tmp/fw4-seed.json"), true);
foreach ([["l1","g1"],["l2","g2"]] as [$lk,$gk]) {
  $v = null;
  for ($i = 0; $i < 90; $i++) { $v = Video::where("guid",$s[$gk])->first(); if ($v && $v->status >= Video::FINISHED) break; sleep(2); }
  if (!$v || $v->status !== Video::FINISHED) { echo "TRANSCODE FAIL ".$s[$gk]." status=".($v->status ?? "null")." ".($v->error ?? ""); exit(1); }
  $l = Lesson::findOrFail($s[$lk]);
  $a = new VideoAsset();
  $a->forceFill(["provider"=>"internal","provider_library_id"=>(string)config("video.library_id"),"provider_video_id"=>$s[$gk],"status"=>VideoAssetStatus::Ready,"lesson_id"=>$l->id,"declared_size_bytes"=>1000000,"created_by"=>User::where("email","fw4-gv@example.com")->value("id"),"duration_seconds"=>$v->length_seconds])->save();
  $l->forceFill(["video_asset_id"=>$a->id,"video_source"=>"upload","duration_seconds"=>$v->length_seconds])->save();
}
echo "finish ok";
'
docker compose exec -T php php artisan tinker --execute="$FINISH"
echo
python3 - <<'PY'
import json
s=json.load(open("/tmp/fw4-seed.json"))
print("course=%d l1=%d l2=%d l3=%d l4=%d" % (s["course"],s["l1"],s["l2"],s["l3"],s["l4"]))
PY
