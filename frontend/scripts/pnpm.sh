#!/usr/bin/env bash
# Chạy pnpm trong Docker (node:22, pnpm 9 qua corepack) với đúng UID/GID của user host
# để file tạo ra trên WSL2 không thuộc root. Cache pnpm store dùng volume Docker đặt tên
# để không tải lại package mỗi lần.
#
# Cách dùng (từ thư mục repo gốc hoặc frontend/):
#   frontend/scripts/pnpm.sh install
#   frontend/scripts/pnpm.sh -r lint
#   frontend/scripts/pnpm.sh --filter @vitaminvui/web build
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
IMAGE="vitaminvui-frontend-dev:latest"

# Bind mount (không phải named volume) để cache pnpm store thuộc về user host,
# tránh lỗi quyền EACCES khi container chạy --user $(id -u):$(id -g).
# LƯU Ý: .pnpm-store nằm trong /workspace (bind mount) nhưng KHÔNG được đặt biến HOME
# trỏ vào bên trong /workspace — Next.js/Turbopack từ chối dùng pnpm-workspace.yaml làm
# workspace root nếu phát hiện thư mục HOME nằm ngay trong đó ("would include your home
# directory"), khiến build không tìm thấy node_modules/next ở gốc workspace. Dùng sẵn
# /home/node (đã có, đúng quyền UID 1000, thuộc image node:22) của image gốc.
mkdir -p "$FRONTEND_DIR/.pnpm-store"

# Build ảnh dev nếu chưa có (rẻ, cache layer Docker).
docker build -q -t "$IMAGE" -f "$FRONTEND_DIR/Dockerfile.dev" "$FRONTEND_DIR" >/dev/null

docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e HOME=/home/node \
  -v "$FRONTEND_DIR:/workspace" \
  -w /workspace \
  "$IMAGE" \
  pnpm "$@"
