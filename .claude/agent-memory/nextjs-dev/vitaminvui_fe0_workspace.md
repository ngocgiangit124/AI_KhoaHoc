---
name: vitaminvui-fe0-workspace
description: Cấu trúc pnpm workspace frontend VitaminVui, phiên bản package ghim ở FE0, cách chạy toàn bộ bằng Docker (không có Node/pnpm trên host)
metadata:
  type: project
---

Dự án VitaminVui (`/home/ngocgiang/TestAI_Agent`): backend Laravel 13 API-only (`backend/`,
do agent `laravel-dev` phụ trách), frontend `frontend/` là pnpm workspace 2 app Next.js +
3 package dùng chung, do agent `nextjs-dev` (tôi) phụ trách. Khởi tạo ở task FE0
(`docs/architecture/tasks.md`), hoàn thành 2026-09-25.

**Cấu trúc:** `apps/web` (học sinh, cổng 3000), `apps/admin` (quản trị, cổng 3001),
`packages/api-client` (TS thuần: publicFetch/authFetch/CSRF/deviceId/mã lỗi/safeRedirect,
không build step — dùng qua `transpilePackages` của Next), `packages/ui` (component
Tailwind dùng chung, phụ thuộc `@vitaminvui/api-client`), `packages/config`
(tsconfig.base.json dùng chung, eslint/base.mjs cấm `dangerouslySetInnerHTML`,
prettier.config.mjs). Cả 2 app scaffold bằng `create-next-app@16.3.6 --typescript
--tailwind --eslint --app` KHÔNG dùng `--src-dir` — `app/`, `lib/`, `components/`,
`env.ts` nằm thẳng ở root mỗi app, alias `@/*` trỏ root đó (không phải `src/`).

**Versions ghim (kiểm tra lại nếu có memory cũ hơn — luôn xác minh qua
`npm view <pkg> version` vì đây có thể đã đổi):** next 16.3.6, react/react-dom 19.2.8,
typescript 5.9.3 (KHÔNG dùng 7.x — xem [[typescript7_breaks_eslint]]), tailwindcss +
@tailwindcss/postcss 4.3.3, eslint 9.39.5 + eslint-config-next 16.3.6, prettier 3.9.9,
vitest 3.2.7 + @vitejs/plugin-react 5.2.0 (KHÔNG 6.x — đòi vite ^8, vitest 3 kéo vite 7),
@playwright/test 1.63.0, zod 4.6.5, pnpm 9.15.9, Node 22.

**Chạy mọi thứ qua Docker** vì host chỉ có Node 18: `frontend/scripts/pnpm.sh <args>`
(wrapper `docker run node:22-bookworm-slim`, `--user $(id -u):$(id -g)`, bind mount cache
pnpm store tại `frontend/.pnpm-store/`). `frontend/scripts/playwright.sh <web|admin>`
chạy e2e bằng ảnh `mcr.microsoft.com/playwright` (đã có browser sẵn). Compose riêng
`frontend/docker-compose.yml` chỉ cho `pnpm dev` — KHÔNG đụng `infra/docker-compose.yml`
của laravel-dev.

**Why:** PO/CLAUDE.md yêu cầu Node 22/pnpm 9 nhưng máy host chỉ có Node 18 → toàn bộ dev
loop (install/lint/typecheck/test/build/e2e) phải chạy trong container, không cài gì lên
host.

**How to apply:** Khi làm tiếp FW1+/FA1+, tiếp tục dùng 2 script wrapper này thay vì gọi
`pnpm`/`npx playwright` trực tiếp. Trước khi thêm dependency mới, `npm view <pkg> version`
qua `docker run node:22 npm view ...` để lấy version thật (không đoán từ trí nhớ).
