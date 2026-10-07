#!/usr/bin/env bash
# Chạy e2e THẬT của apps/admin trong ảnh Playwright, KHÔNG dựng dev server mới (dùng admin :3001 + backend :8000 đang chạy ở host).
#   frontend/apps/admin/e2e/run-real.sh e2e/chuong-bai-real.spec.ts
# Cần Docker Desktop (macOS): chuyển tiếp 127.0.0.1:{8000,3001} trong container tới host.docker.internal; Mailpit ở :8025.
set -euo pipefail
ADMIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$(cd "$ADMIN_DIR/../.." && pwd)"
IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble"
docker run --rm \
  --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 \
  -e MAILPIT_URL="${MAILPIT_URL:-http://host.docker.internal:8025}" \
  --add-host api.localhost:127.0.0.1 --add-host admin-api.localhost:127.0.0.1 --add-host video.localhost:127.0.0.1 \
  -v "$FRONTEND_DIR:/workspace" -w /workspace/apps/admin "$IMAGE" \
  bash -c '
    for p in 8000 3001; do
      node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect($p,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen($p,\"127.0.0.1\")" &
    done
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.real.config.ts "$@"
  ' playwright "$@"
