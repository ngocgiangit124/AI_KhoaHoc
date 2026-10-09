#!/usr/bin/env bash
# QA T35: Nginx production (conf.d + snippets NGUYÊN VĂN, chỉ điền placeholder) trước backend giả.
#  - host web/admin: X-Forwarded-Host/X-Real-IP/Forwarded/X-Forwarded-For/X-Internal-Token/X-Client-IP do khách gửi bị ghi đè hoặc xoá
#  - access log của /phu-huynh/huy-nhan-thong-bao không chứa ?t=
#  - 429 khi flood web; 40 client cùng IP mức bình thường 200
#  - host lạ -> 444
# Không đụng stack nào khác. Container/network tạm tên vvqa-*; tự dọn khi thoát.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD="$(cd "$HERE/../.." && pwd)"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  OK    %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
NG=vvqa-nginx; ECHO=vvqa-echo
T="$(mktemp -d)"
cleanup() { docker rm -f $ECHO $NG >/dev/null 2>&1; rm -rf "$T"; }
trap cleanup EXIT
mkdir -p "$T/conf.d" "$T/snippets" "$T/ssl" "$T/log"
cp "$PROD"/nginx/snippets/*.conf "$T/snippets/"
sed -i.bak 's#<IP_MONITOR_LB>#127.0.0.1#' "$T/snippets/vv-api-common.conf"
sed -e 's#<IP_NOI_BO_NGINX>#127.0.0.1#' -e 's#<IP_NEXT_SERVER>#10.231.10.0/24#' "$PROD/nginx/conf.d/vitaminvui.conf" > "$T/conf.d/vitaminvui.conf"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$T/ssl/privkey.pem" -out "$T/ssl/fullchain.pem" -subj "/CN=qa" -days 1 >/dev/null 2>&1
cp "$T/ssl/fullchain.pem" "$T/ssl/default.crt"; cp "$T/ssl/privkey.pem" "$T/ssl/default.key"
chmod -R a+rX "$T"; chmod 777 "$T/log"
cat > "$T/echo.js" <<'JS'
const http=require('http');
for (const p of [3000,3001]) http.createServer((q,r)=>{r.setHeader('content-type','application/json');r.end(JSON.stringify({port:p,url:q.url,h:q.headers}));}).listen(p,'127.0.0.1');
JS
docker rm -f $ECHO $NG >/dev/null 2>&1
docker run -d --name $NG -p 127.0.0.1:18443:443 \
  -v "$T/conf.d:/etc/nginx/conf.d:ro" -v "$T/snippets:/etc/nginx/snippets:ro" -v "$T/ssl:/etc/ssl/vitaminvui:ro" -v "$T/ssl:/etc/ssl/vitaminvui-media:ro" -v "$T/log:/var/log/nginx" \
  nginx:1.27.5@sha256:6784fb0834aa7dbbe12e3d7471e69c290df3e6ba810dc38b34ae33d3c1c05f7d >/dev/null || { echo "không chạy được nginx"; exit 2; }
docker run -d --name $ECHO --network container:$NG -v "$T/echo.js:/echo.js:ro" node:22-bookworm-slim node /echo.js >/dev/null || { echo "không chạy được echo"; exit 2; }
sleep 3
R() { curl -sk --resolve "$1:18443:127.0.0.1" "https://$1:18443$2" "${@:3}"; }
SPOOF=(-H 'X-Forwarded-Host: evil.example' -H 'X-Real-IP: 6.6.6.6' -H 'Forwarded: for=6.6.6.6;host=evil.example' -H 'X-Forwarded-For: 6.6.6.6' -H 'X-Internal-Token: stolen' -H 'X-Client-IP: 7.7.7.7')
jq_h() { python3 -I -c 'import json,sys;o=json.load(sys.stdin);v=o["h"].get(sys.argv[1]);print("<absent>" if v is None else v)' "$1"; }
for spec in "vitaminvui.vn /" "vitaminvui.vn /_next/static/x.js" "vitaminvui.vn /phu-huynh/huy-nhan-thong-bao?t=QASECRETTOKEN" "admin.vitaminvui.vn /" "admin.vitaminvui.vn /_next/static/x.js"; do
  set -- $spec; host="$1"; path="$2"
  body="$(R "$host" "$path" "${SPOOF[@]}")"
  [[ "$(jq_h x-forwarded-host <<<"$body")" == "$host" ]] && ok "$host$path: X-Forwarded-Host ghi đè = \$host" || bad "$host$path: X-Forwarded-Host = $(jq_h x-forwarded-host <<<"$body")"
  xr="$(jq_h x-real-ip <<<"$body")"; xf="$(jq_h x-forwarded-for <<<"$body")"
  [[ "$xr" != 6.6.6.6 && "$xr" != "<absent>" && "$xf" == "$xr" ]] && ok "$host$path: X-Real-IP/X-Forwarded-For = remote_addr ($xr), không nhận 6.6.6.6" || bad "$host$path: real-ip=$xr xff=$xf"
  [[ "$(jq_h forwarded <<<"$body")" == "" || "$(jq_h forwarded <<<"$body")" == "<absent>" ]] && ok "$host$path: Forwarded rỗng" || bad "$host$path: Forwarded=$(jq_h forwarded <<<"$body")"
  it="$(jq_h x-internal-token <<<"$body")"; ci="$(jq_h x-client-ip <<<"$body")"; [[ ( "$it" == "" || "$it" == "<absent>" ) && ( "$ci" == "" || "$ci" == "<absent>" ) ]] && ok "$host$path: X-Internal-Token/X-Client-IP bị xoá" || bad "$host$path: header nội bộ lọt qua"
done
[[ "$(R evil.example / -o /dev/null -w '%{http_code}' 2>&1)" == "000" ]] && ok "host lạ: Nginx đóng kết nối (444)" || bad "host lạ không bị 444: $(R evil.example / -o /dev/null -w '%{http_code}')"
# webhook Bunny ?k= (upstream php giả không tồn tại -> 502): access log phải che, error.log là giới hạn đã biết (README/snippet)
R api.vitaminvui.vn "/api/v1/webhooks/video/bunny?k=QAWEBHOOKK" -X POST -o /dev/null
# log
sleep 1
if grep -rq QASECRETTOKEN "$T/log"; then bad "log Nginx chứa ?t= ($(grep -rl QASECRETTOKEN "$T/log" | xargs -n1 basename | tr '\n' ' '))"; else ok "access log Nginx KHÔNG chứa ?t= (mọi file log)"; fi
if grep -l QAWEBHOOKK "$T"/log/*access* >/dev/null 2>&1; then bad "access log chứa ?k= ($(grep -l QAWEBHOOKK "$T"/log/*access* | xargs -n1 basename | tr '\n' ' '))"; else ok "access log KHÔNG chứa ?k= (webhook Bunny)"; fi
for f in error.log; do grep -q 'QASECRETTOKEN\|QAWEBHOOKK' "$T/log/$f" && echo "        INFO  $f có chứa query token khi upstream lỗi (giới hạn ĐÃ GHI trong snippet; cần quyền 0640 + xoay log)" || echo "        INFO  $f không chứa token"; done
ls "$T/log" | tr '\n' ' ' | sed 's/^/        file log: /'; echo
# flood / lớp học trên host web (vv_web 10r/s burst 200 nodelay)
codes() { seq 1 "$1" | xargs -P "$2" -I{} curl -sk -o /dev/null -w '%{http_code}\n' --resolve vitaminvui.vn:18443:127.0.0.1 "https://vitaminvui.vn:18443/khoa-hoc" | sort | uniq -c | awk '{print $2"="$1}' | tr '\n' ' '; }
NORMAL="$(codes 120 40)"; echo "        40 client x 3: $NORMAL"
[[ "$NORMAL" != *429* ]] && ok "web: 40 client cùng IP x 3 request: KHÔNG 429" || bad "web: lớp học bị 429 oan: $NORMAL"
sleep 25
FLOOD="$(codes 700 70)"; echo "        flood 700: $FLOOD"
[[ "$FLOOD" == *429* ]] && ok "web: flood 700 req -> có 429" || bad "web: flood không 429: $FLOOD"
printf '\n== Kết quả: %d đạt, %d lỗi\n' "$PASS" "$FAIL"
[[ $FAIL -eq 0 ]]
