#!/usr/bin/env bash
# Chuẩn bị dữ liệu cho e2e/chuyen-de-real.spec.ts (idempotent, chạy trên máy host, cần Docker local đang chạy):
#   frontend/apps/admin/e2e/seed-e2e-subjects.sh          # tạo chuyên đề "E2E Đang gán" + 1 khóa gán (nếu chưa có)
#   frontend/apps/admin/e2e/seed-e2e-subjects.sh --clean  # dọn chuyên đề rác tiền tố "E2E CD " (spec tự dọn, đây là dự phòng)
# Tài khoản e2e-qlt/e2e-gv @example.com đã được seed từ QA T28 (xem admin-real.spec.ts).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

if [ "${1:-}" = "--clean" ]; then
  PHP='echo App\Models\Subject::where("name","like","E2E CD %")->delete();'
else
  PHP='$s=App\Models\Subject::where("slug","e2e-dang-gan")->first() ?? App\Models\Subject::factory()->create(["name"=>"E2E Đang gán","slug"=>"e2e-dang-gan"]); if($s->status->value!=="active"){$s->update(["status"=>"active"]);} if($s->courses()->withTrashed()->count()==0){$c=App\Models\Course::factory()->create(); $c->subjects()->attach($s->id);} echo "e2e-dang-gan id=".$s->id." courses=".$s->courses()->withTrashed()->count();'
fi
docker compose exec -T php php artisan tinker --execute="$PHP"
echo
