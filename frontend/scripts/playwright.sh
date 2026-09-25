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
ENV_ARGS=(-e CI=1)
if [ "$MODE" = "--real-backend" ]; then
  NETWORK_ARGS=(
    --network host
    --add-host api.localhost:127.0.0.1
    --add-host admin-api.localhost:127.0.0.1
    --add-host admin.localhost:127.0.0.1
  )
  ENV_ARGS+=(-e E2E_REAL_BACKEND=1)
fi

# Không dùng corepack (cần ghi vào /usr/bin, không được phép khi chạy --user non-root).
# node_modules đã có sẵn từ `scripts/pnpm.sh install` (bind mount dùng chung) — gọi thẳng
# binary local, không cần pnpm/corepack trong container này.
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e HOME=/home/pwuser \
  "${ENV_ARGS[@]}" \
  "${NETWORK_ARGS[@]}" \
  -v "$FRONTEND_DIR:/workspace" \
  -w "/workspace/apps/$APP" \
  "$IMAGE" \
  ./node_modules/.bin/playwright test
