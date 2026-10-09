#!/usr/bin/env bash
# Kiểm image backend + worker-video đã build (T35-1 (b)). Dùng: check-images.sh [tag]  (mặc định dev; registry vv-local)
# Cần $GEN/secrets.env (make-env.sh) để kiểm `docker history` không chứa secret smoke.
set -uo pipefail
# shellcheck source=lib.sh disable=SC1091
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
TAG="${1:-$SMOKE_TAG}"
BACK="vv-local/vitaminvui-backend:$TAG"
WORK="vv-local/vitaminvui-worker-video:$TAG"
[[ -f "$GEN/secrets.env" ]] || "$SMOKE_DIR/make-env.sh" >/dev/null
load_secrets

run() { docker run --rm --entrypoint "$1" "$2" "${@:3}"; }  # run <entrypoint> <image> args...
sh_in() { docker run --rm --entrypoint sh "$1" -c "$2"; }

section "Backend $BACK"
check "chạy UID 10001" test "$(run id "$BACK" -u)" = "10001"
check "chạy GID 10001" test "$(run id "$BACK" -g)" = "10001"
mods="$(run php "$BACK" -m)"
for m in redis pdo_mysql gd intl bcmath pcntl exif zip; do
    check "php -m có $m" grep -qix "$m" <<<"$mods"
done
check "php -m có Zend OPcache" grep -q "Zend OPcache" <<<"$mods"
check "không có xdebug" bash -c "! grep -qi xdebug <<<'$mods'"

cli="$(run php "$BACK" -i)"
fpm="$(run php-fpm "$BACK" -i)"
for src in cli fpm; do
    out="${!src}"
    check "php $src: display_errors=Off" grep -Eq '^display_errors => Off => Off' <<<"$out"
    check "php $src: display_startup_errors=Off" grep -Eq '^display_startup_errors => Off => Off' <<<"$out"
    check "php $src: log_errors=On" grep -Eq '^log_errors => On => On' <<<"$out"
    check "php $src: expose_php=Off" grep -Eq '^expose_php => Off => Off' <<<"$out"
done
check "FPM opcache.validate_timestamps=Off" grep -Eq '^opcache.validate_timestamps => Off => Off' <<<"$fpm"
check "FPM opcache.enable=On" grep -Eq '^opcache.enable => On => On' <<<"$fpm"
check "CLI opcache.enable_cli=Off (zz-opcache.ini)" grep -Eq '^opcache.enable_cli => Off => Off' <<<"$cli"

for p in .env tests phpunit.xml phpunit.local-a.xml phpstan.neon vendor/pestphp vendor/larastan vendor/laravel/pint .git .claude .gitignore README.md; do
    check_fails "image không có /var/www/backend/$p" sh_in "$BACK" "test -e /var/www/backend/$p"
done
check "không có node_modules trong /var/www" test -z "$(sh_in "$BACK" 'find /var/www -name node_modules -maxdepth 4 | head -1')"
check "không có file .env* nào trong /var/www" test -z "$(sh_in "$BACK" "find /var/www -name '.env*' | head -1")"
check "không có composer" test -z "$(sh_in "$BACK" 'command -v composer || true')"
check "không có git" test -z "$(sh_in "$BACK" 'command -v git || true')"
check "không có ffmpeg trong backend" test -z "$(sh_in "$BACK" 'command -v ffmpeg || true')"
check "mã nguồn thuộc root (chỉ đọc với 10001)" test "$(sh_in "$BACK" 'stat -c %u /var/www/backend/app')" = "0"
check "storage và bootstrap/cache ghi được bởi 10001" test "$(sh_in "$BACK" 'stat -c %u /var/www/backend/storage /var/www/backend/bootstrap/cache | sort -u')" = "10001"

env_out="$(run env "$BACK")"
check "env của image không có APP_ENV" bash -c "! grep -q '^APP_ENV=' <<<'$env_out'"
check "env của image không có APP_KEY" bash -c "! grep -q '^APP_KEY=' <<<'$env_out'"
check "entrypoint thoát khác 0 khi guard từ chối (APP_ENV thiếu = production, thiếu biến)" bash -c "! docker run --rm '$BACK' php -v >/dev/null 2>&1"

rev="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$BACK")"
cv="$(docker image inspect --format '{{ index .Config.Labels "vv.compose-version" }}' "$BACK")"
check "label revision = $TAG" test "$rev" = "$TAG"
check_cv="$(grep -m1 '^x-vv-compose-version:' "$PROD_DIR/docker-compose.yml" | sed 's/.*: *"\{0,1\}\([^"]*\)"\{0,1\}/\1/')"
check "label vv.compose-version ($cv) = x-vv-compose-version trong docker-compose.yml ($check_cv)" test "$cv" = "$check_cv"
bake_cv="$(grep -A1 'variable "COMPOSE_VERSION"' "$PROD_DIR/docker-bake.hcl" | sed -n 's/.*default *= *"\(.*\)"/\1/p')"
check "COMPOSE_VERSION trong docker-bake.hcl ($bake_cv) = compose ($check_cv)" test "$bake_cv" = "$check_cv"

section "Worker-video $WORK"
check "worker chạy UID 10001" test "$(run id "$WORK" -u)" = "10001"
check "worker có ffmpeg" sh_in "$WORK" 'command -v ffmpeg && command -v ffprobe'
check "worker có label vv.compose-version" test "$(docker image inspect --format '{{ index .Config.Labels "vv.compose-version" }}' "$WORK")" = "$check_cv"
check "worker không có .env" test -z "$(sh_in "$WORK" "find /var/www -name '.env*' | head -1")"
check "worker không có composer/git" test -z "$(sh_in "$WORK" 'command -v composer; command -v git')"

section "Secret trong lịch sử build"
hist="$(docker history --no-trunc "$BACK"; docker history --no-trunc "$WORK")"
leaks=0
for n in APP_KEY WORKER_APP_KEY MYSQL_ROOT_PW DB_APP_PW DB_MIGRATE_PW DB_WORKER_PW REDIS_PW REDIS_VIDEO_PW REDIS_WORKER_PW INTERNAL_API_TOKEN VIDEOLAB_API_KEY VIDEOLAB_TOKEN_KEY VIDEOLAB_WEBHOOK_SECRET PRIVACY_NOTICE_TOKEN_KEY WORKER_PRIVACY_KEY; do
    v="${!n}"
    v="${v#base64:}"
    if grep -qF -- "$v" <<<"$hist"; then leaks=$((leaks + 1)); echo "  rò: $n"; fi
done
check "docker history --no-trunc không chứa secret smoke" test "$leaks" = "0"
# Quét luôn nội dung filesystem image với các giá trị secret smoke (an toàn dù chưa từng bị COPY).
files_hit="$(for n in INTERNAL_API_TOKEN DB_APP_PW REDIS_PW; do docker run --rm --entrypoint sh "$BACK" -c "grep -rIl -- '${!n}' /var/www /usr/local/etc /etc 2>/dev/null | head -1"; done)"
check "filesystem image không chứa secret smoke" test -z "$files_hit"

summary
