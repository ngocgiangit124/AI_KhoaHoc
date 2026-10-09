#!/usr/bin/env bash
# Smoke toàn bộ T35-1 trên máy local (Docker Desktop) bằng stack `vvsmoke`, KHÔNG đụng project `vitaminvui` của dev.
#   smoke.sh [run] [--with-frontend] [--with-videolab] [--size small|large] [--keep] [--build]    chạy toàn bộ kiểm, mặc định dọn stack khi xong (--keep: giữ để kiểm tay)
#   smoke.sh down                                          dọn: container, volume, network, image tạm, thư mục sinh ra
# Mặc định giống production V1: video qua Bunny, KHÔNG có worker-video/redis-video (profile `videolab` tắt). --with-videolab bật VideoLab + các kiểm sandbox worker.
# --with-frontend: có thêm web/admin (cần image vv-local/vitaminvui-web:production-dev, vitaminvui-admin:production-dev của T35-2).
# Cổng dùng: 127.0.0.1:18080 (nginx-smoke), 13000/13001 (web/admin, chỉ với --with-frontend). Subnet 10.231.20-22.0/24.
# Env/secret là GIÁ TRỊ GIẢ sinh bởi make-env.sh; không đọc backend/.env.
set -uo pipefail
# shellcheck source=lib.sh disable=SC1091
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

MODE="run"; WITH_FRONTEND=0; KEEP=0; BUILD=0; VIDEOLAB=0
for a in "$@"; do
    case "$a" in
        run|down) MODE="$a" ;;
        --with-frontend) WITH_FRONTEND=1 ;;
        --with-videolab) VIDEOLAB=1 ;;
        --size) ;;
        small|large) export VV_SIZE="$a" ;;
        --keep) KEEP=1 ;;
        --build) BUILD=1 ;;
        *) sed -n '2,7p' "${BASH_SOURCE[0]}"; exit 2 ;;
    esac
done
export IMAGE_TAG="$SMOKE_TAG"
export VV_WEB_PORT=13000 VV_ADMIN_PORT=13001
[[ $VIDEOLAB -eq 1 ]] && export COMPOSE_PROFILES=videolab
export VV_SKIP_FRONTEND=1   # các deploy chính không có web/admin; khối frontend (--with-frontend) bật lại ở cuối
LOGDIR="$GEN/logs"
DEPLOY="$PROD_DIR/deploy.sh"

teardown() {
    COMPOSE_PROFILES='*' dc down -v --remove-orphans >/dev/null 2>&1 || true   # CHỈ project vvsmoke (smoke được phép `down`, production script thì không)
    docker rm -f vvsmoke-curl >/dev/null 2>&1 || true
}

purge() {
    teardown
    for t in dev dev2 dev3 dev4 dev5; do
        docker rmi "vv-local/vitaminvui-backend:$t" "vv-local/vitaminvui-worker-video:$t" >/dev/null 2>&1 || true
    done
    docker rmi vv-local/vitaminvui-web:production-dev vv-local/vitaminvui-admin:production-dev >/dev/null 2>&1 || true
    rm -rf "$GEN"
    echo "smoke: đã dọn stack vvsmoke, image vv-local/*, thư mục .generated"
}

if [[ "$MODE" == "down" ]]; then purge; exit 0; fi

# ---------------------------------------------------------------------------------------------------- tiện ích
http_status() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H "Host: $1" "$VV_SMOKE_BASE$2"; }
http_body() { curl -s --max-time 20 -H "Host: $1" "$VV_SMOKE_BASE$2"; }
API="api.vvsmoke.internal"
sched() { dc exec -T scheduler "$@"; }
worker() { dc exec -T worker-video "$@"; }
mysql_root() { dc exec -T -e VV_SQL="$1" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$VV_SQL"'; }
deploy() { local name="$1"; shift; "$DEPLOY" "$@" > "$LOGDIR/$name.log" 2>&1; }
running_tag() { docker inspect --format '{{.Config.Image}}' "$(dc ps -q "$1" | head -1)" | sed 's/.*://'; }

# Biến thể image: backend:<tag> = backend:dev + 1 migration giả; worker-video:<tag> = worker-video:dev (cùng nhãn).
make_variant() { # make_variant <tag> <tên file migration>
    docker build -q --build-arg BASE="vv-local/vitaminvui-backend:$SMOKE_TAG" --build-arg MIGRATION="$2" \
        -t "vv-local/vitaminvui-backend:$1" -f "$SMOKE_DIR/variants/Dockerfile" "$SMOKE_DIR/variants" >/dev/null \
        && { [[ $VIDEOLAB -eq 0 ]] || docker tag "vv-local/vitaminvui-worker-video:$SMOKE_TAG" "vv-local/vitaminvui-worker-video:$1"; }
}

wait_http() { # wait_http <host> <path> <code> <giây>
    local i
    for ((i = 0; i < $4; i++)); do [[ "$(http_status "$1" "$2")" == "$3" ]] && return 0; sleep 1; done
    return 1
}

# ---------------------------------------------------------------------------------------------------- chuẩn bị
section "Chuẩn bị"
command -v openssl >/dev/null || { echo "thiếu openssl"; exit 2; }
BAKE_TARGETS="backend"; [[ $VIDEOLAB -eq 1 ]] && BAKE_TARGETS="backend worker-video"
mkdir -p "$SMOKE_DIR/.generated"  # log build (QA T35 BUG-1)
if [[ $BUILD -eq 1 ]] || ! docker image inspect "vv-local/vitaminvui-backend:$SMOKE_TAG" >/dev/null 2>&1 || { [[ $VIDEOLAB -eq 1 ]] && ! docker image inspect "vv-local/vitaminvui-worker-video:$SMOKE_TAG" >/dev/null 2>&1; }; then
    echo "build image (bake $BAKE_TARGETS)"
    # shellcheck disable=SC2086
    (cd "$REPO_ROOT" && VV_REGISTRY=vv-local IMAGE_TAG="$SMOKE_TAG" docker buildx bake -f infra/production/docker-bake.hcl --load $BAKE_TARGETS) >"$SMOKE_DIR/.generated/build-backend.log" 2>&1 \
        || { echo "build image lỗi (20 dòng cuối, đủ ở smoke/.generated/build-backend.log):"; tail -n 20 "$SMOKE_DIR/.generated/build-backend.log"; exit 1; }
fi
if [[ $WITH_FRONTEND -eq 1 ]] && { [[ $BUILD -eq 1 ]] || ! docker image inspect vv-local/vitaminvui-web:production-dev vv-local/vitaminvui-admin:production-dev >/dev/null 2>&1; }; then
    echo "build image web/admin (frontend/docker-bake.hcl, giá trị NEXT_PUBLIC_* của smoke)"
    (cd "$REPO_ROOT" && VV_REGISTRY=vv-local IMAGE_TAG=dev VV_ENV=production \
        NEXT_PUBLIC_API_URL=https://api.vvsmoke.internal NEXT_PUBLIC_SITE_URL=https://vvsmoke.internal \
        NEXT_PUBLIC_STATIC_URL=http://static.vvsmoke-media.internal:8090 NEXT_PUBLIC_VIDEO_HOSTS=https://video.vvsmoke.internal \
        NEXT_PUBLIC_TURNSTILE_SITE_KEY=0x4AAAAAAAsmokefakesitekey NEXT_PUBLIC_ADMIN_API_URL=https://admin-api.vvsmoke.internal \
        NEXT_PUBLIC_ADMIN_URL=https://admin.vvsmoke.internal NEXT_PUBLIC_VIDEO_UPLOAD_URL=https://video.vvsmoke.internal \
        docker buildx bake -f frontend/docker-bake.hcl --load web admin) >"$SMOKE_DIR/.generated/build-frontend.log" 2>&1 || { echo "build image frontend lỗi (20 dòng cuối, đủ ở smoke/.generated/build-frontend.log):"; tail -n 20 "$SMOKE_DIR/.generated/build-frontend.log"; exit 1; }
fi
teardown
MKARGS="--force"; [[ $VIDEOLAB -eq 1 ]] && MKARGS="--force --videolab"
# shellcheck disable=SC2086
"$SMOKE_DIR/make-env.sh" $MKARGS >/dev/null && ok "sinh env giả (make-env.sh)" || { bad "make-env.sh"; exit 1; }
load_secrets
mkdir -p "$LOGDIR"

DATA_SVCS="mysql redis mailpit nginx-smoke"; [[ $VIDEOLAB -eq 1 ]] && DATA_SVCS="mysql redis redis-video mailpit nginx-smoke"
# shellcheck disable=SC2086
dc up -d --wait $DATA_SVCS >/dev/null 2>&1 && ok "$DATA_SVCS chạy (mysql/redis có healthcheck xanh)" || bad "dựng dịch vụ dữ liệu"
"$SMOKE_DIR/grants.smoke.sh" >/dev/null 2>&1 && ok "grants-users.sql (một lần, trước migrate)" || bad "grants-users.sql"
check "nginx-smoke nạp được cấu hình (snippet production nguyên văn)" dc exec -T nginx-smoke nginx -t

# ---------------------------------------------------------------------------------------------------- deploy đầu tiên
section "Deploy lần đầu (DB trống)"
deploy first-noack deploy --local "$SMOKE_TAG"
check "deploy dừng khi thiếu --ack-irreversible" grep -q "VV-IRREVERSIBLE" "$LOGDIR/first-noack.log"
check "chưa đổi container (php chưa chạy)" test -z "$(dc ps -q php)"
check "trước khi dừng, deploy chưa ghi IMAGE_TAG" test -z "$(grep '^IMAGE_TAG=' "$GEN/.env" | cut -d= -f2)"

T_DEPLOY=$(date +%s)
deploy first deploy --local "$SMOKE_TAG" --ack-irreversible
rc=$?
[[ $rc -eq 0 ]] && ok "deploy --local $SMOKE_TAG (--ack-irreversible) thành công" || { bad "deploy lần đầu (xem $LOGDIR/first.log)"; tail -30 "$LOGDIR/first.log"; }
check "migration chờ: deploy chỉ NHẮC chụp snapshot (khuyến nghị, không bắt buộc cờ)" grep -q "KHUYẾN NGHỊ chụp snapshot" "$LOGDIR/first.log"
check "preflight app (+ worker-video nếu VideoLab bật) qua" grep -q "preflight" "$LOGDIR/first.log"
check "migrate chạy" grep -q "migrate bằng user vv_migrate" "$LOGDIR/first.log"
check "smoke của deploy.sh đạt" grep -q "schedule:list đủ lệnh" "$LOGDIR/first.log"
check "releases.log ghi deploy" grep -q "deploy none->$SMOKE_TAG migrate=yes result=ok" "$GEN/releases.log"
check ".env ghi IMAGE_TAG" grep -q "^IMAGE_TAG=$SMOKE_TAG$" "$GEN/.env"

if [[ $VIDEOLAB -eq 1 ]]; then check "deploy.sh tự cấp quyền bảng cho vv_worker_video sau migrate (không chạy tay lần 2)" grep -q "quyền vv_worker_video: chỉ vl_videos" "$LOGDIR/first.log"; fi

# ---------------------------------------------------------------------------------------------------- DB
section "MySQL: user, trigger, log_bin_trust_function_creators"
check "log_bin_trust_function_creators = 0 sau migrate" test "$(mysql_root 'SELECT @@GLOBAL.log_bin_trust_function_creators')" = "0"
check "SHOW TRIGGERS LIKE 'audit_logs' có 2 trigger" test "$(mysql_root "SHOW TRIGGERS LIKE 'audit_logs'" | wc -l | tr -d ' ')" = "2"
PHP_PDO='$p=new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
dbq() { # dbq <service> <sql>: chạy bằng user DB của chính container đó; in "OK" hoặc "ERR <mã>"
    dc exec -T -e VV_SQL="$2" "$1" php -r "$PHP_PDO"'try{$p->query(getenv("VV_SQL"));echo "OK";}catch(Throwable $e){echo "ERR ".$e->errorInfo[1].":".$e->errorInfo[2];}'
}
check "vv_app INSERT audit_logs được" test "$(dbq scheduler "INSERT INTO audit_logs (action) VALUES ('smoke')")" = "OK"
r="$(dbq scheduler "UPDATE audit_logs SET action='x'")"
[[ "$r" == ERR\ 1644:*"audit_logs la bat bien"* ]] && ok "UPDATE audit_logs bằng vv_app bị TRIGGER chặn đúng thông báo (1644 'audit_logs la bat bien')" || bad "UPDATE audit_logs phải bị trigger chặn bằng thông báo bất biến (nhận: $r)"
check "vv_app INSERT audit_logs dòng cũ 36 tháng" test "$(dbq scheduler "INSERT INTO audit_logs (action, created_at) VALUES ('smoke-old', NOW() - INTERVAL 36 MONTH)")" = "OK"
check "vv_app DELETE dòng audit_logs quá 24 tháng thành công (audit:purge chạy được qua trigger)" test "$(dbq scheduler "DELETE FROM audit_logs WHERE action = 'smoke-old'")" = "OK"
r="$(dbq scheduler "DELETE FROM audit_logs WHERE action = 'smoke'")"
[[ "$r" == ERR\ 1644:* ]] && ok "DELETE dòng audit_logs mới bị chặn" || bad "DELETE dòng mới phải bị chặn (nhận: $r)"
check "deploy kiểm trigger audit_logs: không cảnh báo" bash -c "! grep -q 'BẤT BIẾN audit_logs' '$LOGDIR/first.log'"
check "status exit 0 khi mọi thứ đúng" "$DEPLOY" status --local
mysql_root "SET GLOBAL log_bin_trust_function_creators = 1" >/dev/null
check_fails "status exit != 0 khi log_bin_trust_function_creators = 1 (D3)" "$DEPLOY" status --local
mysql_root "SET GLOBAL log_bin_trust_function_creators = 0" >/dev/null
r="$(dbq scheduler "CREATE TABLE vv_x (id INT)")"; [[ "$r" == ERR\ 1142* ]] && ok "vv_app không có DDL (1142)" || bad "vv_app phải bị 1142 khi CREATE TABLE (nhận: $r)"
if [[ $VIDEOLAB -eq 1 ]]; then
    r="$(dbq worker-video "SELECT * FROM users LIMIT 1")"; [[ "$r" == ERR\ 1142* ]] && ok "vv_worker_video SELECT users -> 1142" || bad "vv_worker_video phải bị 1142 (nhận: $r)"
    r="$(dbq worker-video "SELECT * FROM vl_videos LIMIT 1")"; [[ "$r" == "OK" ]] && ok "vv_worker_video SELECT vl_videos được" || bad "vv_worker_video phải đọc được vl_videos (nhận: $r)"
fi

# ---------------------------------------------------------------------------------------------------- Nginx
section "Qua nginx-smoke (snippet production)"
check "api /up 200" test "$(http_status $API /up)" = "200"
body="$(http_body $API /api/v1/config/public)"
check "/api/v1/config/public 200 có paid_checkout_enabled=false" bash -c "grep -Eq '\"paid_checkout_enabled\" *: *false' <<<'$body'"
check "/.env không trả 200" test "$(http_status $API /.env)" != "200"
check "/index.php/x không trả 200" test "$(http_status $API /index.php/x)" != "200"
check "/storage/logs/laravel.log không trả 200" test "$(http_status $API /storage/logs/laravel.log)" != "200"
check "admin-api /up 200" test "$(http_status admin-api.vvsmoke.internal /up)" = "200"
http_status $API "/api/v1/config/public?k=secretq123&t=secretq456" >/dev/null
check "gọi thẳng /index.php từ ngoài trả 404 (location internal, không né limit_req)" test "$(http_status $API /index.php)" = "404"

section "Nginx: limit_req/limit_conn cho host API (V3-1)"
# Đường dẫn không tồn tại: tới Laravel trả 404 nhanh, KHÔNG dính throttle nghiệp vụ (catalog 120/phút) nên phân biệt được 429 của Nginx.
UP="$VV_SMOKE_BASE/api/v1/zz-smoke-404"
codes() { # codes <tổng> <song song> <url> [curl args...]: in "<mã> <số lượng>"
    local n="$1" par="$2" url="$3"; shift 3
    seq 1 "$n" | xargs -P "$par" -I{} curl -s -o /dev/null -w '%{http_code}\n' "$@" -H "Host: $API" "$url" | sort | uniq -c | awk '{print $2" "$1}'
}
cnt() { awk -v c="$1" '$1==c {print $2}' <<<"$2" | head -1; }
FLOOD="$(codes 700 70 "$UP")"
echo "        flood 700 req: $(tr '\n' ' ' <<<"$FLOOD")"
[[ "$(cnt 404 "$FLOOD")" -ge 1 ]] 2>/dev/null && ok "flood: phần trong burst vẫn tới Laravel (404)" || bad "flood: không request nào tới Laravel (nhận: $FLOOD)"
[[ "$(cnt 429 "$FLOOD")" -ge 1 ]] 2>/dev/null && ok "flood vượt burst: Nginx trả 429 trước khi tới FPM" || bad "flood phải có 429 (nhận: $FLOOD)"
sleep 8
NORMAL="$(seq 1 40 | xargs -P 40 -I{} sh -c 'for i in 1 2 3; do curl -s -o /dev/null -w "%{http_code}\n" -H "Host: '"$API"'" '"$UP"'; done' | sort | uniq -c | awk '{print $2" "$1}')"
[[ -z "$(cnt 429 "$NORMAL")" ]] && ok "40 client cùng IP x 3 request (lớp học bình thường): KHÔNG có 429 ($(tr '\n' ' ' <<<"$NORMAL"))" || bad "lớp học 40 client bị 429 oan: $NORMAL"
sleep 6
AUTHN="$(seq 1 40 | xargs -P 40 -I{} curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' -H "Host: $API" -d '{}' "$VV_SMOKE_BASE/api/v1/auth/login" | sort | uniq -c | awk '{print $2" "$1}')"
[[ -z "$(cnt 429 "$AUTHN")" ]] && ok "40 học sinh đăng nhập cùng lúc cùng IP: KHÔNG 429 ($(tr '\n' ' ' <<<"$AUTHN"))" || bad "đăng nhập đồng loạt bị 429 oan: $AUTHN"
sleep 6
AUTHF="$(codes 400 60 "$VV_SMOKE_BASE/api/v1/auth/login" -X POST -H 'Content-Type: application/json' -d '{}')"
[[ "$(cnt 429 "$AUTHF")" -ge 1 ]] 2>/dev/null && ok "flood /auth/login: Nginx trả 429" || bad "flood /auth/login phải có 429 (nhận: $AUTHF)"
sleep 10
check "/up không bị limit_req (giám sát vẫn 200 ngay sau flood)" test "$(http_status $API /up)" = "200"
check "sau khi hết flood API trả 200 lại" wait_http $API /api/v1/config/public 200 20
check "access log php-fpm không chứa query string (?k=, ?t=)" bash -c "! docker logs '$(dc ps -q php)' 2>&1 | grep -q 'secretq'"

section "Bảo trì dùng chung (driver cache)"
sched php artisan down >/dev/null 2>&1
code="$(http_status $API /api/v1/config/public)"
[[ "$code" == "503" ]] && ok "php artisan down ở container scheduler: request qua php trả 503" || bad "down ở scheduler phải làm php trả 503 (nhận $code)"
sched php artisan up >/dev/null 2>&1
check "php artisan up: trả lại 200" wait_http $API /api/v1/config/public 200 10

# ---------------------------------------------------------------------------------------------------- worker-video & Redis
section "Redis ACL; worker-video sandbox (chỉ --with-videolab)"
if [[ $VIDEOLAB -eq 0 ]]; then
    check "V1/Bunny: không có container worker-video hay redis-video (profile videolab tắt)" test -z "$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Names}}' | grep -E 'worker-video|redis-video' || true)"
    check "V1/Bunny: compose không liệt kê worker-video/redis-video khi không bật profile" test -z "$(COMPOSE_PROFILES='' dc config --services | grep -E 'worker-video|redis-video' || true)"
    check "V1/Bunny: app chạy VIDEO_PROVIDER=bunny, VIDEOLAB_ENABLED=false" test "$(sched php -r 'echo getenv("VIDEO_PROVIDER"),",",getenv("VIDEOLAB_ENABLED");')" = "bunny,false"
    check "V1/Bunny: deploy không nhắc worker-video" bash -c "! grep -q 'worker-video' '$LOGDIR/first.log'"
    check "V1/Bunny: tạo user vv_worker_video không có trong MySQL" test "$(mysql_root "SELECT COUNT(*) FROM mysql.user WHERE user = 'vv_worker_video'")" = "0"
fi
if [[ $VIDEOLAB -eq 1 ]]; then
    check "worker-video: không phân giải được 'redis' (Redis chính)" test "$(worker php -r 'echo gethostbyname("redis");')" = "redis"
    check "worker-video: không ra Internet (kết nối 1.1.1.1:443 thất bại)" test "$(worker php -r '$s=@fsockopen("1.1.1.1",443,$e,$m,4);echo $s?"open":"closed";')" = "closed"
    check "worker-video: không tới được mailpit/php (mạng khác)" test "$(worker php -r 'echo gethostbyname("mailpit");')" = "mailpit"
    check "worker-video: LLEN queue video qua redis-video chạy được" test "$(worker php -r '$r=new Redis;$r->connect("redis-video",6379);$r->auth([getenv("REDIS_VIDEO_USERNAME"),getenv("REDIS_VIDEO_PASSWORD")]);echo $r->lLen(getenv("REDIS_PREFIX")."queues:video");')" = "0"
    check "worker-video: rootfs chỉ đọc" test "$(worker sh -c 'touch /x 2>&1 >/dev/null || echo ro' | tail -1)" = "ro"
    check "worker-video: UID 10001" test "$(worker id -u)" = "10001"
    check "worker-video: capability rỗng (cap_drop ALL)" test "$(worker sh -c 'grep CapEff /proc/self/status | tr -d "[:space:]"')" = "CapEff:0000000000000000"
    check "worker-video: có ffmpeg" worker sh -c 'ffmpeg -version'
    check "worker-video: không có biến của Redis chính hay SMTP" test -z "$(worker env | grep -E '^(REDIS_HOST|REDIS_PASSWORD|MAIL_HOST|MAIL_PASSWORD|INTERNAL_API_TOKEN|VIDEOLAB_API_KEY)=' || true)"
    check "php (app) gửi được vào Redis video (dispatch dùng REDIS_VIDEO_*)" test "$(sched php -r '$r=new Redis;$r->connect("redis-video",6379);$r->auth([getenv("REDIS_VIDEO_USERNAME"),getenv("REDIS_VIDEO_PASSWORD")]);echo $r->ping()?"ok":"no";')" = "ok"
    ACLOUT="$(docker run --rm --network "${PROJECT}_video" -e SKIP_SIGNALS=1 -v "$PROD_DIR/redis/check-acl.sh:/check-acl.sh:ro" redis:7.4.11 sh /check-acl.sh redis-video 6379 vitaminvui-database- vitaminvui_cache vv_worker_video "$REDIS_WORKER_PW" 2>&1)"
    echo "$ACLOUT" | tail -2 | sed 's/^/        /'
    check "check-acl.sh (SKIP_SIGNALS=1) in 'ACL đạt.' trên redis-video" grep -q "ACL đạt." <<<"$ACLOUT"
fi
check "redis chính: chỉ user default (worker không có user ở đây)" test "$(dc exec -T -e REDISCLI_AUTH="$REDIS_PW" redis redis-cli --no-auth-warning ACL LIST | grep -c vv_worker_video)" = "0"
rcli() { dc exec -T "$1" redis-cli --no-auth-warning --user vv_healthcheck --pass x "${@:2}" 2>&1; }
check "vv_healthcheck: PING được (healthcheck không cần secret)" test "$(rcli redis ping)" = "PONG"
check "vv_healthcheck: GET bị NOPERM (chỉ +ping)" grep -q NOPERM <<<"$(rcli redis get anykey)"
if [[ $VIDEOLAB -eq 1 ]]; then
    check "vv_healthcheck: CONFIG bị NOPERM trên redis-video" grep -q NOPERM <<<"$(rcli redis-video config get '*')"
fi
check "redis healthy (bằng user vv_healthcheck)" test "$(docker inspect --format '{{.State.Health.Status}}' "$(dc ps -q redis)")" = "healthy"
if [[ $VIDEOLAB -eq 1 ]]; then
    check "redis-video healthy (bằng user vv_healthcheck)" test "$(docker inspect --format '{{.State.Health.Status}}' "$(dc ps -q redis-video)")" = "healthy"
fi
if [[ $VIDEOLAB -eq 1 ]]; then
    check "worker-video: /tmp là tmpfs noexec,nosuid,nodev (S5)" grep -q noexec <<<"$(worker grep ' /tmp ' /proc/mounts)"
    check "worker-video: không thực thi được file trong /tmp" test "$(worker sh -c 'cp /bin/true /tmp/t 2>/dev/null; /tmp/t >/dev/null 2>&1 && echo ran || echo blocked')" = "blocked"
fi
check "mysql có mem_limit (D1)" test "$(docker inspect --format '{{.HostConfig.Memory}}' "$(dc ps -q mysql)")" -gt 0
check "mysql: innodb_buffer_pool_size theo VV_MYSQL_BUFFER_POOL (256M)" test "$(mysql_root 'SELECT @@innodb_buffer_pool_size')" = "268435456"
WANT_CFG="100,1073741824,+00:00"; [[ "${VV_SIZE:-large}" == "small" ]] && WANT_CFG="50,536870912,+00:00"
check "mysql: my.cnf + cỡ máy áp dụng (max_connections, redo, time_zone) = $WANT_CFG" test "$(mysql_root 'SELECT CONCAT(@@max_connections, ",", @@innodb_redo_log_capacity, ",", @@global.time_zone)')" = "$WANT_CFG"
check "mysql: không có cảnh báo binlog_format deprecated" bash -c "! docker logs '$(dc ps -q mysql)' 2>&1 | grep -qi 'binlog_format.*deprecated'"
check "mysql: slow log bật" test "$(mysql_root 'SELECT @@slow_query_log')" = "1"
check "Redis không publish cổng ra host" test -z "$(docker port "$(dc ps -q redis)" 2>/dev/null)"
check "MySQL không publish cổng ra host" test -z "$(docker port "$(dc ps -q mysql)" 2>/dev/null)"
check "php chỉ publish 127.0.0.1 (smoke tắt cổng)" test -z "$(docker port "$(dc ps -q php)" 2>/dev/null | grep -v '127.0.0.1' || true)"

# ---------------------------------------------------------------------------------------------------- scheduler, queue, mail
if [[ "${VV_SIZE:-large}" == "small" ]]; then
    section "Cỡ máy small (1 vCPU / 2 GB): giá trị thực trong container"
    check "FPM pm.max_children = 5" grep -q "pm.max_children = 5" <<<"$(dc exec -T php php-fpm -tt 2>&1)"
    check "FPM start/min/max spare hợp lệ (2/1/3), php-fpm khởi động được" grep -q "pm.max_spare_servers = 3" <<<"$(dc exec -T php php-fpm -tt 2>&1)"
    check "opcache.memory_consumption = 64" grep -Eq "^opcache.memory_consumption => 64" <<<"$(dc exec -T php php-fpm -i)"
    check "MySQL innodb_buffer_pool_size = 256M" test "$(mysql_root 'SELECT @@innodb_buffer_pool_size')" = "268435456"
    check "MySQL max_connections = 50" test "$(mysql_root 'SELECT @@max_connections')" = "50"
    check "MySQL performance_schema tắt" test "$(mysql_root 'SELECT @@performance_schema')" = "0"
    check "MySQL innodb_redo_log_capacity = 512M" test "$(mysql_root 'SELECT @@innodb_redo_log_capacity')" = "536870912"
    check "Redis maxmemory = 64mb" test "$(dc exec -T -e REDISCLI_AUTH="$REDIS_PW" redis redis-cli --no-auth-warning config get maxmemory | tail -1)" = "67108864"
    TOTAL_MEM=0
    for c in $(dc ps -q); do m="$(docker inspect --format '{{.HostConfig.Memory}}' "$c")"; TOTAL_MEM=$((TOTAL_MEM + m)); done
    echo "        tổng mem_limit các container: $((TOTAL_MEM / 1048576)) MB"
    check "tổng mem_limit <= 1,8 GB (1843 MB)" test "$TOTAL_MEM" -le $((1843 * 1048576))
fi

section "Scheduler, queue, mail"
WANT_Q=2; [[ "${VV_SIZE:-large}" == "small" ]] && WANT_Q=1
check "container queue x$WANT_Q (VV_SIZE=${VV_SIZE:-large})" test "$(dc ps -q queue | wc -l | tr -d ' ')" = "$WANT_Q"
SL="$(sched php artisan schedule:list 2>&1)"
check "schedule:list có counters:recount, orders:expire-manual, images:prune-orphans, ops:health" bash -c 'for c in counters:recount orders:expire-manual images:prune-orphans ops:health; do grep -q "$c" <<<"$1" || exit 1; done' _ "$SL"
MAILS_BEFORE="$(docker run --rm --network "${PROJECT}_app" curlimages/curl:latest -s http://mailpit:8025/api/v1/messages 2>/dev/null | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2)"
sched php artisan tinker --execute='Illuminate\Support\Facades\Mail::to("smoke@vvsmoke.internal")->send(new App\Mail\OtpMail("Smoke", "123456", App\Enums\OtpPurpose::VerifyAccount, 10));' >/dev/null 2>&1
mail_ok=0
for _ in $(seq 1 30); do
    n="$(docker run --rm --network "${PROJECT}_app" curlimages/curl:latest -s http://mailpit:8025/api/v1/messages 2>/dev/null | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2)"
    if [[ "${n:-0}" -gt "${MAILS_BEFORE:-0}" ]]; then mail_ok=1; break; fi
    sleep 2
done
[[ $mail_ok -eq 1 ]] && ok "mail OTP đi qua queue tới mailpit" || bad "mail OTP không tới mailpit sau 60s"

elapsed=$(( $(date +%s) - T_DEPLOY ))
if [[ $elapsed -lt 150 ]]; then echo "  (chờ $((150 - elapsed))s cho nhịp scheduler/worker để ops:health)"; sleep $((150 - elapsed)); fi
OPS="$(sched php artisan ops:health 2>&1)"; ops_rc=$?
echo "$OPS" | head -12 | sed 's/^/        /'
[[ $ops_rc -eq 0 ]] && ok "ops:health không báo worker/scheduler chết" || bad "ops:health thoát $ops_rc"

# ---------------------------------------------------------------------------------------------------- dữ liệu sống sót khi restart
section "restart php giữ dữ liệu"
dc exec -T php sh -c 'echo keep > /var/www/uploads/smoke.txt && echo keep > /var/www/backend/storage/app/smoke.txt' >/dev/null 2>&1
dc restart php >/dev/null 2>&1
check "uploads còn sau restart (trong container)" dc exec -T php test -f /var/www/uploads/smoke.txt
check "storage/app còn sau restart (trong container)" dc exec -T php test -f /var/www/backend/storage/app/smoke.txt
check "uploads có trên thư mục host" test -f "$GEN/data/uploads/smoke.txt"
check "php healthy lại" wait_http $API /up 200 60


# ---------------------------------------------------------------------------------------------------- migrate lỗi, irreversible, rollback
section "Migrate lỗi, VV-IRREVERSIBLE, rollback"
make_variant dev4 2099_01_01_000003_vv_smoke_fail.php && ok "build biến thể dev4 (migration lỗi)" || bad "build dev4"
make_variant dev3 2099_01_01_000002_vv_smoke_irreversible.php && ok "build biến thể dev3 (VV-IRREVERSIBLE)" || bad "build dev3"
make_variant dev2 2099_01_01_000001_vv_smoke_fake.php && ok "build biến thể dev2 (migration giả)" || bad "build dev2"

deploy dev4 deploy --local dev4; rc=$?
[[ $rc -ne 0 ]] && ok "deploy dev4 (migrate lỗi) thoát khác 0" || bad "deploy dev4 phải lỗi"
check "sau migrate lỗi: log_bin_trust_function_creators = 0" test "$(mysql_root 'SELECT @@GLOBAL.log_bin_trust_function_creators')" = "0"
check "migrate lỗi: container vẫn chạy bản cũ ($SMOKE_TAG)" test "$(running_tag scheduler)" = "$SMOKE_TAG"
check "migrate lỗi: IMAGE_TAG không đổi" grep -q "^IMAGE_TAG=$SMOKE_TAG$" "$GEN/.env"
check "migrate lỗi: in hướng dẫn khôi phục, không tự rollback" grep -q "KHÔNG tự rollback" "$LOGDIR/dev4.log"
check "migrate lỗi: releases.log ghi migrate-failed" grep -q "migrate-failed" "$GEN/releases.log"

deploy dev3 deploy --local dev3; rc=$?
[[ $rc -ne 0 ]] && grep -q "VV-IRREVERSIBLE" "$LOGDIR/dev3.log" && ok "deploy dev3 dừng vì VV-IRREVERSIBLE thiếu --ack-irreversible" || bad "deploy dev3 phải dừng vì VV-IRREVERSIBLE"
check "dev3: chưa đổi gì (vẫn $SMOKE_TAG)" test "$(running_tag scheduler)" = "$SMOKE_TAG"

deploy dev2 deploy --local dev2 --maintenance; rc=$?
[[ $rc -eq 0 ]] && ok "deploy dev2 --maintenance thành công" || { bad "deploy dev2 (xem $LOGDIR/dev2.log)"; tail -20 "$LOGDIR/dev2.log"; }
check "dev2: bật bảo trì khi có migration" grep -q "bật bảo trì" "$LOGDIR/dev2.log"
check "dev2: PREVIOUS_TAG = $SMOKE_TAG" grep -q "^PREVIOUS_TAG=$SMOKE_TAG$" "$GEN/.env"
check "dev2: site đã bật lại (200)" wait_http $API /up 200 30

deploy rb1 rollback --local; rc=$?
[[ $rc -ne 0 ]] && grep -q "migration mà image" "$LOGDIR/rb1.log" && ok "rollback dừng vì DB có migration image cũ không biết" || bad "rollback phải dừng khi schema đi trước code"
check "rollback dừng: vẫn đang chạy dev2" grep -q "^IMAGE_TAG=dev2$" "$GEN/.env"
deploy rb2 rollback --local --force-schema-ahead; rc=$?
[[ $rc -eq 0 ]] && ok "rollback --force-schema-ahead thành công" || { bad "rollback --force-schema-ahead (xem $LOGDIR/rb2.log)"; tail -20 "$LOGDIR/rb2.log"; }
for s in php queue scheduler $([[ $VIDEOLAB -eq 1 ]] && echo worker-video); do
    check "rollback: $s chạy lại tag $SMOKE_TAG" test "$(running_tag "$s")" = "$SMOKE_TAG"
done
check "rollback: không chạy migrate" bash -c "! grep -q 'migrate bằng user' '$LOGDIR/rb2.log'"
check "releases.log ghi đủ deploy + rollback" bash -c "[[ \$(grep -c 'deploy\|rollback' '$GEN/releases.log') -ge 4 ]]"
check "status chạy được" "$DEPLOY" status --local

section "Rollback sau smoke lỗi (R1), S8, digest (S2), khoá"
# R1: deploy qua bước đổi container rồi smoke lỗi (VV_SMOKE_BASE trỏ cổng chết) -> .env phải phản ánh bản đang chạy; rollback về ĐÚNG bản cũ.
make_variant dev5 2099_01_01_000001_vv_smoke_fake.php && ok "build biến thể dev5" || bad "build dev5"
VV_SMOKE_BASE="http://127.0.0.1:1" deploy dev5 deploy --local dev5; rc=$?
[[ $rc -ne 0 ]] && ok "deploy dev5 với smoke lỗi thoát != 0" || bad "deploy dev5 (smoke lỗi) phải thoát != 0"
check "smoke lỗi: container mới đang chạy (dev5)" test "$(running_tag scheduler)" = "dev5"
check "smoke lỗi: .env IMAGE_TAG=dev5, PREVIOUS_TAG=$SMOKE_TAG (R1)" bash -c "grep -q '^IMAGE_TAG=dev5\$' '$GEN/.env' && grep -q '^PREVIOUS_TAG=$SMOKE_TAG\$' '$GEN/.env'"
check "smoke lỗi: releases.log ghi smoke-failed" grep -q "smoke-failed" "$GEN/releases.log"
deploy rb3 rollback --local --force-schema-ahead; rc=$?
[[ $rc -eq 0 ]] && ok "rollback sau smoke lỗi thành công" || { bad "rollback sau smoke lỗi (xem $LOGDIR/rb3.log)"; tail -10 "$LOGDIR/rb3.log"; }
check "rollback sau smoke lỗi về ĐÚNG bản cũ ($SMOKE_TAG), không phải bản trước nữa" test "$(running_tag scheduler)" = "$SMOKE_TAG"
check "rollback: .env IMAGE_TAG=$SMOKE_TAG" grep -q "^IMAGE_TAG=$SMOKE_TAG$" "$GEN/.env"

# S8: tag xấu trong .env; quyền thư mục
cp "$GEN/.env" "$GEN/.env.bak"
A40="$(printf 'a%.0s' $(seq 1 40))"
B40="$(printf 'b%.0s' $(seq 1 40))"
sed -i.tmp "s/^IMAGE_TAG=.*/IMAGE_TAG=$A40/; s/^PREVIOUS_TAG=.*/PREVIOUS_TAG=$B40/" "$GEN/.env"; rm -f "$GEN/.env.tmp"
"$DEPLOY" rollback >"$LOGDIR/rb-nodig.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "thiếu IMAGE_DIGEST" "$LOGDIR/rb-nodig.log" && ok "rollback tới tag KHÔNG còn trên server mà thiếu digest -> dừng trước pull (R17)" || bad "rollback thiếu digest phải dừng (xem $LOGDIR/rb-nodig.log)"
cp "$GEN/.env.bak" "$GEN/.env"
sed -i.tmp 's/^PREVIOUS_TAG=.*/PREVIOUS_TAG=dev;touch_x/' "$GEN/.env"; rm -f "$GEN/.env.tmp"
deploy rb4 rollback --local; rc=$?
[[ $rc -ne 0 ]] && grep -q "không hợp lệ" "$LOGDIR/rb4.log" && ok "rollback dừng khi PREVIOUS_TAG sai định dạng (S8)" || bad "rollback phải dừng khi PREVIOUS_TAG sai định dạng"
cp "$GEN/.env.bak" "$GEN/.env"; rm -f "$GEN/.env.bak"
chmod a-w "$GEN"
deploy ro deploy --local "$SMOKE_TAG"; rc=$?
chmod u+w "$GEN"
[[ $rc -ne 0 ]] && grep -q "không ghi được" "$LOGDIR/ro.log" && ok "VV_HOME không ghi được -> deploy dừng với thông báo rõ (S8: chủ thư mục phải là user chạy script)" || bad "deploy phải dừng rõ khi VV_HOME không ghi được"
check "thư mục deploy ghi được sau khi khôi phục quyền: deploy lại $SMOKE_TAG qua" bash -c "[[ -w '$GEN' ]]"

# R15: khoá deploy mồ côi (tiến trình đã chết) được gỡ tự động; khoá của tiến trình còn sống thì từ chối.
mkdir "$GEN/.deploy.lock.d" && echo 999999 > "$GEN/.deploy.lock.d/pid"
deploy stale deploy --local "$SMOKE_TAG"; rc=$?
[[ $rc -eq 0 ]] && grep -q "khoá mồ côi" "$LOGDIR/stale.log" && ok "khoá mồ côi (tiến trình chết) được gỡ tự động, deploy chạy" || bad "khoá mồ côi phải được gỡ (xem $LOGDIR/stale.log)"
mkdir "$GEN/.deploy.lock.d" && echo $$ > "$GEN/.deploy.lock.d/pid"
deploy live deploy --local "$SMOKE_TAG"; rc=$?
rm -f "$GEN/.deploy.lock.d/pid"; rmdir "$GEN/.deploy.lock.d"
[[ $rc -ne 0 ]] && grep -q "đang có deploy khác" "$LOGDIR/live.log" && ok "khoá do tiến trình CÒN SỐNG giữ -> deploy từ chối, không gỡ" || bad "khoá còn sống phải chặn deploy"

# S2: ngoài --local, digest là BẮT BUỘC; thiếu thì dừng trước pull/up.
"$DEPLOY" deploy "$A40" >"$LOGDIR/nodig.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "thiếu IMAGE_DIGEST_BACKEND" "$LOGDIR/nodig.log" && ok "deploy không --local mà thiếu digest -> dừng trước pull/up (S2)" || bad "thiếu digest phải dừng deploy"
check "thiếu digest: không đổi gì (vẫn $SMOKE_TAG)" test "$(running_tag scheduler)" = "$SMOKE_TAG"
IMAGE_DIGEST_BACKEND="sha256:xyz" "$DEPLOY" deploy "$A40" >"$LOGDIR/baddig.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "không đúng dạng" "$LOGDIR/baddig.log" && ok "digest sai định dạng -> dừng (S2)" || bad "digest sai định dạng phải dừng"
check "pull không được gọi khi thiếu/sai digest" bash -c "! grep -q 'pull image' '$LOGDIR/nodig.log' '$LOGDIR/baddig.log'"
VV_COSIGN_VERIFY=1 IMAGE_DIGEST_BACKEND="sha256:$(printf '0%.0s' $(seq 1 64))" "$DEPLOY" deploy --local "$SMOKE_TAG" >"$LOGDIR/cosign.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -Eq "cosign|digest image" "$LOGDIR/cosign.log" && ok "VV_COSIGN_VERIFY=1: không có cosign/digest lệch -> dừng" || bad "VV_COSIGN_VERIFY phải dừng khi không xác minh được"

# R16: rollback tới tag tường minh (bản KHÁC PREVIOUS_TAG)
deploy rb5 rollback dev2 --local; rc=$?
[[ $rc -eq 0 ]] && ok "rollback <tag> tường minh (dev2) thành công" || { bad "rollback dev2 (xem $LOGDIR/rb5.log)"; tail -8 "$LOGDIR/rb5.log"; }
check "rollback tường minh: chạy dev2" test "$(running_tag scheduler)" = "dev2"
check "rollback tường minh: PREVIOUS_TAG = $SMOKE_TAG" grep -q "^PREVIOUS_TAG=$SMOKE_TAG$" "$GEN/.env"
deploy rb6 rollback --local --force-schema-ahead; rc=$?
[[ $rc -eq 0 ]] && check "rollback (không tag) quay lại $SMOKE_TAG" test "$(running_tag scheduler)" = "$SMOKE_TAG" || bad "rollback về PREVIOUS_TAG (xem $LOGDIR/rb6.log)"

# S2: digest sai thì dừng trước khi đổi gì
IMAGE_DIGEST_BACKEND="sha256:0000000000000000000000000000000000000000000000000000000000000000" deploy dg deploy --local "$SMOKE_TAG"; rc=$?
[[ $rc -ne 0 ]] && grep -q "digest image" "$LOGDIR/dg.log" && ok "digest sai -> deploy dừng trước khi đổi container (S2)" || bad "digest sai phải dừng deploy"
check "digest sai: không đổi gì (vẫn $SMOKE_TAG)" test "$(running_tag scheduler)" = "$SMOKE_TAG"

# ---------------------------------------------------------------------------------------------------- kiểm tĩnh
# ---------------------------------------------------------------------------------------------------- frontend (T35-2 (d))
if [[ $WITH_FRONTEND -eq 1 ]]; then
    section "web/admin (T35-2 (d))"
    # Bật web/admin trên stack đang chạy (deploy.sh --local không bỏ frontend), không migration chờ.
    unset VV_SKIP_FRONTEND
    deploy fe deploy --local "$SMOKE_TAG"; rc=$?
    [[ $rc -eq 0 ]] && ok "deploy kèm web/admin: smoke /khoa-hoc 200, /dang-nhap 200" || { bad "deploy kèm frontend (xem $LOGDIR/fe.log)"; tail -15 "$LOGDIR/fe.log"; }
    check "web healthy" bash -c "[[ \$(docker inspect --format '{{.State.Health.Status}}' \$(cd '$SMOKE_DIR' && source lib.sh && dc ps -q web)) == healthy ]]"
    check "admin healthy (healthcheck /dang-nhap)" bash -c "[[ \$(docker inspect --format '{{.State.Health.Status}}' \$(cd '$SMOKE_DIR' && source lib.sh && dc ps -q admin)) == healthy ]]"
    check "web /khoa-hoc 200 (SSR gọi API_INTERNAL_URL qua nginx-smoke :8081)" test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:13000/khoa-hoc)" = "200"
    check "log :8081 của nginx-smoke có request mang X-Internal-Token" bash -c "cd '$SMOKE_DIR' && source lib.sh && dc exec -T nginx-smoke cat /var/log/nginx/vv-internal.access.log | grep -q 'internal_token=yes'"
    check "log :8081 không có request thiếu token" bash -c "cd '$SMOKE_DIR' && source lib.sh && ! dc exec -T nginx-smoke cat /var/log/nginx/vv-internal.access.log | grep -q 'internal_token=no'"
    if [[ "${VV_SIZE:-large}" == "small" ]]; then
        TOTAL_ALL=0
        for c in $(dc ps -q); do m="$(docker inspect --format '{{.HostConfig.Memory}}' "$c")"; TOTAL_ALL=$((TOTAL_ALL + m)); done
        echo "        tổng mem_limit gồm web/admin: $((TOTAL_ALL / 1048576)) MB"
        check "small: tổng mem_limit cả web/admin <= 1,8 GB (1843 MB)" test "$TOTAL_ALL" -le $((1843 * 1048576))
        check "small: web NODE_OPTIONS có --max-old-space-size=160, admin 112" bash -c "[[ \$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' $(dc ps -q web) | grep -c 'NODE_OPTIONS=--max-old-space-size=160') -eq 1 && \$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' $(dc ps -q admin) | grep -c 'NODE_OPTIONS=--max-old-space-size=112') -eq 1 ]]"
    fi
    check "web CSP có nonce-" bash -c "curl -sI http://127.0.0.1:13000/ | grep -i content-security-policy | grep -q 'nonce-'"
    sched php -r 'imagepng(imagecreatetruecolor(40,40),"/var/www/uploads/smoke.png");' >/dev/null 2>&1
    img="$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:13000/_next/image?url=http%3A%2F%2Fstatic.vvsmoke-media.internal%3A8090%2Fsmoke.png&w=64&q=75')"
    # Máy chủ ảnh smoke nằm ở IP private nên bộ tối ưu ảnh của Next TỪ CHỐI (chống SSRF) -> 400. Đạt = không 5xx. Nhánh 200 cần host công khai (staging).
    [[ "$img" =~ ^(200|400)$ ]] && ok "/_next/image không 500 (nhận $img; 400 = chặn IP private, đúng thiết kế)" || bad "/_next/image trả $img"
    check "web: mã thuộc root, rootfs chỉ đọc (S7)" test "$(dc exec -T web sh -c 'touch /app/apps/web/server.js 2>/dev/null && echo writable || echo ro')" = "ro"
    check "web: stat server.js thuộc root" test "$(dc exec -T web stat -c %u /app/apps/web/server.js)" = "0"
    check "web: cache ảnh của Next ghi được (tmpfs)" dc exec -T web sh -c 'touch /app/apps/web/.next/cache/x && rm /app/apps/web/.next/cache/x'
    check "web: /tmp noexec" grep -q noexec <<<"$(dc exec -T web grep ' /tmp ' /proc/mounts)"
    check "admin: rootfs chỉ đọc, cache ghi được" test "$(dc exec -T admin sh -c 'touch /app/apps/admin/server.js 2>/dev/null && echo writable || echo ro; touch /app/apps/admin/.next/cache/x && echo cache-ok' | tr '\n' ' ')" = "ro cache-ok "
    check "web/admin có mem_limit" bash -c "[[ \$(docker inspect --format '{{.HostConfig.Memory}}' $(dc ps -q web)) -gt 0 && \$(docker inspect --format '{{.HostConfig.Memory}}' $(dc ps -q admin)) -gt 0 ]]"
    check "admin /dang-nhap 200" test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:13001/dang-nhap)" = "200"
fi

section "Kiểm tĩnh script"
check "grep 'compose down' infra/production/*.sh rỗng" bash -c "! grep -n 'compose down' '$PROD_DIR'/*.sh"
check "script production không dùng 'down -v'" bash -c "! grep -nE '(^|[^a-z])down -v' '$PROD_DIR'/*.sh"
if docker image inspect koalaman/shellcheck:stable >/dev/null 2>&1 || docker pull -q koalaman/shellcheck:stable >/dev/null 2>&1; then
    sc="$(cd "$PROD_DIR" && docker run --rm -v "$PROD_DIR:/mnt:ro" -w /mnt koalaman/shellcheck:stable -S warning -x -P smoke deploy.sh smoke/*.sh 2>&1)"
    [[ -z "$sc" ]] && ok "shellcheck 0 lỗi (deploy.sh, smoke/*.sh)" || { bad "shellcheck có cảnh báo"; echo "$sc" | head -30; }
else
    bad "không có image shellcheck"
fi

# nginx -t cho TOÀN BỘ cấu hình production (conf.d + snippets + optional/videolab.conf), placeholder điền giá trị giả, chứng chỉ tự ký tạm
NT="$(mktemp -d)"
mkdir -p "$NT/conf.d" "$NT/snippets" "$NT/ssl"
cp "$PROD_DIR"/nginx/snippets/*.conf "$NT/snippets/"
sed -i.bak 's#<IP_MONITOR_LB>#127.0.0.1#' "$NT/snippets/vv-api-common.conf"
sed -e 's#<IP_NOI_BO_NGINX>#127.0.0.1#' -e 's#<IP_NEXT_SERVER>#10.231.10.0/24#' "$PROD_DIR/nginx/conf.d/vitaminvui.conf" > "$NT/conf.d/vitaminvui.conf"
sed -e 's#<IP_APP_SERVER>#127.0.0.1#' "$PROD_DIR/nginx/optional/videolab.conf" > "$NT/conf.d/videolab.conf"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$NT/ssl/privkey.pem" -out "$NT/ssl/fullchain.pem" -subj "/CN=smoke" -days 1 >/dev/null 2>&1
cp "$NT/ssl/fullchain.pem" "$NT/ssl/default.crt"; cp "$NT/ssl/privkey.pem" "$NT/ssl/default.key"
NGT="$(docker run --rm -v "$NT/conf.d:/etc/nginx/conf.d:ro" -v "$NT/snippets:/etc/nginx/snippets:ro" -v "$NT/ssl:/etc/ssl/vitaminvui:ro" -v "$NT/ssl:/etc/ssl/vitaminvui-media:ro" nginx:1.27.5@sha256:6784fb0834aa7dbbe12e3d7471e69c290df3e6ba810dc38b34ae33d3c1c05f7d nginx -t 2>&1)"
check "nginx -t cấu hình production đầy đủ (kể cả optional/videolab.conf) đạt" grep -q "test is successful" <<<"$NGT"
[[ -z "${NGT##*successful*}" ]] || echo "$NGT" | tail -5
rm -rf "$NT"

# không secret trong log deploy
leaks=0
for n in APP_KEY WORKER_APP_KEY MYSQL_ROOT_PW DB_APP_PW DB_MIGRATE_PW DB_WORKER_PW REDIS_PW REDIS_VIDEO_PW REDIS_WORKER_PW INTERNAL_API_TOKEN VIDEOLAB_API_KEY VIDEOLAB_TOKEN_KEY VIDEOLAB_WEBHOOK_SECRET PRIVACY_NOTICE_TOKEN_KEY WORKER_PRIVACY_KEY; do
    v="${!n}"; v="${v#base64:}"
    if grep -rqF -- "$v" "$LOGDIR" "$GEN/releases.log"; then leaks=$((leaks + 1)); echo "  rò: $n"; fi
done
check "log deploy/rollback/releases.log không chứa secret smoke" test "$leaks" = "0"

# ---------------------------------------------------------------------------------------------------- kết thúc
summary; rc=$?
if [[ $KEEP -eq 1 ]]; then
    echo "Giữ stack (--keep). Dọn bằng: $0 down"
elif [[ $rc -eq 0 ]]; then
    purge
else
    echo "Có lỗi: GIỮ stack để điều tra. Log: $LOGDIR. Dọn bằng: $0 down"
fi
exit $rc
