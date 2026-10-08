#!/usr/bin/env bash
# Chạy e2e THẬT của SLN8 (chi tiết khóa -> trang học; chỉ spec e2e/sln8-real.spec.ts) trong ảnh Playwright (macOS + Docker Desktop). Không đụng dev server cổng 3000 của host.
#   frontend/apps/web/e2e/seed-e2e-sln8.sh --reset      # trước mỗi lần chạy (in ra course=.. l1=.. l2=..)
#   E2E_SLN8="course=.. l1=.. l2=.." frontend/apps/web/e2e/run-sln8-real.sh --trace=off
#   frontend/apps/web/e2e/seed-e2e-sln8.sh --clean      # sau khi xong
# Trong container: chuyển tiếp 127.0.0.1:8000 -> host.docker.internal:8000 (backend/Nginx); Next dev chạy ngay trong container.
set -euo pipefail
WEB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$(cd "$WEB_DIR/../.." && pwd)"
IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble"
[ "${E2E_REUSE_BUILD:-}" = "1" ] || rm -rf "$WEB_DIR/.next-e2e-sln8"
docker run --rm \
  --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 -e NEXT_TELEMETRY_DISABLED=1 \
  -e E2E_SLN8="${E2E_SLN8:-}" -e E2E_DEBUG="${E2E_DEBUG:-}" -e E2E_REUSE_BUILD="${E2E_REUSE_BUILD:-}" \
  --add-host api.localhost:127.0.0.1 \
  -v "$FRONTEND_DIR:/workspace" -w /workspace/apps/web "$IMAGE" \
  bash -c '
    node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect(8000,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen(8000,\"127.0.0.1\")" &
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.sln8.config.ts --workers=1 "$@"
  ' playwright "$@"
