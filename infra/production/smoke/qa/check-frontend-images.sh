#!/usr/bin/env bash
# QA T35-2: kiểm image web/admin production đã build (giá trị NEXT_PUBLIC_* theo dạng production, khoá Turnstile giả hợp lệ, KHÔNG phải khoá thử).
# Dùng: check-frontend-images.sh [--build]   (image: qa-local/vitaminvui-{web,admin}:production-qa). --build build bằng frontend/docker-bake.hcl.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../../.." && pwd)"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  OK    $*"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
chk() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; fi; }
if [[ "${1:-}" == "--build" ]]; then
  (cd "$REPO" && VV_REGISTRY=qa-local IMAGE_TAG=qa VV_ENV=production \
    NEXT_PUBLIC_API_URL=https://api.vitaminvui.vn NEXT_PUBLIC_SITE_URL=https://vitaminvui.vn \
    NEXT_PUBLIC_STATIC_URL=https://static.vitaminvui-media.net NEXT_PUBLIC_VIDEO_HOSTS=https://cdn.vitaminvui.asia \
    NEXT_PUBLIC_TURNSTILE_SITE_KEY=0x4AAAAAAAqaFakeSiteKey1 NEXT_PUBLIC_MOMO_HOSTS= \
    NEXT_PUBLIC_ADMIN_API_URL=https://admin-api.vitaminvui.vn NEXT_PUBLIC_ADMIN_URL=https://admin.vitaminvui.vn \
    NEXT_PUBLIC_VIDEO_UPLOAD_URL=https://cdn.vitaminvui.asia \
    docker buildx bake -f frontend/docker-bake.hcl --load web admin) >/dev/null 2>&1 || { echo "build lỗi"; exit 2; }
fi
for app in web admin; do
  I="qa-local/vitaminvui-$app:production-qa"
  echo "== $I"
  docker image inspect "$I" >/dev/null 2>&1 || { bad "chưa có image $I"; continue; }
  chk "$app: USER không phải root" test "$(docker image inspect --format '{{.Config.User}}' "$I")" != "" -a "$(docker image inspect --format '{{.Config.User}}' "$I")" != "root" -a "$(docker image inspect --format '{{.Config.User}}' "$I")" != "0"
  uid="$(docker run --rm --entrypoint id "$I" -u 2>/dev/null)"; [[ "$uid" != "0" && -n "$uid" ]] && ok "$app: uid thực tế $uid" || bad "$app: chạy uid '$uid'"
  [[ -z "$(docker run --rm --entrypoint sh "$I" -c "find / -xdev \( -name '.env' -o -name '.env.*' -o -name '*.pem' -o -name '*.key' \) -not -path '*/node_modules/*' 2>/dev/null | head -3")" ]] && ok "$app: không có .env*/*.pem/*.key ngoài node_modules" || bad "$app: có .env/khoá: $(docker run --rm --entrypoint sh "$I" -c "find / -xdev \( -name '.env' -o -name '.env.*' \) 2>/dev/null | head -3")"
  chk "$app: label vv.env=production" test "$(docker image inspect --format '{{ index .Config.Labels "vv.env" }}' "$I")" = "production"
  hits="$(docker run --rm --entrypoint sh "$I" -c "grep -rIlE 'test-payment\\.momo\\.vn|1x00000000000000000000AA|2x00000000000000000000AB|3x00000000000000000000FF|api\\.localhost|admin-api\\.localhost|video\\.localhost|localhost:8000|localhost:8080|localhost:300[01]' /app/apps 2>/dev/null | head")"
  [[ -z "$hits" ]] && ok "$app: không có host test MoMo/khoá thử Turnstile/host local trong /app/apps (framework Next trong node_modules có chuỗi localhost:3000 nội bộ, vô hại)" || bad "$app: còn $hits"
  lh="$(docker run --rm --entrypoint sh "$I" -c "grep -rIlE 'localhost|127\\.0\\.0\\.1' /app/apps 2>/dev/null | grep -v node_modules | head")"
  [[ -z "$lh" ]] && ok "$app: 'localhost'/127.0.0.1 không có trong /app/apps (mã ứng dụng + .next)" || { echo "        INFO  localhost trong: $(echo "$lh" | tr '\n' ' ')"; }
  hist="$(docker history --no-trunc "$I")"
  grep -qiE 'INTERNAL_API_TOKEN|SECRET|PASSWORD|PRIVATE' <<<"$hist" && bad "$app: docker history có từ khoá secret" || ok "$app: docker history không có INTERNAL_API_TOKEN/SECRET/PASSWORD"
  grep -q 'momo' <(docker run --rm --entrypoint sh "$I" -c "grep -rIho 'https\\?://[a-z.-]*momo[a-z.-]*' /app/apps 2>/dev/null | sort -u") && bad "$app: có host momo" || ok "$app: không có host momo"
done
echo; echo "== Kết quả: $PASS đạt, $FAIL lỗi"; [[ $FAIL -eq 0 ]]
