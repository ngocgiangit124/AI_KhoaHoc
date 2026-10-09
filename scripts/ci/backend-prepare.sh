#!/usr/bin/env bash
# Chuẩn bị môi trường test backend (T35-3, ADR-008 §8.10). Chạy được ngoài GitHub (giống scripts/cloud-setup.sh).
#
#   scripts/ci/backend-prepare.sh env     # tạo backend/.env từ .env.example (+ key:generate). Cần đã `composer install`.
#   scripts/ci/backend-prepare.sh db      # hosts + DB/user test + SET GLOBAL (MySQL đã chạy ở 127.0.0.1:3306)
#   scripts/ci/backend-prepare.sh all     # db rồi env
#
# Biến (mọi giá trị chỉ là hằng cho CI/test, không phải secret thật):
#   MYSQL_HOST (127.0.0.1)  MYSQL_PORT (3306)  MYSQL_ROOT_PASSWORD (bắt buộc cho `db`)
#   DB_APP_PASSWORD (secret)  REDIS_ADDR (127.0.0.1)
# phpunit.xml ép DB_HOST=mysql nên "mysql" và "redis" phải phân giải về máy chạy service.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
DB_APP_PASSWORD="${DB_APP_PASSWORD:-secret}"
REDIS_ADDR="${REDIS_ADDR:-127.0.0.1}"
SUDO=""
[[ "$(id -u)" -eq 0 ]] || SUDO="sudo"

log() { echo "[backend-prepare] $*"; }

prepare_db() {
  : "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD là bắt buộc}"
  if ! command -v mysql >/dev/null 2>&1; then
    log "Cài mysql-client"
    $SUDO apt-get update -qq
    $SUDO apt-get install -y -qq mysql-client || $SUDO apt-get install -y -qq default-mysql-client
  fi

  # /etc/hosts cần địa chỉ IP: nếu truyền tên máy (chạy trong mạng Docker) thì phân giải trước.
  local mysql_ip redis_ip
  mysql_ip="$(getent hosts "$MYSQL_HOST" | awk '{print $1; exit}')"
  redis_ip="$(getent hosts "$REDIS_ADDR" | awk '{print $1; exit}')"
  [[ -n "$mysql_ip" && -n "$redis_ip" ]] || { log "Không phân giải được MYSQL_HOST/REDIS_ADDR"; exit 1; }
  if ! grep -qE '(^|\s)mysql(\s|$)' /etc/hosts; then
    echo "$mysql_ip mysql" | $SUDO tee -a /etc/hosts >/dev/null
  fi
  if ! grep -qE '(^|\s)redis(\s|$)' /etc/hosts; then
    echo "$redis_ip redis" | $SUDO tee -a /etc/hosts >/dev/null
  fi

  log "Chờ MySQL ${MYSQL_HOST}:${MYSQL_PORT}"
  local i
  for i in $(seq 1 60); do
    if MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin -h "$MYSQL_HOST" -P "$MYSQL_PORT" -uroot --protocol=tcp ping >/dev/null 2>&1; then
      break
    fi
    [[ "$i" -lt 60 ]] || { log "MySQL không sẵn sàng"; exit 1; }
    sleep 1
  done

  # Cấu hình giống infra/docker-compose.yml (isolation, múi giờ) + log_bin_trust_function_creators cho migration tạo trigger.
  # Service container không truyền được tham số dòng lệnh nên đặt bằng SET GLOBAL (áp cho kết nối MỚI).
  log "Tạo DB/user test, đặt READ-COMMITTED, trust_function_creators"
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -uroot --protocol=tcp <<SQL
SET GLOBAL transaction_isolation = 'READ-COMMITTED';
SET GLOBAL log_bin_trust_function_creators = 1;
SET GLOBAL time_zone = '+07:00';
CREATE DATABASE IF NOT EXISTS vitaminvui CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'vitaminvui'@'%' IDENTIFIED BY '${DB_APP_PASSWORD}';
GRANT ALL ON vitaminvui.* TO 'vitaminvui'@'%';
GRANT ALL ON vitaminvui_testing.* TO 'vitaminvui'@'%';
FLUSH PRIVILEGES;
SQL

  local iso
  iso="$(MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -uroot --protocol=tcp -N -e 'SELECT @@global.transaction_isolation')"
  [[ "$iso" == "READ-COMMITTED" ]] || { log "Isolation toàn cục là '$iso', mong đợi READ-COMMITTED"; exit 1; }
}

prepare_env() {
  cd "$ROOT/backend"
  if [[ ! -f .env ]]; then
    cp .env.example .env
    # DB_PASSWORD/REDIS_PASSWORD: dùng `|` làm dấu phân cách sed; giá trị CI không chứa `|` hay `&`.
    sed -i.bak \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_APP_PASSWORD}|" \
      -e 's|^REDIS_PASSWORD=.*|REDIS_PASSWORD=null|' .env
    rm -f .env.bak
  fi
  mkdir -p storage/framework/phpstan storage/framework/cache storage/framework/views storage/framework/sessions storage/logs bootstrap/cache
  grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction >/dev/null
  log "backend/.env sẵn sàng (APP_KEY không in ra log)"
}

case "${1:-}" in
  db) prepare_db ;;
  env) prepare_env ;;
  all) prepare_db; prepare_env ;;
  *) echo "Dùng: $0 db|env|all" >&2; exit 2 ;;
esac
