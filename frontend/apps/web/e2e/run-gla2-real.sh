#!/usr/bin/env bash
# e2e THẬT GL-A2 của web trong ảnh Playwright, KHÔNG dựng server (dùng dev server :3000 + backend :8000 trên host). Cần Docker Desktop và Internet (script Turnstile).
#   frontend/apps/web/e2e/seed-e2e-gla2.sh --reset   # trước MỖI lần chạy
#   frontend/apps/web/e2e/run-gla2-real.sh
#   frontend/apps/web/e2e/seed-e2e-gla2.sh --clean   # sau cùng
set -euo pipefail
WEB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$(cd "$WEB_DIR/../.." && pwd)"
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 \
  --add-host api.localhost:127.0.0.1 \
  -v "$FRONTEND_DIR:/workspace" -w /workspace/apps/web mcr.microsoft.com/playwright:v1.63.0-noble \
  bash -c '
    for p in 8000 3000; do
      node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect($p,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen($p,\"127.0.0.1\")" &
    done
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.gla2.config.ts --workers=1 --trace=off "$@"
  ' playwright "$@"
