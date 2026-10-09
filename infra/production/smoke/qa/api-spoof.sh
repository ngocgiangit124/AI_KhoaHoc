#!/usr/bin/env bash
# QA T35: header IP giả gửi vào host api. của stack smoke ĐANG CHẠY (smoke.sh --keep) không đổi IP mà Laravel thấy.
# Cách đo: limiter `catalog` của GET /api/v1/config/public là 120/phút THEO $request->ip() (không token => bỏ X-Client-IP). Nếu Laravel tin
# X-Forwarded-For/X-Real-IP giả (đổi mỗi request) thì không bao giờ chạm 120. Phân biệt 429 của Laravel (có header X-RateLimit/Retry-After) với Nginx.
set -uo pipefail
# shellcheck source=../lib.sh disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib.sh"
API="api.vvsmoke.internal"
U="$VV_SMOKE_BASE/api/v1/config/public"
run() { # run <spoof 0|1>: 135 request tuần tự -> "<số 200> <số 429 Laravel> <số 429 khác>"
  local ok=0 lv=0 other=0 i out code hdr
  for i in $(seq 1 135); do
    if [[ $1 == 1 ]]; then
      out="$(curl -s -D- -o /dev/null -H "Host: $API" -H "X-Forwarded-For: 10.9.$((i/250)).$((i%250+1))" -H "X-Real-IP: 10.8.8.$((i%250+1))" -H "X-Client-IP: 10.7.7.$((i%250+1))" -H "Forwarded: for=10.6.6.$((i%250+1))" "$U")"
    else
      out="$(curl -s -D- -o /dev/null -H "Host: $API" "$U")"
    fi
    code="$(head -1 <<<"$out" | awk '{print $2}')"
    if [[ $code == 200 ]]; then ok=$((ok+1)); elif [[ $code == 429 ]]; then
      if grep -qi '^x-ratelimit\|^retry-after' <<<"$out"; then lv=$((lv+1)); else other=$((other+1)); fi
    else other=$((other+1)); fi
  done
  echo "$ok $lv $other"
}
echo "chờ 65s cho cửa sổ limiter trống"; sleep 65
read -r a b c <<<"$(run 0)"; echo "control (không header giả):  200=$a laravel429=$b khác=$c"
[[ $b -ge 1 && $a -le 125 ]] && echo "OK    control: Laravel throttle theo IP (120/phút) hoạt động" || { echo "FAIL  control không chạm 120/phút"; exit 1; }
echo "chờ 65s"; sleep 65
read -r a b c <<<"$(run 1)"; echo "spoof (XFF/X-Real-IP/X-Client-IP/Forwarded đổi mỗi request): 200=$a laravel429=$b khác=$c"
if [[ $b -ge 1 && $a -le 125 ]]; then echo "OK    header IP giả KHÔNG đổi IP Laravel thấy (vẫn bị throttle 120/phút)"; else echo "FAIL  header giả né được throttle theo IP"; exit 1; fi
