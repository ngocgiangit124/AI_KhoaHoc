#!/usr/bin/env bash
# Như run-real.sh nhưng gắn uploads của backend (chỉ đọc) tại /uploads để spec giả lập miền tĩnh STATIC_URL (QA FA11). QA_SHOTS=/workspace/apps/admin/qa-shots để lưu ảnh chụp.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble"
mkdir -p "$ROOT/backend/storage/app/uploads"
docker run --rm \
  --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 -e UPLOADS_DIR=/uploads -e QA_SHOTS="${QA_SHOTS:-}" \
  -e MAILPIT_URL="http://host.docker.internal:8025" \
  --add-host api.localhost:127.0.0.1 --add-host admin-api.localhost:127.0.0.1 --add-host video.localhost:127.0.0.1 \
  -v "$ROOT/frontend:/workspace" -v "$ROOT/backend/storage/app/uploads:/uploads:ro" -w /workspace/apps/admin "$IMAGE" \
  bash -c '
    for p in 8000 3001; do
      node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect($p,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen($p,\"127.0.0.1\")" &
    done
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.real.config.ts "$@"
  ' playwright "$@"
