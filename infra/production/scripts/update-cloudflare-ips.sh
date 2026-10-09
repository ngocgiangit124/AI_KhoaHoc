#!/usr/bin/env bash
# TUỲ CHỌN, CHỈ KHI BẬT CLOUDFLARE PROXY (hiện tạm bỏ proxy, PO 2026-10-10; KHÔNG cron mặc định). Cập nhật dải IP Cloudflare trong
# nginx/snippets/vv-real-ip.cloudflare.conf (sau khi copy thành vv-real-ip.conf) từ nguồn chính thức (GL-A2/S3, ADR-008).
# Dùng: update-cloudflare-ips.sh [đường-dẫn-file-real-ip]   (mặc định: /etc/nginx/snippets/vv-real-ip.conf, file phải có marker BEGIN/END cloudflare-ips)
# Cron hàng tuần, sau đó script tự chạy `nginx -t` và reload; lỗi thì khôi phục bản cũ. Không thay đổi gì nếu tải lỗi/rỗng.
set -euo pipefail

TARGET="${1:-/etc/nginx/snippets/vv-real-ip.conf}"
TMP="$(mktemp)"; BACKUP="$(mktemp)"
trap 'rm -f "$TMP" "$BACKUP"' EXIT

# Nguồn cho phép ghi đè bằng biến môi trường CF_IPS_V4_URL/CF_IPS_V6_URL (chỉ để test với nguồn giả: file:// được chấp nhận
# khi đặt CF_IPS_ALLOW_FILE=1); mặc định là nguồn chính thức qua HTTPS.
V4_URL="${CF_IPS_V4_URL:-https://www.cloudflare.com/ips-v4}"
V6_URL="${CF_IPS_V6_URL:-https://www.cloudflare.com/ips-v6}"
PROTO="=https"; [ "${CF_IPS_ALLOW_FILE:-0}" = "1" ] && PROTO="=https,file"

# Phải có đủ marker trước khi ghi (thiếu marker thì sed in thêm bản cũ -> trùng/ hỏng cấu hình).
grep -q '^# --- BEGIN cloudflare-ips' "$TARGET" && grep -q '^# --- END cloudflare-ips' "$TARGET" \
  || { echo "file đích thiếu marker BEGIN/END cloudflare-ips" >&2; exit 1; }

v4="$(curl -fsS --proto "$PROTO" --max-time 15 "$V4_URL")"
v6="$(curl -fsS --proto "$PROTO" --max-time 15 "$V6_URL")"

# Kiểm MỌI dòng (không chỉ 1 dòng): chỉ CIDR hợp lệ, prefix tối thiểu v4 /8 và v6 /24 (dòng v6 phải bắt đầu bằng nhóm hex, không nhận `::/…` bao trùm IPv4-mapped — security V3-1) (dải thật rộng nhất: v4 /13, v6 /29),
# nên không chèn được cấu hình nginx hay dải quá rộng kiểu 0.0.0.0/1.
bad4="$(printf '%s\n' "$v4" | grep -Ev '^([0-9]{1,3}\.){3}[0-9]{1,3}/([89]|[12][0-9]|3[0-2])$' || true)"
bad6="$(printf '%s\n' "$v6" | grep -Ev '^[0-9a-fA-F]{1,4}:[0-9a-fA-F:]*/(2[4-9]|[3-9][0-9]|1[01][0-9]|12[0-8])$' || true)"
[ -z "$bad4" ] && [ -z "$bad6" ] || { echo "có dòng CIDR không hợp lệ hoặc quá rộng" >&2; exit 1; }
[ "$(printf '%s\n' "$v4" | wc -l)" -ge 10 ] && [ "$(printf '%s\n' "$v6" | wc -l)" -ge 5 ] || { echo "danh sách quá ngắn" >&2; exit 1; }

{
  sed '/^# --- BEGIN cloudflare-ips/,$d' "$TARGET"
  echo "# --- BEGIN cloudflare-ips (sinh bởi update-cloudflare-ips.sh) ---"
  echo "# IPv4"
  echo "$v4" | while read -r cidr; do [ -n "$cidr" ] && echo "set_real_ip_from ${cidr};"; done
  echo "# IPv6"
  echo "$v6" | while read -r cidr; do [ -n "$cidr" ] && echo "set_real_ip_from ${cidr};"; done
  echo "# --- END cloudflare-ips ---"
  sed '1,/^# --- END cloudflare-ips/d' "$TARGET"
} > "$TMP"

cp "$TARGET" "$BACKUP"
cat "$TMP" > "$TARGET"

if nginx -t; then
  nginx -s reload
  echo "Đã cập nhật dải IP Cloudflare và reload Nginx."
else
  cat "$BACKUP" > "$TARGET"
  echo "nginx -t lỗi, đã khôi phục bản cũ." >&2
  exit 1
fi
