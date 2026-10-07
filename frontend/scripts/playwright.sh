#!/usr/bin/env bash
# Chạy Playwright e2e trong Docker bằng ảnh chính thức mcr.microsoft.com/playwright
# (đã cài sẵn trình duyệt + thư viện hệ thống — tránh phải `playwright install --with-deps`
# trong ảnh node:22 thường dùng cho pnpm). Ghim đúng version @playwright/test
# (frontend/apps/*/package.json).
#
# Cách dùng:
#   frontend/scripts/playwright.sh web             # chạy e2e apps/web, mock API nội bộ
#   frontend/scripts/playwright.sh admin           # chạy e2e apps/admin, mock API nội bộ
#   frontend/scripts/playwright.sh web --real-backend    # dùng backend Laravel thật
#   frontend/scripts/playwright.sh admin --real-backend
#   frontend/scripts/playwright.sh web --real-backend --retries=0 e2e/otp.spec.ts
#     (từ tham số thứ 3 trở đi chuyển thẳng cho `playwright test`; muốn truyền tham số
#     ở chế độ mock thì đặt tham số thứ 2 là `--mock`)
#
# `--real-backend`: bỏ qua e2e/mock-api-server.mjs, gọi thẳng backend thật đang chạy ở
# api.localhost:8000 / admin-api.localhost:8000 (infra/docker-compose.yml của laravel-dev,
# publish cổng 8000 ra host). Dùng `--network host` + `--add-host` để container Docker
# phân giải được `*.localhost` giống hệt trình duyệt thật (glibc resolver mặc định KHÔNG
# tự phân giải `*.localhost` như trình duyệt — phải khai rõ bằng --add-host).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLAYWRIGHT_VERSION="1.63.0"
IMAGE="mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble"
APP="${1:?Dùng: playwright.sh <web|admin> [--real-backend]}"
MODE="${2:-}"

mkdir -p "$FRONTEND_DIR/.pnpm-store"

NETWORK_ARGS=()
FORWARD_8000=0
ENV_ARGS=(-e CI=1)
if [ "$MODE" = "--real-backend" ]; then
  NETWORK_ARGS=(
    --add-host api.localhost:127.0.0.1
    --add-host admin-api.localhost:127.0.0.1
  )
  if [ "$(uname -s)" = "Darwin" ]; then
    # Docker Desktop (macOS): --network host không đưa container vào mạng của máy host.
    # Chromium luôn trỏ *.localhost về loopback của container, nên mở một bộ chuyển tiếp
    # 127.0.0.1:8000 -> host.docker.internal:8000 ngay trong container (Docker Desktop tới
    # được nginx dù nginx chỉ bind 127.0.0.1:8000 trên host).
    FORWARD_8000=1
  else
    NETWORK_ARGS+=(--network host)
  fi
  ENV_ARGS+=(-e E2E_REAL_BACKEND=1)
  if [ "$(uname -s)" = "Darwin" ]; then
    # Mailpit (cổng 8025 trên host): trong container Docker Desktop 127.0.0.1 không tới được host.
    ENV_ARGS+=(-e E2E_MAILPIT_URL="${E2E_MAILPIT_URL:-http://host.docker.internal:8025}" -e MAILPIT_URL="${MAILPIT_URL:-http://host.docker.internal:8025}")
  fi
fi

# Không dùng corepack (cần ghi vào /usr/bin, không được phép khi chạy --user non-root).
# node_modules đã có sẵn từ `scripts/pnpm.sh install` (bind mount dùng chung) — gọi thẳng
# binary local, không cần pnpm/corepack trong container này.
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  "${ENV_ARGS[@]}" \
  ${NETWORK_ARGS[@]+"${NETWORK_ARGS[@]}"} \
  -v "$FRONTEND_DIR:/workspace" \
  -w "/workspace/apps/$APP" \
  -e FORWARD_8000="$FORWARD_8000" \
  "$IMAGE" \
  bash -c '
    if [ "$FORWARD_8000" = 1 ]; then
      node -e "require(\"net\").createServer(c => { const u = require(\"net\").connect(8000, \"host.docker.internal\"); c.pipe(u).pipe(c); u.on(\"error\", () => c.destroy()); c.on(\"error\", () => u.destroy()); }).listen(8000, \"127.0.0.1\")" &
      sleep 1
    fi
    exec ./node_modules/.bin/playwright test "$@"
  ' playwright "${@:3}"
