# VitaminVui — Frontend

Workspace pnpm chứa 2 app Next.js (App Router) + 3 package dùng chung. Xem
`docs/architecture/tasks.md` (mục FE0), `docs/adr/ADR-004-...md`, `docs/architecture/api-contract.md`,
`docs/design/design-system.md` cho đặc tả gốc.

```
frontend/
  apps/web/     Next.js — học sinh (vitaminvui.vn), cổng 3000
  apps/admin/   Next.js — quản trị (admin.vitaminvui.vn), cổng 3001
  packages/
    api-client/ TS thuần: publicFetch, authFetch, CSRF, deviceId, mã lỗi, safeRedirect...
    ui/         Component Tailwind dùng chung (Button, Alert, ForcedLogoutOverlay...)
    config/     tsconfig.base.json, eslint (cấm dangerouslySetInnerHTML), prettier.config.mjs
  scripts/
    pnpm.sh        Chạy pnpm trong Docker (install/lint/typecheck/test/build...)
    playwright.sh  Chạy e2e trong Docker (ảnh mcr.microsoft.com/playwright, có sẵn trình duyệt)
  Dockerfile.dev     Ảnh dev dùng chung (node:22-bookworm-slim + pnpm 9 qua corepack)
  docker-compose.yml `pnpm dev` cho cả 2 app (KHÔNG phải infra/docker-compose.yml của backend)
```

## Vì sao mọi thứ chạy trong Docker

Máy host chỉ có Node 18 — **không dùng được** cho dự án này (yêu cầu Node 22). Mọi lệnh
`pnpm`/`next`/test đều chạy qua Docker (`node:22-bookworm-slim`, pnpm 9 bật bằng
corepack), container chạy với `--user $(id -u):$(id -g)` để file tạo ra thuộc về bạn,
không phải root. Cache pnpm store nằm ở `frontend/.pnpm-store/` (bind mount, gitignored)
để không tải lại package mỗi lần.

**Không cài Node/pnpm trên host** là chủ đích — nếu bạn có Node 22 thật, mọi lệnh dưới
đây vẫn chạy được bỏ qua `scripts/pnpm.sh`, chỉ cần `pnpm install` rồi `pnpm -r <script>`.

## Yêu cầu

- Docker Desktop (đã xác nhận 28.4) + Compose v2 (đã xác nhận v2.39), chạy trên WSL2.
- Không cần cài gì khác trên host.

## Cài đặt lần đầu

```bash
cd frontend
cp apps/web/.env.example apps/web/.env.local
cp apps/admin/.env.example apps/admin/.env.local
# Sửa .env.local nếu cần (mặc định đã khớp domain *.localhost dùng ở local).

./scripts/pnpm.sh install
```

`.env.local` không commit (đã trong `.gitignore`). File `.env.local` đã tạo sẵn trong
repo này chỉ chứa giá trị mặc định cho local (không có secret thật) — vẫn nên tự kiểm
tra lại trước khi dùng cho môi trường khác.

## Chạy dev (cả 2 app)

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f frontend/docker-compose.yml up
```

- Web: http://api.localhost:3000
- Admin: http://admin-api.localhost:3001
  (Chrome/Firefox tự phân giải `*.localhost` → 127.0.0.1, không cần sửa `/etc/hosts`.)

**Vì sao web mở ở `api.localhost` chứ không phải `localhost`/`app.localhost`:** Chromium coi các host
`*.localhost` khác nhau (và `localhost`) là *cross-site*; cookie phiên của API (`SameSite=Lax`) bị bỏ ở
request cross-site, mọi POST trả 419. Đã kiểm bằng Chromium thật: chỉ cặp CÙNG hostname (khác cổng) mới gửi
cookie (`api.localhost:3000` ↔ `api.localhost:8000`, `admin-api.localhost:3001` ↔ `admin-api.localhost:8000`).
Production (`vitaminvui.vn` ↔ `api.vitaminvui.vn`) cùng registrable domain nên không bị. Backend phải khớp:
`FRONTEND_URL=http://api.localhost:3000`, `ADMIN_URL=http://admin-api.localhost:3001`,
`SANCTUM_STATEFUL_DOMAINS=api.localhost:3000,admin-api.localhost:3001` (xem `backend/.env.example`).

Chạy riêng 1 app: `docker compose -f frontend/docker-compose.yml up web` (hoặc `admin`).

Hoặc không dùng compose, chạy trực tiếp qua wrapper:

```bash
frontend/scripts/pnpm.sh --filter @vitaminvui/web run dev
```

## Lint / Typecheck / Test / Build

```bash
frontend/scripts/pnpm.sh run lint        # eslint (cả 2 app)
frontend/scripts/pnpm.sh run typecheck   # next typegen && tsc --noEmit (mọi package)
frontend/scripts/pnpm.sh run test        # vitest run (mọi package)
frontend/scripts/pnpm.sh run build       # next build (cả 2 app)
frontend/scripts/pnpm.sh audit --prod    # kiểm lỗ hổng dependency production
```

Chạy riêng cho 1 package: `frontend/scripts/pnpm.sh --filter @vitaminvui/api-client run test`.

**Kết quả thực tế đã chạy** (xem báo cáo bàn giao để có số liệu mới nhất): lint 0 lỗi,
typecheck 0 lỗi, 64 unit test pass (api-client 34, ui 16, web 9, admin 5), build thành
công cả 2 app, `pnpm audit --prod` không có lỗ hổng nào.

## E2E (Playwright)

Dùng ảnh chính thức `mcr.microsoft.com/playwright` (đã có sẵn trình duyệt — ảnh
`node:22-bookworm-slim` dùng cho lệnh thường không cài trình duyệt để giữ nhẹ).

```bash
frontend/scripts/playwright.sh web
frontend/scripts/playwright.sh admin
```

Mặc định mỗi app có `e2e/mock-api-server.mjs` (chỉ dùng Node `http`, không thêm package)
giả lập tối thiểu host `api`/`admin-api` theo đúng field trong `api-contract.md`, để test
không phụ thuộc backend.

**Đã xác nhận chạy được với backend Laravel thật** (T01 xong, chạy qua
`infra/docker-compose.yml`, publish cổng 8000 ra host):

```bash
frontend/scripts/playwright.sh web --real-backend
frontend/scripts/playwright.sh admin --real-backend
```

`--real-backend` bỏ qua mock, dùng `--network host` + `--add-host api.localhost:127.0.0.1
--add-host admin-api.localhost:127.0.0.1` (xem
`scripts/playwright.sh`) để container Docker phân giải `*.localhost` — glibc resolver mặc
định (khác trình duyệt) KHÔNG tự phân giải các domain này. Cả 2 chế độ đều pass (3/3 test).

Test hiện có (đã chạy pass ở cả mock và backend thật):
- `apps/web/e2e/home.spec.ts` — trang chủ hiển thị lớp 6–12, có header CSP chứa `nonce-`.
- `apps/admin/e2e/dang-nhap.spec.ts` — `/dang-nhap` lấy được CSRF token từ admin-api (CORS đúng origin), có CSP.
- `apps/admin/e2e/cross-origin-blocked.spec.ts` — gọi admin-api từ origin web (`api.localhost:3000`) bị CORS chặn (mở web ở `api.localhost:3000`).

## Biến môi trường

Validate bằng `zod` trong `apps/*/env.ts` (public, đọc được ở trình duyệt — tiền tố
`NEXT_PUBLIC_*`) và `apps/web/env.server.ts` (server-only, có `import 'server-only'`).
Next.js ném lỗi rõ ràng ngay khi thiếu/sai biến (chạy lúc `next dev`/`next build`).

| App | Biến | Ghi chú |
|---|---|---|
| web | `NEXT_PUBLIC_API_URL` | Base URL host `api` — trình duyệt gọi trực tiếp |
| web | `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_STATIC_URL` | |
| web | `NEXT_PUBLIC_VIDEO_HOSTS` | Danh sách host, phân tách dấu phẩy (CSP `connect-src`/`media-src`) |
| web | `NEXT_PUBLIC_TURNSTILE_SITE_KEY` | Để trống ở local (chờ PO — G4) |
| web | `NEXT_PUBLIC_MOMO_HOSTS` | Allowlist mở `pay_url` (S23); non-prod `test-payment.momo.vn` |
| web | `API_INTERNAL_URL` **(server-only)** | Laravel gọi từ SSR (`lib/api.server.ts`). Trong Docker Compose dùng `host.docker.internal:8000`; chạy `next dev` thẳng trên máy (không qua Docker) thì đổi lại `http://api.localhost:8000` |
| admin | `NEXT_PUBLIC_ADMIN_API_URL`, `NEXT_PUBLIC_ADMIN_URL` | |
| admin | `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_UPLOAD_URL` | |

Biến mới thêm sau này: cập nhật `.env.example` tương ứng + báo PO/Architect (không bao
giờ đặt secret vào biến `NEXT_PUBLIC_*`).

## Bảo mật đã dựng ở FE0 (ADR-004)

- **CSP có nonce** sinh trong `proxy.ts` mỗi app (Next.js 16 đổi tên từ `middleware.ts`
  — xem `node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/proxy.md`
  sau khi `pnpm install`). `frame-src` khác nhau: web cho phép YouTube-nocookie/Vimeo,
  admin `'none'`.
- Header khác: `Strict-Transport-Security` (chỉ non-dev), `X-Content-Type-Options`,
  `Referrer-Policy`, `Permissions-Policy`.
- `next.config.ts`: `poweredByHeader: false`, `images.remotePatterns` chỉ `STATIC_URL`.
- `publicFetch` (không cookie, được cache/ISR) tách bạch `authFetch`
  (`credentials:'include'`, luôn `cache:'no-store'`) — `packages/api-client`.
- `authFetch` tự gắn `X-Device-Id`, tự gắn `X-CSRF-TOKEN` cho method ghi, tự lấy lại CSRF
  và thử lại đúng 1 lần khi gặp 419. Lỗi mạng (`NetworkError`) tách biệt hẳn với lỗi có
  `code` từ API (`ApiError`) — không bao giờ coi mất mạng là mất phiên.
- Mã lỗi `SESSION_REPLACED` → sự kiện `forced-logout`; `SESSION_EXPIRED`/`SESSION_REVOKED`/
  `UNAUTHENTICATED`/`STAFF_IDLE_TIMEOUT` → `login-required`. `<ForcedLogoutOverlay>`
  (packages/ui) lắng 2 sự kiện này, đã gắn ở `apps/web/app/layout.tsx`:
  - `forced-logout` (nghi vấn bị chiếm tài khoản) → overlay chặn toàn màn hình, không thể đóng (US-014 §2.1).
  - `login-required` (phiên hết hạn bình thường) → **êm hơn**: chuyển hướng thẳng tới
    `/dang-nhap`, giữ đường dẫn hiện tại qua `?next=` (validate bằng `safeRedirect`),
    không hiện overlay chặn.
- `safeRedirect`, `isAllowedPayUrl`, `jsonLd` (`packages/api-client/src/security.ts`) —
  chống open redirect / chỉ mở MoMo đúng host / escape JSON-LD (S23).
- ESLint cấm `dangerouslySetInnerHTML` toàn workspace
  (`packages/config/eslint/base.mjs`) trừ file được allowlist tường minh trong
  `eslint.config.mjs` của app — chưa có allowlist nào ở FE0 (sẽ thêm ở FW2 cho
  component render `courses.description` qua DOMPurify).
- Route cần đăng nhập phải tự đặt `export const dynamic = 'force-dynamic'` và dùng
  `authFetch`; chỉ `publicFetch` được dùng `revalidate`/ISR (S16) — quy ước ghi trong
  comment `apps/web/lib/api.ts` / `apps/web/lib/api.server.ts`.

## Vấn đề kiến trúc CSP nonce vs ISR — ĐÃ CHỐT (ADR-004 §2.7)

FE0 từng nêu xung đột: CSP nonce (bắt buộc theo ADR-004 §2.6) buộc trang render động,
trong khi trang công khai muốn ISR/Full Route Cache để tối ưu SEO/hiệu năng. Architect đã
chốt ở **ADR-004 §2.7 (2026-09-25)**: chọn phương án **(a)** — giữ nonce cho mọi trang
HTML, chấp nhận mất Full Route Cache, cache dữ liệu ở tầng Next Data Cache
(`publicFetch(url, { revalidate, tags })`). Ngưỡng hiệu năng chấp nhận được: p95 TTFB
≤ 500 ms ở 50 req/s (cache ấm), ≤ 1,2 s (cache lạnh) — đo ở FW2 bằng k6/autocannon.

Đã áp dụng ở FE0: `app/page.tsx` (web) dùng `export const dynamic = 'force-dynamic'` +
`publicFetchServer(path, { revalidate: 60 })`; `proxy.ts` matcher loại trừ đúng danh sách
route không phải HTML theo §2.7 (`_next/static`, `_next/image`, `favicon.ico`,
`robots.txt`, `sitemap.xml`, file tĩnh trong `public/`).

## Giả định đã xác nhận với backend thật

- **Hình dạng response `GET /api/v1/config/public`**: **đã xác nhận khớp 100%** với
  backend Laravel thật (T01) — response phẳng, field `otp: { ttl_minutes,
  resend_cooldown_seconds }` đúng ví dụ JSON trong `api-contract.md` §2.1. `apps/web/lib/types/config.ts`
  export `publicConfigSchema` (zod) validate response này lúc runtime — schema sai (đổi
  tên/kiểu field) sẽ ném lỗi rõ ràng, được `app/error.tsx` bắt như lỗi API khác (không
  crash trắng trang), không chỉ tin type TypeScript tĩnh.
- **`X-Device-Id` khi không có `localStorage`** (SSR): `packages/api-client/src/deviceId.ts`
  sinh UUID tạm mỗi lần gọi ở server thay vì id ổn định — chấp nhận được vì các luồng SSR
  ở FE0 chỉ gọi endpoint công khai (không cần device ổn định); cần xem lại nếu sau này có
  SSR gọi endpoint cần `X-Device-Id` thật.

## Tích hợp Docker với backend/infra (khi T01 xong)

Frontend hiện có `docker-compose.yml` **riêng**, không đụng `infra/docker-compose.yml`
của `laravel-dev`. Khi ghép:
- SSR (`API_INTERNAL_URL`) cần gọi được sang Laravel. Trên Docker Desktop/WSL2,
  `http://host.docker.internal:8000` đã hoạt động sẵn (không cần cấu hình network) **miễn
  là** `infra/docker-compose.yml` publish cổng 8000 ra host. Nếu muốn 2 compose project
  dùng chung network Docker (không qua host), cần thêm `networks: { default: { external: true, name: ... } }`
  vào cả 2 file — đề nghị thống nhất tên network với `laravel-dev`.
- Nginx của infra cần thêm `admin-api.localhost` vào cấu hình
  host-based routing đã mô tả ở ADR-004 §2.1 (nếu chưa có).
- `NEXT_PUBLIC_API_URL`/`NEXT_PUBLIC_ADMIN_API_URL` trong `.env.local` đã khớp domain cục
  bộ theo bảng ADR-004 §2.1 — không cần đổi khi backend lên đúng cổng 8000.

## Package đã cài ở FE0 (theo đúng danh sách "cài ngay" của tasks.md)

Nền: `next` 16.3.6, `react`/`react-dom` 19.2.8, `typescript` 5.9.3, `tailwindcss` +
`@tailwindcss/postcss` 4.3.3, `zod` 4.6.5.
Dev: `eslint` 9.39.5 + `eslint-config-next` 16.3.6, `prettier` 3.9.9, `vitest` 3.2.7 (+
`@vitejs/plugin-react`, `jsdom`), `@testing-library/react` + `dom` + `jest-dom` +
`user-event`, `@playwright/test` 1.63.0.

**Chưa cài** (đúng theo tasks.md — cài ở task dùng tới): `react-hook-form` +
`@hookform/resolvers`, `isomorphic-dompurify`, `katex`, `hls.js`, `tus-js-client`,
`@dnd-kit/core` + `@dnd-kit/sortable`. Widget Turnstile dùng script chính thức, không
cần package.

`typescript` chọn bản 5.9.3 thay vì bản mới nhất trên npm (`7.0.2`, bản viết lại bằng Go)
vì `typescript-eslint` (phụ thuộc của `eslint-config-next`) hiện chỉ hỗ trợ
`typescript >=4.8.4 <6.1.0` — dùng TS 7 sẽ vỡ lint. `eslint` cũng cố tình dùng nhánh 9.x
(không phải 10.x mới nhất) để khớp đúng câu chữ "ESLint 9" của tasks.md; cả 2 đều tương
thích `eslint-config-next` (peer `>=9.0.0`).

## Component đã có trong `packages/ui` (chưa đủ hết design-system.md §5.1)

Đã có: `Button`, `Badge`, `StatusPill`, `Alert`, `Modal`, `ConfirmModal`, `Card`,
`EmptyState`, `Skeleton`, `Spinner`, `Table`, `Toast`/`useToast`, `ForcedLogoutOverlay`,
cùng `formatCurrencyVnd`/`formatDateVn`/`formatDateTimeVn` (Intl, luôn ép
`timeZone: 'Asia/Ho_Chi_Minh'` để tránh lỗi hydration).

`Table` (`packages/ui/src/Table.tsx`) là bản **tối thiểu** (cột + dòng + trạng thái
loading/rỗng) đúng tên nêu trong tasks.md FE0 — chưa phải `<DataTable>` đầy đủ của
design-system.md §5.1 (chưa có slot filter, header sticky); mở rộng dần khi FA8 (đơn
hàng)/FA7 (mã giảm giá) cần.

**Chưa có** (cố ý để lại cho FW/FA — cần `react-hook-form` hoặc chưa có consumer để viết
test có ý nghĩa): `Pagination`, `CursorPagination`, `ProgressBar`, `FormField`/`TextInput`/
`Select`/`Textarea`, `OtpInput`, `PasswordInput`, `Countdown`, `Avatar`, `Breadcrumb`,
`TurnstileWidget`, `AuditLogTable`, `PiiMaskedText`, và mọi component riêng
`apps/web`/`apps/admin` (§5.2, §5.3).

## Quy ước

- Đặt tên component `PascalCase.tsx`; `packages/ui` = dùng chung 2 app, nghiệp vụ riêng
  đặt trong `apps/web`/`apps/admin`.
- `apps/web` và `apps/admin` **không đặt `src/`** — `app/`, `lib/`, `components/`,
  `env.ts` ở ngay root app (theo đúng scaffold `create-next-app@16.3.6 --typescript
  --tailwind --eslint --app`), alias `@/*` trỏ vào root app đó.
- Route quản trị đặt trong `apps/admin`; route học sinh trong `apps/web` — không dùng
  chung layout/cookie giữa 2 app (ADR-004 §2.2).
