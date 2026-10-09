#!/usr/bin/env bash
# VitaminVui — deploy/rollback trên server (T35-1, ADR-008 §8.11–8.12). Chạy bằng user `vvdeploy` (nhóm docker) qua SSH.
#
#   deploy.sh deploy <sha> [--maintenance] [--ack-irreversible] [--worker-video=graceful|skip]
#   deploy.sh rollback [<tag>] [--force-schema-ahead] [--worker-video=graceful|skip]     (không có <tag>: PREVIOUS_TAG; <tag> tường minh để quay về bản KHÁC)
#   deploy.sh status
#   deploy.sh compose <đối số docker compose...>     (chạy compose với đúng project/env/cỡ máy, vd. `deploy.sh compose ps`)
#   cờ chung: --local (CHỈ cho smoke: bỏ `pull`, nhận tag tự do như `dev`; VV_HOME trỏ thư mục smoke)
#
# Biến môi trường (đều tuỳ chọn): VV_HOME (mặc định /opt/vitaminvui), VV_PROJECT (vvstack), COMPOSE_FILE (danh sách file, dùng cho smoke),
# VV_SMOKE_BASE (smoke: http://127.0.0.1:18080 thay cho https://<api-host> qua --resolve), VV_SKIP_FRONTEND=1 (--local: không có web/admin),
# VV_WEB_PORT/VV_ADMIN_PORT (cổng loopback của web/admin, mặc định 3000/3001).
# Trong $VV_HOME/.env hoặc môi trường: VV_VIDEOLAB=0|1 (mặc định 0: V1 dùng Bunny, KHÔNG chạy worker-video/redis-video/VideoLab; =1 bật profile compose `videolab`
# và các bước worker), VV_WORKER_DB_HOST (host user vv_worker_video, mặc định 10.231.12.%, chỉ khi VV_VIDEOLAB=1),
# IMAGE_DIGEST_BACKEND|WORKER_VIDEO|WEB|ADMIN (sha256:<64 hex>): BẮT BUỘC khi không --local (S2); VV_COSIGN_VERIFY=1 thì còn chạy `cosign verify` mỗi image.
# Quyền thư mục: $VV_HOME phải GHI ĐƯỢC bởi user chạy script (khoá, .env tạm, releases.log): README đặt chủ vvdeploy (S8).
#
# Nguyên tắc: không dùng lệnh "down" của compose (xoá mạng làm Nginx host mất 10.231.10.1); chỉ pull/up -d/run --rm/exec. Không in giá trị secret:
# không `set -x`, không in `compose config`, mật khẩu root MySQL lấy từ môi trường container. Lỗi sau khi đổi container: KHÔNG tự rollback,
# in lệnh rollback và trạng thái migrate để người vận hành quyết.
# shellcheck disable=SC2016  # chuỗi trong 'sh -c' cố ý để container tự mở rộng biến (mật khẩu không đi qua script)
set -euo pipefail
# R2: `sort` và `comm` phải dùng cùng một collation (Ubuntu mặc định C.UTF-8/en_US khác C): sai thứ tự làm `comm` báo sai migration chờ.
export LC_ALL=C

VV_HOME="${VV_HOME:-/opt/vitaminvui}"
PROJECT="${VV_PROJECT:-vvstack}"
export VV_HOME
LOCAL=0
ACTION=""
SHA=""
OPT_MAINT=0
OPT_ACK=0
OPT_FORCE_SCHEMA=0
OPT_WORKER="graceful"
LOCK_DIR=""
IMG_MIG=""
DB_MIG=""
TRUST_RAISED=0
MAINT_ON=0
HAD_MIGRATE="no"
TAG_RE='^[0-9a-f]{40}$'

# Các lệnh scheduler bắt buộc có trong `schedule:list` (go-live-readiness §5, checklist §6).
REQUIRED_SCHEDULE="counters:recount videos:check-stuck videos:prune-orphans images:prune-orphans quizzes:auto-submit-expired orders:expire-manual otp:prune audit:purge orders:purge-customer-notes orders:purge-staff-notes users:purge-unverified queue:prune-failed queue:monitor ops:health"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
warn() { printf '[%s] CẢNH BÁO: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { printf '[%s] LỖI: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------------------------------- compose
# Cỡ máy (VV_SIZE=large|small, mặc định large): nạp sizes/<cỡ>.env TRƯỚC .env (.env đặt lại từng giá trị thì thắng).
size_env_file() {
    local sz="${VV_SIZE:-$(env_value VV_SIZE)}" dir
    sz="${sz:-large}"
    [[ "$sz" == "large" || "$sz" == "small" ]] || die "VV_SIZE phải là large hoặc small (đang '$sz')"
    dir="$(dirname "$(main_compose_file)")/sizes"
    [[ -f "$dir/$sz.env" ]] || die "thiếu $dir/$sz.env (copy thư mục sizes/ cùng docker-compose.yml)"
    printf '%s' "$dir/$sz.env"
}

dc() {
    if [[ -n "${COMPOSE_FILE:-}" ]]; then
        docker compose --project-name "$PROJECT" --env-file "$(size_env_file)" --env-file "$VV_HOME/.env" "$@"
    else
        docker compose --project-name "$PROJECT" --project-directory "$VV_HOME" -f "$VV_HOME/docker-compose.yml" --env-file "$(size_env_file)" --env-file "$VV_HOME/.env" "$@"
    fi
}

main_compose_file() {
    if [[ -n "${COMPOSE_FILE:-}" ]]; then
        printf '%s' "${COMPOSE_FILE%%:*}"
    else
        printf '%s' "$VV_HOME/docker-compose.yml"
    fi
}

env_value() { # env_value KEY  -> giá trị trong $VV_HOME/.env (không source file)
    local key="$1" line
    [[ -f "$VV_HOME/.env" ]] || return 0
    line="$(grep -E "^${key}=" "$VV_HOME/.env" | tail -n 1 || true)"
    printf '%s' "${line#*=}"
}

set_env_value() { # set_env_value KEY VALUE  -> ghi đè an toàn (file tạm + mv, giữ quyền)
    local key="$1" value="$2" tmp
    tmp="$(mktemp "$VV_HOME/.env.XXXXXX")"
    if [[ -f "$VV_HOME/.env" ]]; then
        grep -vE "^${key}=" "$VV_HOME/.env" > "$tmp" || true
        chmod --reference="$VV_HOME/.env" "$tmp" 2>/dev/null || chmod 0640 "$tmp"
    else
        chmod 0640 "$tmp"
    fi
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    mv "$tmp" "$VV_HOME/.env"
}

app_env_value() { # app_env_value KEY -> giá trị không bí mật trong env/app.env (APP_API_HOST...)
    local line
    line="$(grep -E "^$1=" "$VV_HOME/env/app.env" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%\"}"
    printf '%s' "${line#\"}"
}

file_mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"; }

# V1: VideoLab tắt (Bunny). VV_VIDEOLAB=1 bật service worker-video/redis-video (profile compose `videolab`) và mọi bước liên quan.
videolab_on() { [[ "${VV_VIDEOLAB:-$(env_value VV_VIDEOLAB)}" == "1" ]]; }
worker_services() { if videolab_on; then printf 'worker-video'; fi; }
data_services() { if videolab_on; then printf 'mysql redis redis-video'; else printf 'mysql redis'; fi; }
apply_profiles() { if videolab_on; then export COMPOSE_PROFILES="${COMPOSE_PROFILES:+$COMPOSE_PROFILES,}videolab"; fi; }

frontend_services() { if [[ "${VV_SKIP_FRONTEND:-0}" == "1" && $LOCAL -eq 1 ]]; then printf ''; else printf 'web admin'; fi; }

# ---------------------------------------------------------------------------------------------------- khoá, trap
acquire_lock() {
    local lock="$VV_HOME/.deploy.lock.d" pid
    if ! mkdir "$lock" 2>/dev/null; then
        # R15: khoá mồ côi (deploy.sh bị SIGKILL): nếu tiến trình ghi trong khoá không còn sống thì gỡ và thử lại một lần.
        pid="$(cat "$lock/pid" 2>/dev/null || true)"
        if [[ "$pid" =~ ^[0-9]+$ ]] && ! kill -0 "$pid" 2>/dev/null; then
            warn "gỡ khoá mồ côi của tiến trình $pid (đã chết)"
            rm -f "$lock/pid"; rmdir "$lock" 2>/dev/null || true
            mkdir "$lock" 2>/dev/null || die "không lấy lại được khoá $lock"
        else
            die "đang có deploy khác chạy (pid ${pid:-?}) hoặc khoá cũ còn sót: $lock. Xác nhận không có tiến trình rồi xoá thư mục này."
        fi
    fi
    echo "$$" > "$lock/pid"
    LOCK_DIR="$lock"
}

mysql_exec() { # mysql_exec "SQL"  -> root trên DB ứng dụng, mật khẩu lấy từ môi trường container (không xuất hiện ở đây)
    dc exec -T -e VV_SQL="$1" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$VV_SQL"'
}

set_trust() { mysql_exec "SET GLOBAL log_bin_trust_function_creators = $1" >/dev/null; }

# Luôn trả log_bin_trust_function_creators về 0, dù migrate lỗi, Ctrl-C hay script thoát bất thường (trừ SIGKILL: `status` báo giá trị).
on_exit() {
    local rc=$?
    if [[ $TRUST_RAISED -eq 1 ]]; then
        if set_trust 0 2>/dev/null; then
            TRUST_RAISED=0
            log "log_bin_trust_function_creators đã trả về 0"
        else
            warn "KHÔNG trả được log_bin_trust_function_creators về 0. Chạy ngay: deploy.sh status, rồi SET GLOBAL log_bin_trust_function_creators=0 bằng root."
        fi
    fi
    if [[ $MAINT_ON -eq 1 ]]; then
        warn "Site đang ở chế độ bảo trì. Bật lại: docker compose -p $PROJECT run --rm --no-deps -T -e VV_OPTIMIZE=0 scheduler php artisan up"
    fi
    [[ -n "$LOCK_DIR" ]] && { rm -f "$LOCK_DIR/pid"; rmdir "$LOCK_DIR" 2>/dev/null; } || true
    exit "$rc"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

# ---------------------------------------------------------------------------------------------------- kiểm đầu vào
check_inputs() {
    local f mode
    [[ -f "$VV_HOME/.env" ]] || die "thiếu $VV_HOME/.env (mẫu: compose.env.example)"
    [[ -f "$(main_compose_file)" ]] || die "thiếu file compose $(main_compose_file)"
    [[ -w "$VV_HOME" ]] || die "$VV_HOME không ghi được bởi $(id -un): chủ thư mục phải là user chạy deploy.sh (README, S8)"
    for f in app migrate mysql web admin $(if videolab_on; then printf 'worker-video'; fi); do
        [[ -f "$VV_HOME/env/$f.env" ]] || die "thiếu $VV_HOME/env/$f.env"
        mode="$(file_mode "$VV_HOME/env/$f.env")"
        [[ "$mode" == "600" ]] || die "$VV_HOME/env/$f.env phải có quyền 0600 (đang $mode)"
    done
    for f in my.cnf redis.conf users.acl $(if videolab_on; then printf 'redis-video.conf users.video-instance.acl grants-worker.sql'; fi); do
        [[ -f "$VV_HOME/conf/$f" ]] || die "thiếu $VV_HOME/conf/$f"
    done
    dc config -q || die "docker compose config không hợp lệ"
}

compose_version() { # đọc `x-vv-compose-version: "N"` của file compose chính
    local line
    line="$(grep -m1 -E '^x-vv-compose-version:' "$(main_compose_file)" || true)"
    line="${line#*:}"
    line="${line//\"/}"
    printf '%s' "${line// /}"
}

image_ref() { dc config --images 2>/dev/null | grep -E "/vitaminvui-$1:" | head -n 1; }

image_label() { docker image inspect --format "{{ index .Config.Labels \"$2\" }}" "$1" 2>/dev/null; }

check_labels() { # check_labels <tag>
    local want_cv want_env ref lv
    want_cv="$(compose_version)"
    [[ -n "$want_cv" ]] || die "file compose thiếu x-vv-compose-version"
    for svc in backend $(worker_services); do
        ref="$(image_ref "$svc")"
        [[ -n "$ref" ]] || die "không xác định được image $svc"
        docker image inspect "$ref" >/dev/null 2>&1 || die "chưa có image $ref (pull lỗi hoặc tag sai)"
        lv="$(image_label "$ref" vv.compose-version)"
        [[ "$lv" == "$want_cv" ]] || die "image $ref có vv.compose-version='$lv', file compose đang là '$want_cv': dùng bản deploy.sh/compose cùng release với image"
        if [[ $LOCAL -eq 0 ]]; then
            lv="$(image_label "$ref" org.opencontainers.image.revision)"
            [[ "$lv" == "$1" ]] || die "image $ref có revision='$lv' khác tag $1"
        fi
    done
    want_env="${VV_ENV:-$(env_value VV_ENV)}"
    [[ -n "$want_env" ]] || die "thiếu VV_ENV"
    for svc in $(frontend_services); do
        ref="$(image_ref "$svc")"
        docker image inspect "$ref" >/dev/null 2>&1 || die "chưa có image $ref"
        lv="$(image_label "$ref" vv.env)"
        [[ "$lv" == "$want_env" ]] || die "image $ref có vv.env='$lv', server này là '$want_env' (build riêng cho từng môi trường)"
    done
}

# ---------------------------------------------------------------------------------------------------- migration
ensure_data_services() {
    log "đảm bảo $(data_services) đang chạy (không tạo lại nếu đã có)"
    # shellcheck disable=SC2046
    dc up -d --no-recreate --wait $(data_services) >/dev/null
}

image_migrations() { # tên migration (không .php) mà image hiện tại (IMAGE_TAG) biết
    dc run --rm --no-deps -T --entrypoint ls scheduler -1 database/migrations | sed -n 's/\.php$//p' | LC_ALL=C sort
}

applied_migrations() {
    local has
    has="$(mysql_exec "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'")" || return 1
    [[ "$has" == "0" ]] && return 0
    mysql_exec "SELECT migration FROM migrations" | LC_ALL=C sort
}

irreversible_in_image() { # tên migration có dấu VV-IRREVERSIBLE trong image
    dc run --rm --no-deps -T --entrypoint sh scheduler -c 'cd database/migrations && grep -l "VV-IRREVERSIBLE" ./*.php 2>/dev/null || true' | sed 's#^\./##; s#\.php$##' | LC_ALL=C sort
}

# Tính IMG_MIG (image biết), DB_MIG (DB đã chạy). Lỗi truy vấn thì DỪNG: để rỗng âm thầm sẽ báo sai "không còn migration chờ".
load_migration_sets() {
    IMG_MIG="$(image_migrations)" || die "không liệt kê được migration trong image"
    [[ -n "$IMG_MIG" ]] || die "image không có migration nào (sai image?)"
    DB_MIG="$(applied_migrations)" || die "không truy vấn được bảng migrations của MySQL"
}

pending_migrations() { comm -23 <(printf '%s\n' "$IMG_MIG") <(printf '%s\n' "$DB_MIG" | sed '/^$/d'); }
unknown_migrations() { comm -13 <(printf '%s\n' "$IMG_MIG") <(printf '%s\n' "$DB_MIG" | sed '/^$/d'); }

# ---------------------------------------------------------------------------------------------------- DB: grants, trigger, digest
db_name() { grep -E '^MYSQL_DATABASE=' "$VV_HOME/env/mysql.env" | tail -n 1 | cut -d= -f2-; }
worker_db_host() { local v="${VV_WORKER_DB_HOST:-$(env_value VV_WORKER_DB_HOST)}"; printf '%s' "${v:-10.231.12.%}"; }

# D4: quyền BẢNG của vv_worker_video chỉ cấp được khi bảng đã tồn tại => chạy sau mỗi migrate (GRANT idempotent), rồi tự kiểm.
post_migrate_grants() {
    local host db grants
    videolab_on || return 0
    host="$(worker_db_host)"; db="$(db_name)"
    [[ "$host" =~ ^[0-9A-Za-z.%_:-]{1,60}$ ]] || die "VV_WORKER_DB_HOST không hợp lệ"
    [[ "$db" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "MYSQL_DATABASE trong env/mysql.env không hợp lệ"
    sed -e "s/<DB>/$db/g" -e "s/<WORKER_HOST>/$host/g" "$VV_HOME/conf/grants-worker.sql" \
        | dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot' \
        || die "cấp quyền bảng cho vv_worker_video lỗi. User đã được tạo bằng grants-users.sql chưa (README bước 9)? Đã migrate xong nhưng CHƯA đổi container."
    grants="$(mysql_exec "SHOW GRANTS FOR 'vv_worker_video'@'$host'")" || die "không đọc được quyền của vv_worker_video@$host"
    grep -q 'vl_videos' <<<"$grants" || die "vv_worker_video thiếu quyền trên vl_videos"
    # Ngoài `GRANT USAGE ON *.*`, không được có quyền mức schema/toàn cục hay quyền trên users (worker không được đọc dữ liệu người dùng).
    if grep -v 'GRANT USAGE ON \*\.\*' <<<"$grants" | grep -Eq "ON \*\.\*|ON \`$db\`\.\*|\`users\`"; then
        die "vv_worker_video có quyền rộng hơn dự kiến (schema/toàn cục/users). Xem SHOW GRANTS và thu hồi."
    fi
    log "quyền vv_worker_video: chỉ vl_videos + failed_jobs"
}

# D5: trigger bất biến audit_logs mang DEFINER = user migrate. Xoá/đổi tên user -> lỗi 1449; thu hồi TRIGGER -> 1142. Không rollback tự động.
check_audit_triggers() {
    local cnt missing definer duser dhost g
    cnt="$(mysql_exec "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table = 'audit_logs'")" || { warn "không đếm được trigger audit_logs"; return 1; }
    if [[ "$cnt" != "2" ]]; then warn "audit_logs có $cnt trigger (phải là 2): bất biến audit_logs KHÔNG đảm bảo"; return 1; fi
    missing="$(mysql_exec "SELECT t.trigger_name FROM information_schema.triggers t WHERE t.trigger_schema = DATABASE() AND t.event_object_table = 'audit_logs' AND NOT EXISTS (SELECT 1 FROM mysql.user u WHERE CONCAT(u.user, '@', u.host) = t.definer)")" || return 1
    if [[ -n "$missing" ]]; then warn "DEFINER của trigger audit_logs không còn tồn tại: mọi UPDATE/DELETE audit_logs lỗi 1449. Tạo lại user (grants-users.sql) hoặc tạo lại trigger."; return 1; fi
    definer="$(mysql_exec "SELECT DISTINCT definer FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table = 'audit_logs' LIMIT 1")"
    duser="${definer%%@*}"; dhost="${definer#*@}"
    if ! [[ "$duser" =~ ^[A-Za-z0-9_]{1,32}$ && "$dhost" =~ ^[0-9A-Za-z.%_:-]{1,60}$ ]]; then warn "định dạng DEFINER lạ: không kiểm được"; return 1; fi
    g="$(mysql_exec "SHOW GRANTS FOR '$duser'@'$dhost'")" || return 1
    if ! grep -Eq 'TRIGGER|ALL PRIVILEGES' <<<"$g"; then warn "DEFINER $definer mất quyền TRIGGER: UPDATE/DELETE audit_logs lỗi 1142, audit:purge hỏng."; return 1; fi
    return 0
}

images_present() { # mọi image sẽ chạy (theo IMAGE_TAG hiện hành) đều đã có trên server
    local svc
    for svc in backend $(worker_services) $(frontend_services); do
        docker image inspect "$(image_ref "$svc")" >/dev/null 2>&1 || return 1
    done
}

# S2: ngoài --local, digest của MỌI image sẽ chạy là BẮT BUỘC (dán từ images-*.lock của CI, lấy qua kênh khác server). Một PR cùng repo có thể ghi đè
# tag <sha> trên GHCR; digest (và cosign) là lớp chặn duy nhất ở phía server. Thiếu/sai định dạng -> dừng trước khi pull, không đổi gì.
digest_vars() { # in "<svc>:<biến>" cho image sẽ chạy
    printf 'backend:IMAGE_DIGEST_BACKEND\n'
    if videolab_on; then printf 'worker-video:IMAGE_DIGEST_WORKER_VIDEO\n'; fi
    if [[ -n "$(frontend_services)" ]]; then printf 'web:IMAGE_DIGEST_WEB\nadmin:IMAGE_DIGEST_ADMIN\n'; fi
}

digest_of() { local var="$1"; printf '%s' "${!var:-$(env_value "$var")}"; }

require_digests() {
    local pair var want
    while read -r pair; do
        var="${pair#*:}"
        want="$(digest_of "$var")"
        if [[ -z "$want" ]]; then
            [[ $LOCAL -eq 1 ]] && continue
            die "thiếu $var: digest bắt buộc khi không --local (dán các dòng IMAGE_DIGEST_* từ images-*.lock của run CI vào $VV_HOME/.env). Chưa đổi gì."
        fi
        [[ "$want" =~ ^sha256:[0-9a-f]{64}$ ]] || die "$var không đúng dạng sha256:<64 hex>"
    done < <(digest_vars)
}

check_digests() {
    local pair svc var want ref
    while read -r pair; do
        svc="${pair%%:*}"; var="${pair#*:}"
        want="$(digest_of "$var")"
        [[ -n "$want" ]] || continue
        ref="$(image_ref "$svc")"
        docker image inspect --format '{{join .RepoDigests "\n"}}' "$ref" | grep -qF "@$want" || die "digest image $svc ($ref) khác $var: dừng, chưa đổi gì"
    done < <(digest_vars)
}

# Tuỳ chọn VV_COSIGN_VERIFY=1: chữ ký keyless của workflow main (docs/ops/ci.md). Cần cosign và `docker login ghcr.io` trên server.
verify_signatures() {
    local pair svc var want repo
    [[ $LOCAL -eq 1 ]] && return 0   # --local (smoke): ảnh build tại máy, không có chữ ký
    [[ "${VV_COSIGN_VERIFY:-$(env_value VV_COSIGN_VERIFY)}" == "1" ]] || return 0
    command -v cosign >/dev/null || die "VV_COSIGN_VERIFY=1 nhưng server không có cosign"
    while read -r pair; do
        svc="${pair%%:*}"; var="${pair#*:}"
        want="$(digest_of "$var")"
        [[ -n "$want" ]] || continue
        repo="$(image_ref "$svc")"; repo="${repo%%:*}"
        cosign verify "$repo@$want" \
            --certificate-identity-regexp '^https://github.com/ngocgiangit124/AI_KhoaHoc/\.github/workflows/(ci|release-images)\.yml@refs/heads/main$' \
            --certificate-oidc-issuer https://token.actions.githubusercontent.com >/dev/null 2>&1 || die "cosign verify lỗi cho $svc ($repo@$want): chữ ký không do workflow trên main ký. Dừng, chưa đổi gì."
        log "cosign: $svc đã ký bởi workflow main"
    done < <(digest_vars)
}

# ---------------------------------------------------------------------------------------------------- smoke
smoke_get() { # smoke_get <host> <path> -> in body, thoát !=0 nếu HTTP != 2xx
    local host="$1" path="$2"
    if [[ -n "${VV_SMOKE_BASE:-}" ]]; then
        curl -fsS --max-time 15 -H "Host: $host" "${VV_SMOKE_BASE}${path}"
    else
        curl -fsS --max-time 15 --resolve "$host:443:127.0.0.1" "https://$host$path"
    fi
}

http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 90 "$1" || true; }

run_smoke() {
    local ok=1 api_host body code cmd missing pending
    api_host="$(app_env_value APP_API_HOST)"
    [[ -n "$api_host" ]] || { warn "không đọc được APP_API_HOST từ env/app.env"; return 1; }

    if smoke_get "$api_host" /up >/dev/null; then log "smoke: /up 200"; else warn "smoke: /up lỗi (cần allow 127.0.0.1 trong vv-api-common.conf)"; ok=0; fi

    if body="$(smoke_get "$api_host" /api/v1/config/public)" && printf '%s' "$body" | grep -Eq '"paid_checkout_enabled"[[:space:]]*:[[:space:]]*false'; then
        log "smoke: /api/v1/config/public có paid_checkout_enabled=false"
    else
        warn "smoke: /api/v1/config/public lỗi hoặc paid_checkout_enabled khác false"; ok=0
    fi

    if [[ -n "$(frontend_services)" ]]; then
        code="$(http_code "http://127.0.0.1:${VV_WEB_PORT:-3000}/khoa-hoc")"
        if [[ "$code" == "200" ]]; then log "smoke: web /khoa-hoc 200"; else warn "smoke: web /khoa-hoc trả $code"; ok=0; fi
        code="$(http_code "http://127.0.0.1:${VV_ADMIN_PORT:-3001}/dang-nhap")"
        if [[ "$code" == "200" ]]; then log "smoke: admin /dang-nhap 200"; else warn "smoke: admin /dang-nhap trả $code"; ok=0; fi
    fi

    cmd="$(dc exec -T scheduler php artisan schedule:list 2>&1 || true)"
    missing=""
    # videolab:notify, videolab:cleanup chỉ bắt buộc khi VideoLab bật (V1/Bunny không dùng).
    for c in $REQUIRED_SCHEDULE $(if videolab_on; then printf 'videolab:notify videolab:cleanup'; fi); do
        printf '%s' "$cmd" | grep -q -- "$c" || missing="$missing $c"
    done
    if [[ -z "$missing" ]]; then log "smoke: schedule:list đủ lệnh"; else warn "smoke: schedule:list thiếu:$missing"; ok=0; fi

    load_migration_sets
    pending="$(pending_migrations | wc -l | tr -d ' ')"
    if [[ "$pending" == "0" ]]; then log "smoke: migrate:status không còn migration chờ"; else warn "smoke: còn $pending migration chờ"; ok=0; fi

    [[ $ok -eq 1 ]]
}

print_recovery() { # print_recovery <mode: migrate|swap> <tag cũ>
    local mode="$1" prev="$2"
    {
        echo
        echo "================ KHÔNG tự rollback. Người vận hành quyết định ================"
        if [[ "$mode" == "migrate" ]]; then
            echo "Container VẪN là bản cũ ${prev:+($prev)}; .env chưa đổi: KHÔNG cần và KHÔNG nên rollback code. Xử lý DB:"
            echo "  - migration đã áp một phần? xem bảng migrations; sửa migration rồi deploy lại, hoặc khôi phục SNAPSHOT nhà cung cấp đã chụp trước deploy (khôi phục cả máy, mất dữ liệu phát sinh sau đó; PO quyết)."
        else
            echo "Rollback code (không chạy migrate):  $0 rollback${prev:+ $prev}   # về đúng tag cũ ${prev:-(PREVIOUS_TAG)}; .env đã ghi tag mới nên PREVIOUS_TAG = bản cũ"
            echo "Nếu DB đã có migration mà bản cũ không biết, rollback sẽ dừng; chỉ thêm --force-schema-ahead khi chắc schema mới tương thích ngược."
            echo "Migration không hoàn tác (VV-IRREVERSIBLE): khôi phục snapshot nhà cung cấp chụp trước deploy (README mục 'Backup = snapshot'), PO quyết định."
        fi
        echo "Trạng thái migrate:  docker compose -p $PROJECT exec mysql sh   (rồi: mysql -uroot -p <db> -e \"SELECT migration FROM migrations ORDER BY id DESC LIMIT 5\")"
        echo "Nếu site đang bảo trì:  docker compose -p $PROJECT run --rm --no-deps -T -e VV_OPTIMIZE=0 scheduler php artisan up"
        echo "Job lỗi sau rollback:  docker compose -p $PROJECT exec -T scheduler php artisan queue:failed"
        echo "=============================================================================="
    } >&2
}

# ---------------------------------------------------------------------------------------------------- ghi nhận
record_release() { # record_release <hành động> <tag cũ> <tag mới> <migrate yes|no> <kết quả>
    local who
    who="${SUDO_USER:-$(id -un)}"
    printf '%s %s %s %s->%s migrate=%s result=%s%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$who" "$1" "${2:-none}" "$3" "$4" "$5" "${IMAGE_DIGEST_BACKEND:+ digest_backend=$IMAGE_DIGEST_BACKEND}" >> "$VV_HOME/releases.log"
}

prune_old_images() {
    local keep=3 repo tag n current previous
    [[ $LOCAL -eq 1 ]] && return 0
    current="$(env_value IMAGE_TAG)"; previous="$(env_value PREVIOUS_TAG)"
    for repo in vitaminvui-backend vitaminvui-worker-video vitaminvui-web vitaminvui-admin; do # worker-video: giữ kể cả khi VideoLab tắt (image cũ)
        n=0
        while read -r tag; do
            [[ -z "$tag" || "$tag" == "<none>" ]] && continue
            n=$((n + 1))
            [[ $n -le $keep ]] && continue
            [[ -n "$current" && "$tag" == *"$current" ]] && continue
            [[ -n "$previous" && "$tag" == *"$previous" ]] && continue
            docker rmi "$(env_value VV_REGISTRY)/$repo:$tag" >/dev/null 2>&1 || true
        done < <(docker image ls "$(env_value VV_REGISTRY)/$repo" --format '{{.Tag}}')
    done
}

# ---------------------------------------------------------------------------------------------------- deploy
do_deploy() {
    local old_tag pending_names irreversible hit app_services
    old_tag="$(env_value IMAGE_TAG)"
    if [[ $LOCAL -eq 1 ]]; then
        [[ "$SHA" =~ ^[A-Za-z0-9._-]{1,40}$ ]] || die "tag local không hợp lệ"
    else
        [[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "<sha> phải là 40 ký tự hex của commit"
    fi
    export IMAGE_TAG="$SHA"
    if [[ -z "${VV_ENV:-}" ]]; then VV_ENV="$(env_value VV_ENV)"; fi
    export VV_ENV
    app_services="php queue scheduler $(frontend_services)"

    log "== deploy $SHA (tag cũ: ${old_tag:-chưa có}) =="
    check_inputs
    require_digests

    if [[ $LOCAL -eq 0 ]]; then
        log "pull image"
        # shellcheck disable=SC2046  # danh sách tên service, cố ý tách từ
        dc pull php $(worker_services) $(frontend_services)
    fi
    check_labels "$SHA"
    check_digests
    verify_signatures

    log "preflight (ProductionConfigGuard) với env app$(if videolab_on; then printf " và env worker-video"; fi)"
    if ! dc run --rm --no-deps -T -e VV_OPTIMIZE=0 scheduler php artisan about --only=environment >/dev/null; then
        dc run --rm --no-deps -T -e VV_OPTIMIZE=0 scheduler php artisan about --only=environment >&2 || true
        die "preflight app thất bại: guard cấu hình từ chối env/app.env. Chưa đổi gì trên server."
    fi
    if videolab_on && ! dc run --rm --no-deps -T worker-video php artisan about --only=environment >/dev/null; then
        dc run --rm --no-deps -T worker-video php artisan about --only=environment >&2 || true
        die "preflight worker-video thất bại: guard cấu hình từ chối env/worker-video.env. Chưa đổi gì trên server."
    fi

    ensure_data_services
    # D3: tự chữa nếu lần trước deploy.sh bị SIGKILL khi cờ đang bật.
    set_trust 0 || warn "không đặt được log_bin_trust_function_creators=0"

    load_migration_sets
    pending_names="$(pending_migrations)"
    if [[ -n "$pending_names" ]]; then
        log "migration chờ: $(printf '%s\n' "$pending_names" | wc -l | tr -d ' ')"
        irreversible="$(irreversible_in_image)"
        hit="$(comm -12 <(printf '%s\n' "$pending_names") <(printf '%s\n' "$irreversible"))"
        if [[ -n "$hit" && $OPT_ACK -eq 0 ]]; then
            printf '%s\n' "$hit" | sed 's/^/  - /' >&2
            die "có migration KHÔNG hoàn tác (VV-IRREVERSIBLE) đang chờ. Rollback qua migration này = khôi phục snapshot (mất dữ liệu phát sinh sau đó). Chạy lại với --ack-irreversible nếu PO đã chấp nhận."
        fi
    else
        log "không có migration chờ"
    fi

    # Backup = snapshot ổ đĩa của nhà cung cấp (PO 2026-10-10: khuyến nghị, KHÔNG bắt buộc, không còn cờ xác nhận). Chỉ nhắc khi có migration chờ.
    if [[ -n "$pending_names" ]]; then
        warn "có migration chờ: schema sẽ đổi và khó hoàn tác. KHUYẾN NGHỊ chụp snapshot nhà cung cấp trước (nếu chưa)."
    fi

    if [[ -n "$pending_names" ]]; then
        if [[ $OPT_MAINT -eq 1 ]]; then
            log "bật bảo trì (php artisan down)"
            dc run --rm --no-deps -T -e VV_OPTIMIZE=0 scheduler php artisan down --retry=60 >/dev/null
            MAINT_ON=1
        fi
        log "migrate bằng user vv_migrate (log_bin_trust_function_creators bật tạm)"
        set_trust 1
        TRUST_RAISED=1
        HAD_MIGRATE="yes"
        if ! dc run --rm -T migrate; then
            set_trust 0 && TRUST_RAISED=0
            record_release deploy "$old_tag" "$SHA" "yes" "migrate-failed"
            print_recovery migrate "$old_tag"
            die "migrate lỗi. Container đang chạy vẫn là bản cũ (chưa đổi). DB có thể đang ở trạng thái giữa chừng."
        fi
        set_trust 0
        TRUST_RAISED=0
        log "log_bin_trust_function_creators = $(mysql_exec 'SELECT @@GLOBAL.log_bin_trust_function_creators')"
    fi

    post_migrate_grants
    check_audit_triggers || warn "!!! BẤT BIẾN audit_logs CÓ VẤN ĐỀ (xem cảnh báo trên). Không rollback tự động; xử lý trước khi mở lại cho người dùng."

    # R1: ghi tag NGAY TRƯỚC khi đổi container ("đã cam kết") để .env luôn phản ánh thứ đang chạy; deploy lỗi từ đây thì `rollback`
    # đọc đúng PREVIOUS_TAG = bản cũ. Lỗi ở các bước trên (preflight, kiểm cờ, migrate) KHÔNG ghi gì.
    if [[ "$old_tag" != "$SHA" && -n "$old_tag" ]]; then set_env_value PREVIOUS_TAG "$old_tag"; fi
    set_env_value IMAGE_TAG "$SHA"

    log "đổi container: $app_services"
    # shellcheck disable=SC2086
    if ! dc up -d --no-deps --wait $app_services; then
        record_release deploy "$old_tag" "$SHA" "$HAD_MIGRATE" "swap-failed"
        print_recovery swap "$old_tag"
        die "đổi container lỗi"
    fi

    if [[ $MAINT_ON -eq 1 ]]; then
        dc exec -T scheduler php artisan up >/dev/null && MAINT_ON=0
        log "đã tắt bảo trì"
    fi

    log "smoke"
    if ! run_smoke; then
        record_release deploy "$old_tag" "$SHA" "$HAD_MIGRATE" "smoke-failed"
        print_recovery swap "$old_tag"
        die "smoke lỗi. Container mới đang chạy."
    fi

    if videolab_on; then case "$OPT_WORKER" in
        graceful)
            log "recreate worker-video (SIGTERM, chờ tối đa 3660s cho job ffmpeg đang chạy)"
            dc up -d --no-deps worker-video
            ;;
        skip)
            warn "worker-video giữ tag cũ (--worker-video=skip). Nhớ recreate sau: dc up -d --no-deps worker-video"
            ;;
    esac; fi

    record_release deploy "$old_tag" "$SHA" "$HAD_MIGRATE" "ok"
    prune_old_images
    log "== xong: $SHA =="
}

# ---------------------------------------------------------------------------------------------------- rollback
do_rollback() {
    local cur prev unknown
    cur="$(env_value IMAGE_TAG)"; prev="${SHA:-$(env_value PREVIOUS_TAG)}"
    # R16: PREVIOUS_TAG có thể là bản lỗi (deploy lỗi rồi deploy bản sửa thành công): quay về bản KHÁC thì truyền tag tường minh. Tra releases.log.
    [[ -n "$prev" ]] || die "PREVIOUS_TAG trống: không có bản trước để rollback (hoặc truyền <tag>)"
    [[ -n "$cur" ]] || die "IMAGE_TAG trống"
    # S8: tag đọc từ .env đi thẳng vào nội suy compose: kiểm định dạng như `deploy`.
    if [[ $LOCAL -eq 1 ]]; then
        [[ "$prev" =~ ^[A-Za-z0-9._-]{1,40}$ && "$cur" =~ ^[A-Za-z0-9._-]{1,40}$ ]] || die "PREVIOUS_TAG/IMAGE_TAG trong .env không hợp lệ"
    else
        [[ "$prev" =~ $TAG_RE && "$cur" =~ $TAG_RE ]] || die "PREVIOUS_TAG/IMAGE_TAG trong .env không phải 40 ký tự hex: dừng"
    fi
    if [[ -z "${VV_ENV:-}" ]]; then VV_ENV="$(env_value VV_ENV)"; fi
    export VV_ENV
    export IMAGE_TAG="$prev"
    log "== rollback $cur -> $prev (KHÔNG chạy migrate) =="
    check_inputs
    if [[ $LOCAL -eq 0 ]]; then
        # R17: image tag đích còn trên server (đã pull và kiểm digest ở lần deploy cũ) thì KHÔNG pull lại. Thiếu thì phải kéo về: bắt buộc digest
        # như deploy (không để hở S2 ở đường khẩn cấp): dán IMAGE_DIGEST_* của tag đó vào .env trước.
        if images_present; then
            log "image $prev còn trên server: không pull"
        else
            require_digests
            # shellcheck disable=SC2046,SC2086
            dc pull php $(worker_services) $(frontend_services) || die "pull $prev lỗi"
            check_digests
            verify_signatures
        fi
    fi
    check_labels "$prev"

    ensure_data_services
    load_migration_sets
    unknown="$(unknown_migrations)"
    if [[ -n "$unknown" ]]; then
        printf '%s\n' "$unknown" | sed 's/^/  - /' >&2
        if [[ $OPT_FORCE_SCHEMA -eq 0 ]]; then
            die "DB có migration mà image $prev không biết (danh sách trên). Chỉ rollback tiếp khi chắc schema mới tương thích ngược: thêm --force-schema-ahead."
        fi
        warn "--force-schema-ahead: tiếp tục với schema mới hơn code"
    fi

    # shellcheck disable=SC2046,SC2086
    dc up -d --no-deps --wait php queue scheduler $(frontend_services) || { print_recovery swap ""; die "đổi container về $prev lỗi"; }
    if videolab_on; then case "$OPT_WORKER" in
        graceful) log "recreate worker-video về $prev"; dc up -d --no-deps worker-video ;;
        skip) warn "worker-video giữ nguyên tag hiện tại" ;;
    esac; fi

    set_env_value IMAGE_TAG "$prev"
    set_env_value PREVIOUS_TAG "$cur"
    record_release rollback "$cur" "$prev" "no" "ok"
    log "smoke"
    run_smoke || warn "smoke sau rollback có lỗi; kiểm tay"
    log "== xong rollback. Xem failed_jobs (job do code mới tạo có thể lỗi ở code cũ): php artisan queue:failed =="
}

do_status() {
    local cur prev rc=0 trust
    cur="$(env_value IMAGE_TAG)"; prev="$(env_value PREVIOUS_TAG)"
    echo "VV_ENV=$(env_value VV_ENV) IMAGE_TAG=${cur:-none} PREVIOUS_TAG=${prev:-none}"
    export IMAGE_TAG="${cur:-none}"
    if [[ -z "${VV_ENV:-}" ]]; then VV_ENV="$(env_value VV_ENV)"; fi
    export VV_ENV
    dc ps -a --format 'table {{.Service}}\t{{.Image}}\t{{.State}}\t{{.Health}}' || true
    trust="$(mysql_exec 'SELECT @@GLOBAL.log_bin_trust_function_creators' 2>/dev/null || echo '?')"
    echo "log_bin_trust_function_creators = $trust  (phải là 0)"
    [[ "$trust" == "0" ]] || { warn "log_bin_trust_function_creators khác 0"; rc=1; }
    if check_audit_triggers; then echo "trigger audit_logs: đủ 2, DEFINER tồn tại và còn quyền TRIGGER"; else rc=1; fi
    [[ -f "$VV_HOME/releases.log" ]] && { echo "--- releases.log (5 dòng cuối)"; tail -n 5 "$VV_HOME/releases.log"; }
    return $rc
}

# ---------------------------------------------------------------------------------------------------- main
usage() {
    sed -n '2,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
}

[[ $# -ge 1 ]] || usage
while [[ $# -gt 0 ]]; do
    case "$1" in
        deploy|rollback|status) [[ -z "$ACTION" ]] || usage; ACTION="$1" ;;
        compose) [[ -z "$ACTION" ]] || usage; ACTION=compose; shift; break ;;
        --local) LOCAL=1 ;;
        --maintenance) OPT_MAINT=1 ;;
        --ack-irreversible) OPT_ACK=1 ;;
        --force-schema-ahead) OPT_FORCE_SCHEMA=1 ;;
        --worker-video=graceful|--worker-video=skip) OPT_WORKER="${1#*=}" ;;
        -*) usage ;;
        *) if [[ ( "$ACTION" == "deploy" || "$ACTION" == "rollback" ) && -z "$SHA" ]]; then SHA="$1"; else usage; fi ;;
    esac
    shift
done

[[ -n "$ACTION" ]] || usage
[[ -d "$VV_HOME" ]] || die "không có thư mục $VV_HOME"
command -v docker >/dev/null || die "thiếu docker"

apply_profiles

if [[ "$ACTION" != "status" && ! -w "$VV_HOME" ]]; then
    die "$VV_HOME không ghi được bởi $(id -un): chủ thư mục phải là user chạy deploy.sh (khoá, .env tạm, releases.log; README, S8)"
fi

case "$ACTION" in
    deploy) [[ -n "$SHA" ]] || usage; acquire_lock; do_deploy ;;
    rollback) acquire_lock; do_rollback ;;
    status) do_status ;;
    compose) export IMAGE_TAG="${IMAGE_TAG:-$(env_value IMAGE_TAG)}"; export IMAGE_TAG="${IMAGE_TAG:-none}"; if [[ -z "${VV_ENV:-}" ]]; then VV_ENV="$(env_value VV_ENV)"; fi; export VV_ENV; dc "$@" ;;
esac
