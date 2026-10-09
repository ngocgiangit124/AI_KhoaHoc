#!/usr/bin/env bash
# Hàm dùng chung cho smoke (source, không chạy trực tiếp). Project `vvsmoke`, KHÔNG liên quan project `vitaminvui` của dev.
# shellcheck disable=SC2034  # biến dùng ở script gọi
SMOKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD_DIR="$(cd "$SMOKE_DIR/.." && pwd)"
REPO_ROOT="$(cd "$PROD_DIR/../.." && pwd)"
GEN="$SMOKE_DIR/.generated"
PROJECT="vvsmoke"
export VV_HOME="$GEN"
export VV_PROJECT="$PROJECT"
export COMPOSE_FILE="$PROD_DIR/docker-compose.yml:$SMOKE_DIR/docker-compose.smoke.yml"
export VV_SMOKE_BASE="http://127.0.0.1:18080"
# Image local (không đẩy đi đâu): vv-local/vitaminvui-*:<tag>
export VV_REGISTRY="vv-local"
SMOKE_TAG="${SMOKE_TAG:-dev}"

dc() { docker compose --project-name "$PROJECT" --env-file "$PROD_DIR/sizes/${VV_SIZE:-large}.env" --env-file "$GEN/.env" "$@"; }

load_secrets() {
    set -a
    # shellcheck disable=SC1091
    source "$GEN/secrets.env"
    set +a
}

PASS=0
FAIL=0
FAILED_NAMES=()
ok() { PASS=$((PASS + 1)); printf '  \033[32mOK\033[0m    %s\n' "$*"; }
bad() { FAIL=$((FAIL + 1)); FAILED_NAMES+=("$*"); printf '  \033[31mFAIL\033[0m  %s\n' "$*"; }
section() { printf '\n== %s\n' "$*"; }
# check "mô tả" lệnh... : đạt khi lệnh thoát 0
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; fi; }
# check_fails "mô tả" lệnh... : đạt khi lệnh thoát khác 0
check_fails() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d"; else ok "$d"; fi; }
summary() {
    printf '\n== Kết quả: %d đạt, %d lỗi\n' "$PASS" "$FAIL"
    if [[ $FAIL -gt 0 ]]; then printf 'Lỗi:\n'; printf '  - %s\n' "${FAILED_NAMES[@]}"; return 1; fi
}
