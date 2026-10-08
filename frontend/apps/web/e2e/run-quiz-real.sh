#!/usr/bin/env bash
# Chạy e2e THẬT của làm quiz (FW5) trong ảnh Playwright (macOS + Docker Desktop). Không đụng dev server cổng 3000 của host.
#   frontend/apps/web/e2e/seed-e2e-quiz.sh --reset      # trước MỖI lần chạy (in ra course=.. l1=.. q1=..)
#   E2E_FW5="course=.. l1=.. q1=.. q2=.. q3=.. q4=.." frontend/apps/web/e2e/run-quiz-real.sh [đối số playwright, ví dụ --grep "375"]
#   frontend/apps/web/e2e/seed-e2e-quiz.sh --clean      # sau khi xong
# Trong container: chuyển tiếp 127.0.0.1:8000 -> host.docker.internal:8000 (backend/Nginx); Next dev chạy ngay trong container.
set -euo pipefail
WEB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$(cd "$WEB_DIR/../.." && pwd)"
IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble"
[ "${E2E_REUSE_BUILD:-}" = "1" ] || rm -rf "$WEB_DIR/.next-e2e-fw5"
docker run --rm \
  --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 -e NEXT_TELEMETRY_DISABLED=1 \
  -e E2E_FW5="${E2E_FW5:-}" -e E2E_DEBUG="${E2E_DEBUG:-}" -e E2E_REUSE_BUILD="${E2E_REUSE_BUILD:-}" \
  --add-host api.localhost:127.0.0.1 \
  -v "$FRONTEND_DIR:/workspace" -w /workspace/apps/web "$IMAGE" \
  bash -c '
    node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect(8000,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen(8000,\"127.0.0.1\")" &
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.fw5.config.ts --workers=1 "$@"
  ' playwright "$@"
