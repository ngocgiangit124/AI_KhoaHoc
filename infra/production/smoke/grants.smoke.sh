#!/usr/bin/env bash
# Điền infra/production/mysql/grants-users.sql bằng mật khẩu smoke đã sinh (stdin, không ghi file) rồi chạy trong container mysql (root, mật khẩu
# từ môi trường container). Chạy MỘT lần trước deploy đầu tiên. Quyền bảng của vv_worker_video do deploy.sh cấp sau migrate (grants-worker.sql).
# Host user theo mạng smoke: app/migrate 10.231.21.%, worker 10.231.22.% (production: 10.231.11.% và 10.231.12.%).
# shellcheck disable=SC2016
set -euo pipefail
# shellcheck source=lib.sh disable=SC1091
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
load_secrets
VL_SED='s/^$/&/'   # V1: không tạo user worker; --videolab (VV_VIDEOLAB=1 trong .env): bỏ tiền tố `-- VIDEOLAB: `
grep -q '^VV_VIDEOLAB=1$' "$GEN/.env" && VL_SED='s/^-- VIDEOLAB: //'
sed -e "$VL_SED" "$PROD_DIR/mysql/grants-users.sql" \
  | sed -e 's/<DB>/vitaminvui/g' \
    -e 's/<APP_HOST>/10.231.21.%/g' -e 's/<MIGRATE_HOST>/10.231.21.%/g' -e 's/<WORKER_HOST>/10.231.22.%/g' \
    -e "s/<MAT_KHAU_APP>/$DB_APP_PW/" -e "s/<MAT_KHAU_MIGRATE>/$DB_MIGRATE_PW/" -e "s/<MAT_KHAU_WORKER>/$DB_WORKER_PW/" \
    | dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot'
echo "grants.smoke: đã tạo vv_app, vv_migrate, vv_worker_video (quyền bảng worker: deploy.sh cấp sau migrate)"
