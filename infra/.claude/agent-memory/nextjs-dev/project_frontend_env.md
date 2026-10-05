---
name: frontend-docker-mac
description: scripts/pnpm.sh hỏng trên macOS (UID 501); cách chạy pnpm trong Docker và quy ước auth FW1
metadata:
  type: project
---

`frontend/scripts/pnpm.sh` giả định UID 1000 (WSL): trên macOS corepack lỗi EACCES ở /home/node. Chạy thay bằng `docker run --user $(id -u):$(id -g) -e HOME=/tmp/home -e COREPACK_HOME=/tmp/corepack -v frontend:/workspace vitaminvui-frontend-dev:latest sh -c 'mkdir -p /tmp/home /tmp/corepack && pnpm ...'`. Build cần truyền env NEXT_PUBLIC_* / API_INTERNAL_URL qua `-e` (không có .env.local).

Auth FW1 (apps/web): form ở `components/auth/`, logic ở `lib/auth/` (api, errors, schemas, age); UI chung (FormField, TextInput, PasswordInput, Select, TurnstileWidget) ở packages/ui. `/auth/me` gọi bằng fetch thẳng (không authFetch) để 401 của khách không phát login-required. Sau login/register/logout phải clearCsrfToken.
