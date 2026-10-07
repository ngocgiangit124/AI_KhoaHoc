#!/usr/bin/env bash
# QA FA6: watcher ở host — khi spec ghi test-results/fa6-delete.req thì xoá mềm khóa "E2E FA6 QA Deleted" qua DB (API chặn xoá khóa có đăng ký). Dừng bằng Ctrl-C/kill.
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(cd ../../.. && pwd)"
mkdir -p test-results; rm -f test-results/fa6-delete.req test-results/fa6-delete.ack
while true; do
  if [ -f test-results/fa6-delete.req ]; then
    (cd "$ROOT/infra" && docker compose exec -T php php artisan tinker --execute='App\Models\Course::where("title","E2E FA6 QA Deleted")->delete(); echo "deleted";')
    rm -f test-results/fa6-delete.req; touch test-results/fa6-delete.ack
  fi
  sleep 0.5
done
