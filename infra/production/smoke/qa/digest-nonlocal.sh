#!/usr/bin/env bash
# QA T35: đường KHÔNG --local của deploy/rollback với digest sai/lệch/thiếu, trên stack smoke ĐANG CHẠY (smoke.sh --with-frontend --keep).
# `docker compose pull` được mô phỏng bằng shim (gắn tag <sha40> cho ảnh local đã đổi nhãn revision = "registry trả ảnh lạ"); mọi thứ còn lại
# là deploy.sh thật. Ảnh build local không có RepoDigest nên KHÔNG kiểm được nhánh digest ĐÚNG (chỉ kiểm được trên staging với GHCR).
set -uo pipefail
# shellcheck source=../lib.sh disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib.sh"
DEPLOY="$PROD_DIR/deploy.sh"
S1="$(printf 'c%.0s' $(seq 1 40))"; S2="$(printf 'd%.0s' $(seq 1 40))"
ZERO="sha256:$(printf '0%.0s' $(seq 1 64))"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  OK    $*"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
L="$GEN/logs/qa"; mkdir -p "$L"
running_tag() { docker inspect --format '{{.Config.Image}}' "$(dc ps -q "$1" | head -1)" | sed 's/.*://'; }
REAL_DOCKER="$(command -v docker)"
SHIM="$(mktemp -d)"
cat > "$SHIM/docker" <<SH
#!/usr/bin/env bash
if [[ "\$1" == compose && " \$* " == *" pull "* ]]; then
  echo "[shim] compose pull -> gắn tag \$IMAGE_TAG từ ảnh qasrc" >&2
  $REAL_DOCKER tag vv-local/vitaminvui-backend:qasrc-\$IMAGE_TAG vv-local/vitaminvui-backend:\$IMAGE_TAG || exit 1
  $REAL_DOCKER tag vv-local/vitaminvui-web:production-dev vv-local/vitaminvui-web:production-\$IMAGE_TAG || exit 1
  $REAL_DOCKER tag vv-local/vitaminvui-admin:production-dev vv-local/vitaminvui-admin:production-\$IMAGE_TAG || exit 1
  exit 0
fi
exec $REAL_DOCKER "\$@"
SH
chmod +x "$SHIM/docker"
cleanup() { rm -rf "$SHIM"; "$REAL_DOCKER" rmi "vv-local/vitaminvui-backend:qasrc-$S1" "vv-local/vitaminvui-backend:qasrc-$S2" >/dev/null 2>&1; [[ -f "$GEN/.env.qa-bak" ]] && cp "$GEN/.env.qa-bak" "$GEN/.env" && rm -f "$GEN/.env.qa-bak"; }
trap cleanup EXIT
for s in "$S1" "$S2"; do
  printf 'FROM vv-local/vitaminvui-backend:%s\nLABEL org.opencontainers.image.revision=%s\n' "$SMOKE_TAG" "$s" | "$REAL_DOCKER" build -q -t "vv-local/vitaminvui-backend:qasrc-$s" - >/dev/null || { echo "build qasrc lỗi"; exit 2; }
done
cp "$GEN/.env" "$GEN/.env.qa-bak"
setv() { sed -i.tmp "/^$1=/d" "$GEN/.env"; rm -f "$GEN/.env.tmp"; [[ -n "${2-}" ]] && echo "$1=$2" >> "$GEN/.env"; true; }
nd() { PATH="$SHIM:$PATH" "$@"; }
BEFORE="$(running_tag scheduler)"

# --- deploy không-local ---
setv IMAGE_DIGEST_BACKEND "$ZERO"; setv IMAGE_DIGEST_WEB "$ZERO"; setv IMAGE_DIGEST_ADMIN "$ZERO"
nd "$DEPLOY" deploy "$S1" >"$L/dep-wrong.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "digest image" "$L/dep-wrong.log" && grep -q "pull image" "$L/dep-wrong.log" && ok "deploy không-local, digest LỆCH sau pull -> dừng (rc=$rc)" || { bad "deploy digest lệch (rc=$rc), xem $L/dep-wrong.log"; tail -5 "$L/dep-wrong.log"; }
[[ "$(running_tag scheduler)" == "$BEFORE" ]] && ok "deploy digest lệch: container KHÔNG đổi (vẫn $BEFORE)" || bad "container đã đổi sang $(running_tag scheduler)"
grep -q "^IMAGE_TAG=$BEFORE$" "$GEN/.env" && ok "deploy digest lệch: .env IMAGE_TAG không đổi" || bad ".env IMAGE_TAG bị đổi"
grep -q "preflight\|migrate bằng" "$L/dep-wrong.log" && bad "deploy digest lệch đã chạy preflight/migrate" || ok "deploy digest lệch: chưa preflight, chưa migrate"
setv IMAGE_DIGEST_WEB ""
nd "$DEPLOY" deploy "$S1" >"$L/dep-miss.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "thiếu IMAGE_DIGEST_WEB" "$L/dep-miss.log" && ! grep -q "pull image" "$L/dep-miss.log" && ok "deploy không-local, thiếu digest WEB -> dừng TRƯỚC pull" || bad "deploy thiếu digest WEB (rc=$rc)"
setv IMAGE_DIGEST_WEB "sha256:abc"
nd "$DEPLOY" deploy "$S1" >"$L/dep-fmt.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "không đúng dạng" "$L/dep-fmt.log" && ok "deploy digest sai định dạng -> dừng" || bad "deploy digest sai định dạng (rc=$rc)"

# --- rollback không-local: ảnh tag đích KHÔNG còn trên server -> bắt buộc digest + kiểm sau pull ---
setv IMAGE_DIGEST_BACKEND "$ZERO"; setv IMAGE_DIGEST_WEB "$ZERO"; setv IMAGE_DIGEST_ADMIN "$ZERO"
setv IMAGE_TAG "$S2"; setv PREVIOUS_TAG "$S1"
"$REAL_DOCKER" rmi "vv-local/vitaminvui-backend:$S1" "vv-local/vitaminvui-web:production-$S1" "vv-local/vitaminvui-admin:production-$S1" >/dev/null 2>&1
nd "$DEPLOY" rollback >"$L/rb-wrong.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "digest image" "$L/rb-wrong.log" && ok "rollback không-local, ảnh thiếu + digest LỆCH sau pull -> dừng (rc=$rc)" || { bad "rollback digest lệch (rc=$rc)"; tail -5 "$L/rb-wrong.log"; }
[[ "$(running_tag scheduler)" == "$BEFORE" ]] && ok "rollback digest lệch: container KHÔNG đổi" || bad "container đã đổi"
grep -q "^IMAGE_TAG=$S2$" "$GEN/.env" && grep -q "^PREVIOUS_TAG=$S1$" "$GEN/.env" && ok "rollback digest lệch: .env không bị ghi" || bad ".env bị ghi"
"$REAL_DOCKER" rmi "vv-local/vitaminvui-backend:$S1" "vv-local/vitaminvui-web:production-$S1" "vv-local/vitaminvui-admin:production-$S1" >/dev/null 2>&1
setv IMAGE_DIGEST_ADMIN ""
nd "$DEPLOY" rollback >"$L/rb-miss.log" 2>&1; rc=$?
[[ $rc -ne 0 ]] && grep -q "thiếu IMAGE_DIGEST_ADMIN" "$L/rb-miss.log" && ! grep -q "\[shim\]" "$L/rb-miss.log" && ok "rollback không-local, ảnh thiếu + thiếu digest -> dừng TRƯỚC pull" || bad "rollback thiếu digest (rc=$rc)"

# --- rollback khi ảnh đích CÒN trên server (R17): không pull, không kiểm digest (thiết kế) -> ghi nhận hành vi, rồi khôi phục ---
"$REAL_DOCKER" tag "vv-local/vitaminvui-backend:qasrc-$S1" "vv-local/vitaminvui-backend:$S1"
"$REAL_DOCKER" tag vv-local/vitaminvui-web:production-dev "vv-local/vitaminvui-web:production-$S1"
"$REAL_DOCKER" tag vv-local/vitaminvui-admin:production-dev "vv-local/vitaminvui-admin:production-$S1"
setv IMAGE_DIGEST_BACKEND "$ZERO"; setv IMAGE_DIGEST_WEB "$ZERO"; setv IMAGE_DIGEST_ADMIN "$ZERO"
nd "$DEPLOY" rollback --force-schema-ahead >"$L/rb-present.log" 2>&1; rc=$?
echo "  INFO  rollback khi ảnh đích đã có trên server + digest trong .env sai: rc=$rc, container -> $(running_tag scheduler) (KHÔNG kiểm digest, theo R17)"
cp "$GEN/.env.qa-bak" "$GEN/.env"
"$REAL_DOCKER" rmi "vv-local/vitaminvui-backend:$S1" "vv-local/vitaminvui-web:production-$S1" "vv-local/vitaminvui-admin:production-$S1" "vv-local/vitaminvui-backend:$S2" "vv-local/vitaminvui-web:production-$S2" "vv-local/vitaminvui-admin:production-$S2" >/dev/null 2>&1
"$REAL_DOCKER" rmi "vv-local/vitaminvui-backend:qasrc-$S1" "vv-local/vitaminvui-backend:qasrc-$S2" >/dev/null 2>&1
echo; echo "== Kết quả: $PASS đạt, $FAIL lỗi"
[[ $FAIL -eq 0 ]]
