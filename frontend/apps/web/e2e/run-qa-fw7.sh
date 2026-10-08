#!/usr/bin/env bash
# Chạy e2e THẬT của Quyền dữ liệu cá nhân (FW7) trong ảnh Playwright (macOS + Docker Desktop). Không đụng dev server cổng 3000 của host.
#   frontend/apps/web/e2e/seed-e2e-fw7.sh --reset      # trước MỖI lần chạy (in ra unsub=<token> unsubmain=<token>)
#   E2E_QAFW7="unsub=.. unsubmain=.." frontend/apps/web/e2e/run-fw7-real.sh [đối số playwright]
#   frontend/apps/web/e2e/seed-e2e-fw7.sh --clean      # sau khi xong
# Build vào .next-qa-fw7 (E2E_REUSE_BUILD=1 bỏ build lại). OTP/thư đọc từ Mailpit trên host (host.docker.internal:8025).
set -euo pipefail
WEB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$(cd "$WEB_DIR/../.." && pwd)"
IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble"
[ "${E2E_REUSE_BUILD:-}" = "1" ] || rm -rf "$WEB_DIR/.next-qa-fw7"
# Né `.next/dev/types` của dev server dùng chung (có thể còn import trang đã gỡ làm `next build` lỗi type): trong CONTAINER chồng một
# tsconfig tạm (bỏ dòng đó) lên tsconfig.json. File trên host không đổi, không đụng `.next` dùng chung.
TS_TMP="$(mktemp)"; trap 'rm -f "$TS_TMP"' EXIT
grep -v '\.next/dev/types' "$WEB_DIR/tsconfig.json" > "$TS_TMP"; chmod 666 "$TS_TMP"
docker run --rm \
  --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 -e E2E_REAL_BACKEND=1 -e NEXT_TELEMETRY_DISABLED=1 \
  -e E2E_QAFW7="${E2E_QAFW7:-}" -e E2E_DEBUG="${E2E_DEBUG:-}" -e E2E_REUSE_BUILD="${E2E_REUSE_BUILD:-}" \
  -e E2E_MAILPIT_URL="http://host.docker.internal:8025" \
  --add-host api.localhost:127.0.0.1 \
  -v "$FRONTEND_DIR:/workspace" -v "$TS_TMP:/workspace/apps/web/tsconfig.json" -w /workspace/apps/web "$IMAGE" \
  bash -c '
    node -e "const n=require(\"net\");n.createServer(c=>{const u=n.connect(8000,\"host.docker.internal\");c.pipe(u).pipe(c);u.on(\"error\",()=>c.destroy());c.on(\"error\",()=>u.destroy())}).listen(8000,\"127.0.0.1\")" &
    sleep 1
    exec ./node_modules/.bin/playwright test --config playwright.qa-fw7.config.ts --workers=1 --trace=off "$@"
  ' playwright "$@"
