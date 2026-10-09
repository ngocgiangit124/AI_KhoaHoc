#!/usr/bin/env bash
# QA FW3-1: chạy SAU seed-e2e-fw3.sh --reset. Thêm: mật khẩu cho giáo viên fw3-gv, học sinh fw3-hs-big (sở hữu anh + 31 khóa lấp chỗ, anh cũ nhất).
# Dọn: seed-e2e-fw3.sh --clean (khóa slug e2e-fw3-*, học sinh fw3-*).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"
SEED="$(cat <<'PHP'
if (! app()->environment(["local","testing"])) { throw new RuntimeException("Chỉ chạy ở local/testing"); }
use App\Models\{Course,User,Enrollment};
use Illuminate\Support\Facades\Hash;
$gv = User::where("email","fw3-gv@example.com")->firstOrFail();
$gv->forceFill(["password"=>Hash::make("matkhau-123")])->save();
$anh = Course::where("slug","e2e-fw3-anh")->firstOrFail();
$big = User::factory()->student()->verified()->create(["name"=>"E2E FW3 big","email"=>"fw3-hs-big@example.com","password"=>Hash::make("matkhau-123"),"grade_level"=>9,"phone"=>"09".random_int(10000000,99999999)]);
Enrollment::factory()->create(["user_id"=>$big->id,"course_id"=>$anh->id,"created_at"=>now()->subDays(60)]);
for ($i = 1; $i <= 31; $i++) {
  $c = Course::factory()->published()->create(["title"=>"Lấp chỗ $i","slug"=>"e2e-fw3-fill-$i","grade_level"=>12,"price"=>100000,"created_by"=>$gv->id,"enrollments_count"=>0]);
  Enrollment::factory()->create(["user_id"=>$big->id,"course_id"=>$c->id,"created_at"=>now()->subDays(30)->addMinutes($i)]);
}
echo "QA SEED ok";
PHP
)"
docker compose exec -T php php artisan tinker --execute="$SEED"
echo
