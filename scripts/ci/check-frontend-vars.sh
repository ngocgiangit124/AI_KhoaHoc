#!/usr/bin/env bash
# Kiểm biến NEXT_PUBLIC_* lấy từ vars của GitHub Environment trước khi build image (T35-3, ADR-008 §8.9).
# Chỉ in TÊN biến lỗi, không in giá trị. Dùng: VV_ENV=staging|production check-frontend-vars.sh
set -euo pipefail

VV_ENV="${VV_ENV:?VV_ENV là bắt buộc}"
case "$VV_ENV" in staging | production) ;; *) echo "VV_ENV phải là staging hoặc production" >&2; exit 2 ;; esac

required=(NEXT_PUBLIC_API_URL NEXT_PUBLIC_SITE_URL NEXT_PUBLIC_STATIC_URL NEXT_PUBLIC_VIDEO_HOSTS
  NEXT_PUBLIC_TURNSTILE_SITE_KEY NEXT_PUBLIC_ADMIN_API_URL NEXT_PUBLIC_ADMIN_URL NEXT_PUBLIC_VIDEO_UPLOAD_URL)
urls=(NEXT_PUBLIC_API_URL NEXT_PUBLIC_SITE_URL NEXT_PUBLIC_STATIC_URL NEXT_PUBLIC_ADMIN_API_URL NEXT_PUBLIC_ADMIN_URL NEXT_PUBLIC_VIDEO_UPLOAD_URL)

fail=0
for name in "${required[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "::error::Environment '$VV_ENV' thiếu var $name"
    fail=1
  fi
done
for name in "${urls[@]}"; do
  value="${!name:-}"
  [[ -n "$value" ]] || continue
  if [[ ! "$value" =~ ^https:// ]]; then
    echo "::error::Var $name phải bắt đầu bằng https://"
    fail=1
  fi
done
for name in "${required[@]}" NEXT_PUBLIC_MOMO_HOSTS; do
  value="${!name:-}"
  if [[ "$value" =~ (localhost|127\.0\.0\.1|REPLACE|test-payment|1x0000000000) ]]; then
    echo "::error::Var $name chứa giá trị local/giữ chỗ/khoá thử"
    fail=1
  fi
done
exit "$fail"
