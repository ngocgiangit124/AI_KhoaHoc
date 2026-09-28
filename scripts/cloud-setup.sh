#!/usr/bin/env bash
# Cài môi trường cho Claude Code on the web (cloud sandbox).
# Chạy tự động qua SessionStart hook trong .claude/settings.json.
# Máy local KHÔNG chạy script này (dev local dùng Docker: xem backend/README.md, frontend/README.md).
#
# Cloud sandbox thường không có Docker, nên script cài trực tiếp:
#   PHP 8.3 + Composer, MySQL, Redis, Node 22 + pnpm 9
# rồi dựng lại đúng những gì phpunit.xml cần (host "mysql", DB vitaminvui_testing,
# user vitaminvui). Từ M6, phpunit.xml không còn ép DB_PASSWORD="secret" (mật
# khẩu DB local giờ ngẫu nhiên theo máy) — script này tự đặt backend/.env
# DB_PASSWORD=secret và tạo user MySQL vitaminvui/secret khớp nhau, Laravel tự
# đọc mật khẩu đó từ .env. Script idempotent: chạy lại nhiều lần không sao.

set -uo pipefail

if [[ "${CLAUDE_CODE_REMOTE:-}" != "true" ]]; then
  exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
LOG="/tmp/vitaminvui-cloud-setup.log"
SUDO=""
[[ $EUID -ne 0 ]] && command -v sudo >/dev/null && SUDO="sudo"
export DEBIAN_FRONTEND=noninteractive

log() { echo "[cloud-setup] $*" | tee -a "$LOG" >&2; }
run() { "$@" >>"$LOG" 2>&1; }

log "Bắt đầu cài môi trường (log chi tiết: $LOG)"

# Nếu sandbox có Docker thì dùng đúng stack local, không cài gì thêm.
if command -v docker >/dev/null && docker info >/dev/null 2>&1; then
  log "Có Docker: dùng infra/docker-compose.yml như máy local"
  [[ -f "$ROOT/infra/.env" ]] || cp "$ROOT/infra/.env.example" "$ROOT/infra/.env"
  [[ -f "$ROOT/backend/.env" ]] || cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
  (cd "$ROOT/infra" && VV_UID=$(id -u) VV_GID=$(id -g) run docker compose up -d --build) \
    || log "CẢNH BÁO: docker compose up lỗi, xem $LOG"
  exit 0
fi

run $SUDO apt-get update -y

# --- PHP 8.3 + Composer --------------------------------------------------------
if ! php -v 2>/dev/null | grep -q '^PHP 8\.3'; then
  log "Cài PHP 8.3"
  if ! apt-cache show php8.3-cli >/dev/null 2>&1; then
    run $SUDO apt-get install -y software-properties-common
    run $SUDO add-apt-repository -y ppa:ondrej/php
    run $SUDO apt-get update -y
  fi
  run $SUDO apt-get install -y php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
    php8.3-mysql php8.3-bcmath php8.3-intl php8.3-gd php8.3-redis php8.3-sqlite3 unzip
  run $SUDO update-alternatives --set php /usr/bin/php8.3 || true
fi
if ! command -v composer >/dev/null; then
  log "Cài Composer"
  run bash -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer"
  run $SUDO mv /tmp/composer /usr/local/bin/composer
fi

# --- MySQL + Redis -------------------------------------------------------------
if ! command -v mysqld >/dev/null; then
  log "Cài MySQL server + Redis"
  run $SUDO apt-get install -y mysql-server redis-server
fi
# Cấu hình giống infra/docker-compose.yml (charset/collation/isolation/binlog).
$SUDO tee /etc/mysql/conf.d/vitaminvui.cnf >/dev/null <<'EOF'
[mysqld]
character-set-server=utf8mb4
collation-server=utf8mb4_0900_ai_ci
transaction-isolation=READ-COMMITTED
binlog_format=ROW
default-time-zone='+07:00'
EOF
run $SUDO service mysql start || run $SUDO mysqld_safe --daemonize || true
run $SUDO service redis-server start || run redis-server --daemonize yes || true

# phpunit.xml trỏ DB_HOST=mysql → cho "mysql" và "redis" phân giải về máy này.
grep -qE '\smysql(\s|$)' /etc/hosts || echo "127.0.0.1 mysql redis" | $SUDO tee -a /etc/hosts >/dev/null

for i in $(seq 1 30); do $SUDO mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
log "Tạo DB/user cho test (vitaminvui_testing, user vitaminvui/secret — khớp backend/.env DB_PASSWORD bên dưới)"
run $SUDO mysql -uroot <<'EOF'
CREATE DATABASE IF NOT EXISTS vitaminvui CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'vitaminvui'@'%' IDENTIFIED BY 'secret';
CREATE USER IF NOT EXISTS 'vitaminvui'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL ON vitaminvui.* TO 'vitaminvui'@'%';
GRANT ALL ON vitaminvui_testing.* TO 'vitaminvui'@'%';
GRANT ALL ON vitaminvui.* TO 'vitaminvui'@'localhost';
GRANT ALL ON vitaminvui_testing.* TO 'vitaminvui'@'localhost';
FLUSH PRIVILEGES;
EOF

# --- Backend -------------------------------------------------------------------
cd "$ROOT/backend" || exit 0
if [[ ! -f .env ]]; then
  log "Tạo backend/.env cho cloud (mật khẩu DB = secret, Redis không mật khẩu)"
  cp .env.example .env
  sed -i -e 's/^DB_PASSWORD=.*/DB_PASSWORD=secret/' -e 's/^REDIS_PASSWORD=.*/REDIS_PASSWORD=null/' .env
fi
log "composer install"
run composer install --no-interaction --prefer-dist || log "CẢNH BÁO: composer install lỗi, xem $LOG"
grep -q '^APP_KEY=base64' .env || run php artisan key:generate --force
run php artisan migrate --force || log "CẢNH BÁO: migrate lỗi, xem $LOG"

# --- Frontend: Node 22 + pnpm 9 ------------------------------------------------
NODE_MAJOR=$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/')
if [[ -z "$NODE_MAJOR" || "$NODE_MAJOR" -lt 22 ]]; then
  log "Cài Node 22"
  run bash -c "curl -fsSL https://deb.nodesource.com/setup_22.x | $SUDO -E bash -"
  run $SUDO apt-get install -y nodejs
fi
run $SUDO corepack enable || true
run corepack prepare pnpm@9 --activate || true
cd "$ROOT/frontend" || exit 0
for app in web admin; do
  [[ -f "apps/$app/.env.local" ]] || cp "apps/$app/.env.example" "apps/$app/.env.local"
done
log "pnpm install"
run pnpm install --frozen-lockfile || log "CẢNH BÁO: pnpm install lỗi, xem $LOG"

log "Xong. Backend: cd backend && composer ci. Frontend: cd frontend && pnpm -r run test"
exit 0
