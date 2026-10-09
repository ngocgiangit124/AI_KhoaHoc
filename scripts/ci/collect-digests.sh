#!/usr/bin/env bash
# Tạo images.lock từ metadata của docker/bake-action (T35-3, security S2).
# Dùng: collect-digests.sh <registry> <metadata.json>... > images.lock
# Mỗi dòng: IMAGE_DIGEST_<SVC>=sha256:<64 hex>  (dán vào /opt/vitaminvui/.env cho deploy.sh)
# Kèm dòng chú thích '# ref <image>@<digest>' dùng cho cosign verify. Chỉ digest công khai, không có secret.
set -euo pipefail

# REQUIRED_TARGETS (mặc định cả 4): target nào trong danh sách mà thiếu digest thì thoát != 0 (không ký thiếu im lặng).
required=" ${REQUIRED_TARGETS:-backend worker-video web admin} "
registry="${1:?registry}"
shift
[[ $# -ge 1 ]] || { echo "Thiếu file metadata" >&2; exit 2; }

for target in backend worker-video web admin; do
  digest=""
  tag=""
  for file in "$@"; do
    d="$(jq -r --arg t "$target" '.[$t]["containerimage.digest"] // empty' "$file")"
    if [[ -n "$d" ]]; then
      digest="$d"
      tag="$(jq -r --arg t "$target" '.[$t]["image.name"] // empty' "$file" | cut -d, -f1)"
    fi
  done
  if [[ -z "$digest" ]]; then
    if [[ "$required" == *" $target "* ]]; then
      echo "Thiếu digest cho $target (REQUIRED_TARGETS)" >&2
      exit 1
    fi
    continue
  fi
  [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || { echo "Digest không hợp lệ cho $target" >&2; exit 1; }
  case "$target" in
    backend) svc=BACKEND; repo=vitaminvui-backend ;;
    worker-video) svc=WORKER_VIDEO; repo=vitaminvui-worker-video ;;
    web) svc=WEB; repo=vitaminvui-web ;;
    admin) svc=ADMIN; repo=vitaminvui-admin ;;
  esac
  name="${tag:-$registry/$repo}"
  echo "IMAGE_DIGEST_${svc}=${digest}"
  echo "# ref ${name%%:*}@${digest}"
done
