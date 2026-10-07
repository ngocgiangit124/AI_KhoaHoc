#!/usr/bin/env bash
# e2e QA FW8/FW9 trên bản build production. Trước: seed-e2e-home.sh --reset; build: NEXT_DIST_DIR=.next-qa next build; điều khiển dữ liệu: qa-fw8-control.py chạy trên host.
set -euo pipefail
WEB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$(cd "$WEB_DIR/../.." && pwd)"
ROOT="$(cd "$FRONTEND_DIR/.." && pwd)"
mkdir -p "$ROOT/backend/storage/app/uploads"
docker run --rm \
  --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 -e FW8_QA=1 -e NEXT_TELEMETRY_DISABLED=1 -e UPLOADS_DIR=/uploads -e QA_SHOTS="${QA_SHOTS:-}" \
  --add-host api.localhost:127.0.0.1 \
  -v "$FRONTEND_DIR:/workspace" -v "$ROOT/backend/storage/app/uploads:/uploads:ro" -w /workspace/apps/web mcr.microsoft.com/playwright:v1.63.0-noble \
  bash -c '
    node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect(8000,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen(8000,\"127.0.0.1\")" &
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.fw8qa.config.ts --workers=1 "$@"
  ' playwright "$@"
