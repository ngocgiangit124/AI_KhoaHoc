#!/usr/bin/env bash
# Sinh VV_HOME giả cho stack smoke `vvsmoke` (T35-1): env từ các mẫu production, secret bằng `openssl rand` (giá trị GIẢ, không dùng
# secret thật từ backend/.env), cấu hình MySQL/Redis/Nginx. Ra: infra/production/smoke/.generated/ (gitignore).
# Dùng: make-env.sh [--force] [--videolab]   (--videolab: bật VideoLab/worker-video; mặc định TẮT, video qua Bunny như production V1)   (--force: sinh lại secret; nhớ xoá volume smoke cũ vì mật khẩu MySQL root đổi)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD="$(cd "$HERE/.." && pwd)"
GEN="$HERE/.generated"
DOMAIN="vvsmoke.internal"
MEDIA_DOMAIN="vvsmoke-media.internal"
PREFIX="vitaminvui-database-"

VIDEOLAB=0
for a in "$@"; do
    case "$a" in --force) rm -rf "$GEN" ;; --videolab) VIDEOLAB=1 ;; esac
done
umask 077
mkdir -p "$GEN"/{env,conf,nginx/snippets,data/storage-app,data/storage-logs,data/uploads}
# Thư mục dữ liệu: Docker Desktop (macOS) ánh xạ quyền, nhưng cho rộng để container UID 10001 ghi được trên mọi nền tảng.
chmod 0777 "$GEN"/data/storage-app "$GEN"/data/storage-logs "$GEN"/data/uploads
mkdir -p "$GEN"/data/storage-app/{private,public,purifier,uploads,videolab/hls} 
chmod -R 0777 "$GEN"/data/storage-app

hex() { openssl rand -hex "${1:-16}"; }
sha256() { printf '%s' "$1" | openssl dgst -sha256 -r | cut -d' ' -f1; }

SECRETS="$GEN/secrets.env"
if [[ ! -f "$SECRETS" ]]; then
    {
        echo "APP_KEY=base64:$(openssl rand -base64 32)"
        echo "WORKER_APP_KEY=base64:$(openssl rand -base64 32)"
        echo "BUNNY_WEBHOOK_TOKEN=$(hex 32)"
        for n in MYSQL_ROOT_PW DB_APP_PW DB_MIGRATE_PW DB_WORKER_PW REDIS_PW REDIS_VIDEO_PW REDIS_WORKER_PW; do echo "$n=$(hex 16)"; done
        for n in BUNNY_API_KEY BUNNY_TOKEN_KEY INTERNAL_API_TOKEN VIDEOLAB_API_KEY VIDEOLAB_TOKEN_KEY VIDEOLAB_WEBHOOK_SECRET PRIVACY_NOTICE_TOKEN_KEY WORKER_PRIVACY_KEY; do echo "$n=$(hex 32)"; done
    } > "$SECRETS"
fi
set -a
# shellcheck disable=SC1090
source "$SECRETS"
set +a

# setenv <file> <KEY> <VALUE>: thay dòng `KEY=` đầu tiên (hoặc thêm cuối file). Giá trị truyền qua ENVIRON, không qua đối số awk.
setenv() {
    local f="$1"
    KEY="$2" VAL="$3" awk 'BEGIN{k=ENVIRON["KEY"]; v=ENVIRON["VAL"]; done=0}
        { if (!done && index($0, k "=") == 1) { print k "=" v; done=1 } else print }
        END { if (!done) print k "=" v }' "$f" > "$f.tmp"
    mv "$f.tmp" "$f"
}

# ---- app.env
A="$GEN/env/app.env"
cp "$PROD/.env.production.example" "$A"
setenv "$A" APP_ENV staging
setenv "$A" APP_KEY "$APP_KEY"
setenv "$A" APP_URL "https://api.$DOMAIN"
setenv "$A" APP_API_HOST "api.$DOMAIN"
setenv "$A" APP_ADMIN_API_HOST "admin-api.$DOMAIN"
setenv "$A" FRONTEND_URL "https://$DOMAIN"
setenv "$A" ADMIN_URL "https://admin.$DOMAIN"
setenv "$A" STATIC_URL "https://static.$MEDIA_DOMAIN"
setenv "$A" SANCTUM_STATEFUL_DOMAINS "$DOMAIN,admin.$DOMAIN"
setenv "$A" SESSION_COOKIE "__Host-vvstg_session"
setenv "$A" SESSION_ADMIN_COOKIE "__Host-vvstg_admin_session"
setenv "$A" TRUSTED_PROXIES "10.231.20.0/24"
setenv "$A" INTERNAL_API_TOKEN "$INTERNAL_API_TOKEN"
setenv "$A" DB_PASSWORD "$DB_APP_PW"
setenv "$A" REDIS_PASSWORD "$REDIS_PW"
setenv "$A" MAIL_HOST mailpit
setenv "$A" MAIL_PORT 1025
setenv "$A" MAIL_FROM_ADDRESS "no-reply@$DOMAIN"
setenv "$A" ORDERS_MANUAL_NOTIFY_EMAILS "hotro@$DOMAIN"
setenv "$A" PAYMENT_CONTACT_EMAIL "hotro@$DOMAIN"
setenv "$A" SUPPORT_EMAIL "hotro@$DOMAIN"
# V1 = Bunny (giá trị giả; không gọi Bunny thật)
setenv "$A" BUNNY_LIBRARY_ID "123456"
setenv "$A" BUNNY_API_KEY "$BUNNY_API_KEY"
setenv "$A" BUNNY_CDN_HOST "https://vz-vvsmoke.b-cdn.net"
setenv "$A" BUNNY_TOKEN_KEY "$BUNNY_TOKEN_KEY"
setenv "$A" BUNNY_WEBHOOK_TOKEN "$BUNNY_WEBHOOK_TOKEN"
if [[ $VIDEOLAB -eq 1 ]]; then
    setenv "$A" VIDEO_PROVIDER internal
    setenv "$A" VIDEO_ENABLED_PROVIDERS internal
    setenv "$A" VIDEOLAB_ENABLED true
    setenv "$A" REDIS_VIDEO_HOST redis-video
    setenv "$A" REDIS_VIDEO_PORT 6379
    setenv "$A" REDIS_VIDEO_USERNAME default
    setenv "$A" REDIS_VIDEO_PASSWORD "$REDIS_VIDEO_PW"
    setenv "$A" VIDEOLAB_API_KEY "$VIDEOLAB_API_KEY"
    setenv "$A" VIDEOLAB_TOKEN_KEY "$VIDEOLAB_TOKEN_KEY"
    setenv "$A" VIDEOLAB_WEBHOOK_SECRET "$VIDEOLAB_WEBHOOK_SECRET"
    setenv "$A" VIDEOLAB_HOST "video.$DOMAIN"
    setenv "$A" VIDEOLAB_PUBLIC_URL "https://video.$DOMAIN"
    setenv "$A" VIDEOLAB_INTERNAL_URL "http://nginx-smoke"
    setenv "$A" VIDEOLAB_WEBHOOK_URL "https://api.$DOMAIN/api/v1/webhooks/video/internal"
    setenv "$A" VIDEOLAB_WEBHOOK_HOST "api.$DOMAIN"
    setenv "$A" VIDEOLAB_PATH /var/www/backend/storage/app/videolab
    setenv "$A" VIDEOLAB_ACCEL_REDIRECT true
fi
setenv "$A" TURNSTILE_SITE_KEY "0x4AAAAAAAsmokefakesitekey"
setenv "$A" TURNSTILE_SECRET "0x4AAAAAAAsmokefakesecretkey"
setenv "$A" PRIVACY_NOTICE_TOKEN_KEY "$PRIVACY_NOTICE_TOKEN_KEY"
setenv "$A" PRIVACY_POLICY_VERSION "2026-10-tam"

# ---- migrate.env
cp "$PROD/.env.migrate.example" "$GEN/env/migrate.env"
setenv "$GEN/env/migrate.env" DB_PASSWORD "$DB_MIGRATE_PW"

if [[ $VIDEOLAB -eq 1 ]]; then
  # ---- worker-video.env
  W="$GEN/env/worker-video.env"
  cp "$PROD/.env.worker-video.example" "$W"
  setenv "$W" APP_ENV staging
  setenv "$W" APP_KEY "$WORKER_APP_KEY"
  setenv "$W" FRONTEND_URL "https://$DOMAIN"
  setenv "$W" ADMIN_URL "https://admin.$DOMAIN"
  setenv "$W" SANCTUM_STATEFUL_DOMAINS "$DOMAIN,admin.$DOMAIN"
  setenv "$W" STATIC_URL "https://static.$MEDIA_DOMAIN"
  setenv "$W" DB_PASSWORD "$DB_WORKER_PW"
  setenv "$W" REDIS_VIDEO_PASSWORD "$REDIS_WORKER_PW"
  setenv "$W" PRIVACY_NOTICE_TOKEN_KEY "$WORKER_PRIVACY_KEY"
fi

# ---- mysql.env
cp "$PROD/.env.mysql.example" "$GEN/env/mysql.env"
setenv "$GEN/env/mysql.env" MYSQL_ROOT_PASSWORD "$MYSQL_ROOT_PW"

# ---- web.env, admin.env (runtime; T35-2 smoke tuỳ chọn). SSR gọi listener :8081 của nginx-smoke.
printf 'API_INTERNAL_URL=http://nginx-smoke:8081\nINTERNAL_API_TOKEN=%s\nV2_PREVIEW=\n' "$INTERNAL_API_TOKEN" > "$GEN/env/web.env"
printf 'V2_PREVIEW=\n' > "$GEN/env/admin.env"
chmod 0600 "$GEN"/env/*.env

# ---- conf: MySQL, Redis, ACL
cp "$PROD/mysql/my.cnf" "$GEN/conf/my.cnf"
cp "$PROD/redis/redis.conf" "$GEN/conf/"
if [[ $VIDEOLAB -eq 1 ]]; then cp "$PROD/redis/redis-video.conf" "$GEN/conf/"; cp "$PROD/mysql/grants-worker.sql" "$GEN/conf/grants-worker.sql"; fi
# Redis chính: dòng `default` + `vv_healthcheck` (ADR-008 §8.7; không có worker).
grep -E '^user (default|vv_healthcheck) ' "$PROD/redis/users.acl" | sed "s/<SHA256_MAT_KHAU_APP>/$(sha256 "$REDIS_PW")/" > "$GEN/conf/users.acl"
if [[ $VIDEOLAB -eq 1 ]]; then
  # Redis video: default + vv_worker_video.
  sed -e "s/<SHA256_MAT_KHAU_APP>/$(sha256 "$REDIS_VIDEO_PW")/" -e "s/<SHA256_MAT_KHAU_WORKER>/$(sha256 "$REDIS_WORKER_PW")/" -e "s/<PREFIX>/$PREFIX/g" \
      "$PROD/redis/users.video-instance.acl" > "$GEN/conf/users.video-instance.acl"
fi
# redis chạy bằng user `redis` (uid 999) trong container: file phải đọc được. Chỉ chứa băm SHA-256 và là dữ liệu giả.
chmod 0644 "$GEN"/conf/*

# ---- Nginx smoke: DÙNG NGUYÊN snippet production, chỉ điền placeholder <IP_MONITOR_LB> như khi triển khai thật.
cp "$PROD/nginx/snippets/vv-deny.conf" "$GEN/nginx/snippets/vv-deny.conf"
# smoke truy cập từ host qua cổng publish (nguồn là gateway Docker, địa chỉ tuỳ nền tảng) nên cho cả dải private; production chỉ IP giám sát.
perl -pe 's#^(\s*)allow <IP_MONITOR_LB>;#$1allow 127.0.0.1;\n$1allow 10.0.0.0/8;\n$1allow 172.16.0.0/12;\n$1allow 192.168.0.0/16;#' \
    "$PROD/nginx/snippets/vv-api-common.conf" > "$GEN/nginx/snippets/vv-api-common.conf"
if grep -Eq '^[^#]*<IP_' "$GEN/nginx/snippets/vv-api-common.conf"; then echo "make-env: còn placeholder <IP_...> trong snippet" >&2; exit 1; fi

# Block listener :8081 trích NGUYÊN từ production conf, chỉ đổi địa chỉ nghe, allow và tên host.
INTERNAL_BLOCK="$(awk '
    { lines[NR] = $0 }
    /listen <IP_NOI_BO_NGINX>:8081;/ { hit = NR }
    END {
        s = hit; while (s > 0 && lines[s] !~ /^server \{/) s--
        e = hit; while (e <= NR && lines[e] !~ /^\}/) e++
        for (i = s; i <= e; i++) print lines[i]
    }' "$PROD/nginx/conf.d/vitaminvui.conf")"
[[ -n "$INTERNAL_BLOCK" ]] || { echo "make-env: không tìm thấy block :8081 trong vitaminvui.conf" >&2; exit 1; }
INTERNAL_BLOCK="$(printf '%s\n' "$INTERNAL_BLOCK" \
    | sed -e 's#listen <IP_NOI_BO_NGINX>:8081;#listen 8081;#' \
          -e 's#allow <IP_NEXT_SERVER>;#allow 10.231.20.0/24;#' \
          -e "s#api\.vitaminvui\.vn#api.$DOMAIN#g" \
          -e 's#access_log /var/log/nginx/vv-internal.access.log;#access_log /var/log/nginx/vv-internal.access.log vv_smoke_internal;#')"

# Zone giới hạn của host API: trích NGUYÊN từ conf production (cùng ngưỡng với snippet vv-api-common.conf).
API_ZONES="$(grep -E '^limit_(req|conn)_zone .*zone=vv_(api|api_auth|conn):' "$PROD/nginx/conf.d/vitaminvui.conf")"
[[ $(printf '%s\n' "$API_ZONES" | wc -l) -eq 3 ]] || { echo "make-env: không trích đủ 3 zone vv_api/vv_api_auth/vv_conn" >&2; exit 1; }
cat > "$GEN/nginx/default.conf" <<CONF
# Sinh bởi smoke/make-env.sh. Upstream php dùng IP cố định (smoke override) để nginx khởi động được trước khi php tồn tại.
upstream php_fpm { server 10.231.21.10:9000; keepalive 16; }
log_format vv_noargs '\$remote_addr - [\$time_local] "\$request_method \$uri" \$status \$body_bytes_sent "\$http_user_agent"';
# Ghi có/không header X-Internal-Token (KHÔNG ghi giá trị) cho kiểm của T35-2 (d).
map \$http_x_internal_token \$vv_has_token { default "yes"; "" "no"; }
log_format vv_smoke_internal '\$remote_addr "\$request_method \$request_uri" \$status internal_token=\$vv_has_token';
limit_req_zone \$binary_remote_addr zone=vv_cdn:1m rate=30r/s;
$API_ZONES

server {
    listen 80 default_server;
    server_name api.$DOMAIN admin-api.$DOMAIN;
    root /var/www/backend/public;
    include /etc/nginx/snippets/vv-api-common.conf;
}

$INTERNAL_BLOCK

# Tên miền tĩnh giả cho kiểm /_next/image của web (T35-2 (d)): phục vụ thư mục uploads, KHÔNG cookie. Cổng 8090 (http) vì smoke không có TLS.
server {
    listen 8090;
    server_name static.$MEDIA_DOMAIN;
    root /var/www/uploads;
    server_tokens off;
    location / { try_files \$uri =404; }
}
CONF

# ---- compose env ($VV_HOME/.env)
SMOKE_MYSQL_OVERRIDE=$'VV_MYSQL_BUFFER_POOL=256M\nVV_MYSQL_MEM_LIMIT=1g\n'
# shellcheck disable=SC2034  # dùng trong heredoc bên dưới
[[ "${VV_SIZE:-large}" == "small" ]] && SMOKE_MYSQL_OVERRIDE=""   # small dùng nguyên bộ giá trị của sizes/small.env
cat > "$GEN/.env" <<ENV
VV_ENV=production
VV_REGISTRY=vv-local
IMAGE_TAG=
PREVIOUS_TAG=
VV_SUBNET_FRONT=10.231.20.0/24
VV_SUBNET_APP=10.231.21.0/24
VV_SUBNET_VIDEO=10.231.22.0/24
VV_STORAGE_APP=$GEN/data/storage-app
VV_STORAGE_LOGS=$GEN/data/storage-logs
VV_UPLOADS_DIR=$GEN/data/uploads
VV_DATA_DIR=$GEN/data
${SMOKE_MYSQL_OVERRIDE}VV_WORKER_DB_HOST=10.231.22.%
VV_VIDEOLAB=$VIDEOLAB
VV_SIZE=${VV_SIZE:-large}
ENV
chmod 0640 "$GEN/.env"
echo "make-env: đã sinh $GEN"
