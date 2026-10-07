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
| admin | `V2_PREVIEW` **(server-only)** | Bản xem trước design v2 `/v2/...` (nhóm route `(v2-preview)`) trả 404 ở production (`NODE_ENV=production`) trừ khi đặt `V2_PREVIEW=1`. `next dev` luôn xem được. Đọc lúc chạy (layout gọi `connection()`), không cần build lại |

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
  `eslint.config.mjs` của app. `apps/web` có 2 allowlist (FW2): `CourseDescription.tsx`
  (render `courses.description` qua DOMPurify — `lib/catalog/sanitize.ts`) và `seo/JsonLd.tsx`
  (JSON-LD có nonce; chỉ nhận object rồi serialize bằng `jsonLd()`).
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

Đã cài ở task sau: `react-hook-form` + `@hookform/resolvers` (FW1), `isomorphic-dompurify`
4.4.0 (FW2 — mô tả khóa; kéo theo `jsdom` + `dompurify`, chỉ `apps/web`).

**Chưa cài** (đúng theo tasks.md — cài ở task dùng tới): `katex`, `hls.js`, `tus-js-client`,
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

## FW-V2 — Nền design v2 cho `apps/web` (thật)

- `app/layout.tsx`: `<html class="theme-v2" data-theme="light">` + font Be Vietnam Pro/Mali (`next/font`), `AppProviders`
  (`UiLinkProvider` dùng `next/link` + `ToastProvider` v2). Giao diện tối chưa bật (chờ PO, §13).
- Khung trang thật: `components/shell/SiteShell` (header/footer dựng từ `SiteHeader`/`SiteFooter` v2; trạng thái đăng nhập từ
  `/auth/me` qua `AuthProvider`). Route group `(site)` (trang chủ, danh mục, lớp, chi tiết) dùng khung đầy đủ; `(auth)`
  (đăng nhập/đăng ký/OTP) dùng khung `minimal`. Đường dẫn thật ở `lib/routes.ts`; chưa có "Khóa học của tôi"/tài khoản/giỏ hàng
  nên chưa có trong header/footer/bottom-nav (thêm khi FW3–FW5 xong).
- Màn xác thực FW1 giữ nguyên logic/test, chỉ đổi khung/token; làm lại giao diện chi tiết sau khi designer xong.
- **`/v2` (bản xem trước) bị chặn ở production** bằng `proxy.ts` + `lib/previewGate.ts`: khi `NODE_ENV=production` và không có
  `V2_PREVIEW=1` (đọc lúc chạy) proxy rewrite sang route không tồn tại -> 404 thật, response vẫn mang CSP + header bảo mật. Cổng
  chuẩn hoá đường dẫn (giải mã phần trăm `/%76%32`, gộp `//`, không phân biệt hoa thường) và matcher có entry riêng `/v2`,
  `/v2/:path*` KHÔNG có `missing` nên request prefetch cũng bị chặn. `(v2-preview)/v2/layout.tsx` gọi `notFound()` chỉ là lớp phụ
  (khi đã stream thì trả 200 và payload RSC còn nội dung xem trước, nên không đủ một mình). Test: `lib/previewGate.test.ts`, `proxy.test.ts`.
  Kiểm tay trên `next start`: `curl -i /v2`, `/%76%32/khoa-hoc`, `//v2/khoa-hoc`, kèm `-H 'next-router-prefetch: 1' -H 'purpose: prefetch'`
  đều phải 404 và có `Content-Security-Policy`; `V2_PREVIEW=1` -> 200.
- SSR gọi Laravel: xem `API_INTERNAL_URL` trong `.env.example` (ADR-004 §2.8).

## FW2 — Danh mục và chi tiết khóa học (`apps/web`)

Route: `/khoa-hoc` (danh mục), `/lop-{6..12}` (segment động ở gốc `app/[lopSlug]` vì App Router
không có segment tiền tố; `lop-99`/`lop-09`/`lop-abc` -> `notFound()`), `/khoa-hoc/{slug}`,
`/robots.txt` (tĩnh, prerender), `/sitemap.xml` (route handler `force-dynamic`, không prerender lúc build; dữ liệu qua Data Cache `revalidate: 3600` tag `catalog`; `Cache-Control: public, s-maxage=3600, stale-while-revalidate=600`).

- Giao diện theo màn của designer (`(v2-preview)/v2/khoa-hoc`) với dữ liệu thật, tái dùng `CourseCard`/`CourseCover`/
  `Pagination`/`Breadcrumb`/`EmptyState` của `@vitaminvui/ui/v2`. `paid_checkout_enabled=false` (`/config/public`): thẻ và trang
  chi tiết hiện giá + "Sắp mở bán", không có nút mua (`lib/catalog/cta.ts`). Bài học thử mới chỉ có nhãn (phát video: FW4).
- SSR nội bộ: `publicFetchServer` gắn `X-Internal-Token` (`INTERNAL_API_TOKEN`) cho mọi request catalog; `X-Client-IP` chỉ gắn
  cho tìm kiếm `q` vì Next đưa HEADER vào khoá Data Cache (gắn IP cho mọi request sẽ tách cache theo từng IP và phình đĩa).
  Đã chốt ở ADR-004 §2.8 (production: `API_INTERNAL_URL=http://<IP_NOI_BO_NGINX>:8081`; local: `http://api.localhost:8000`).
- API trả 429/5xx/mất kết nối: trang chủ, đăng ký, OTP, danh mục, lớp, chi tiết hiện "Hệ thống đang bận" (`lib/catalog/busy.ts`,
  `CatalogBusy`, có `noindex`), không lộ 500 trần; response không-200 không vào Data Cache (Next chỉ ghi status 200 — có test khoá).
  Sitemap khi API lỗi trả 503 + `Retry-After` + `no-store`. Phân trang chỉ dựa `meta.current_page/last_page`, không dùng `links`/`meta.path`.
- Khóa đã sở hữu: nút "Tiếp tục học" vô hiệu ("Sắp mở trang học") và bài trong outline là hàng tĩnh cho tới khi FW4 có route `/hoc/...`
  (`TODO(FW4)` trong `CourseAction.tsx`, `CourseOutline.tsx`).
- Render động + CSP nonce (ADR-004 §2.7); dữ liệu qua `publicFetchServer` với `revalidate: 60`,
  `tags: ['catalog']` (`lib/catalog/api.ts`). Ghi/đổi dữ liệu catalog ở nơi khác có thể gọi
  `revalidateTag('catalog', 'max')`.
- Bộ lọc/trang/sắp xếp nằm trên URL (`grade`, `subject_ids` lặp key, `q`, `sort`, `page`); giá trị sai
  bị bỏ qua êm. `/lop-{grade}` cố định lớp trong đường dẫn.
- HTTP 404 thật cho `/lop-99` và slug không tồn tại: kiểm ở `layout.tsx` (ngoài ranh giới
  `loading.tsx`). Vì vậy đã BỎ `app/loading.tsx` gốc (nó làm mọi `notFound()` trả 200 + noindex).
  Nếu cần skeleton cho trang khác, đặt `loading.tsx` trong segment của trang đó.
- `viewer-state` gọi phía client bằng `authFetch`, chỉ khi `/auth/me` báo đã đăng nhập
  (`components/catalog/CourseCtaProvider.tsx`). Mua/giỏ hàng: nút chờ T16/FW3.
- `instrumentation.ts` nạp sẵn `isomorphic-dompurify` lúc khởi động: `require('jsdom')` tốn vài giây
  (đo được 15 s trên máy dev), nếu không người xem đầu tiên của trang chi tiết sẽ gánh.
  Readiness check của deploy nên gọi 1 request (vd. `/khoa-hoc`) trước khi nhận tải.
- E2E thật: `e2e/danh-muc-real.spec.ts` + `e2e/seed-e2e-catalog.sh` (`--clean` để dọn).
  Chạy với bản build: đặt `E2E_WEB_COMMAND` (xem `playwright.config.ts`).

### Load test (ADR-004 §2.7) — kết quả 2026-10-05

Công cụ: k6 (`loadtest/catalog.k6.js`), bản build production (`next start`), 1 tiến trình Node, trong
Docker Desktop (Intel i5-1038NG7, 6 vCPU) khi máy đang chạy nhiều container khác (load average ~19 trên 4
nhân, php ~100-150% CPU) — nên số liệu bên dưới là cận xấu, KHÔNG phải kết luận cuối. Dữ liệu: 32 khóa
đã publish, mix 50% chi tiết / 50% danh mục.

| Kịch bản | Mục tiêu | Kết quả |
|---|---|---|
| Cache lạnh (mỗi request là MISS Data Cache, 1,5 req/s, 90 request, đã warm-up jsdom) | p95 TTFB <= 1,2 s | ĐẠT: p95 TTFB 330 ms, p95 tổng 601 ms, max 1,09 s |
| Cache ấm, 50 req/s, 1 instance | p95 TTFB <= 500 ms | KHÔNG ĐẠT: máy chỉ chịu ~26 req/s (p95 > 40 s do xếp hàng) |
| Cache ấm, 50 req/s, 3 instance cùng máy | p95 TTFB <= 500 ms | KHÔNG ĐẠT: ~40 req/s, p95 6,3 s (CPU máy bão hoà) |
| Cache ấm, 10 req/s / 25 req/s, 1 instance | tham khảo | p95 117 ms / 852 ms |

Năng lực 1 instance (k6 50 req/s từng route): `/robots.txt` 50 req/s; `/lop-99` (404) 40; `/khoa-hoc`
(25 thẻ) 30; chi tiết khóa 39; `/lop-12` 48. Chi phí cơ bản mỗi trang động (~25 ms CPU ở môi trường này) chiếm
phần lớn, không riêng FW2. Chưa kết luận được mục tiêu ấm 50 req/s: cần chạy lại trên máy/CI Linux yên
tĩnh với số instance cố định (ADR §2.7: không đạt thì mở rộng instance Next.js trước). Khuyến nghị tối thiểu
3–4 instance sau Nginx cho 50 req/s.

**RỦI RO MỞ (R11):** mục tiêu ADR-004 §2.7 "cache ấm 50 req/s, p95 TTFB <= 500 ms" CHƯA đạt trên máy dev (1 instance ~26 req/s, 3 instance
cùng máy ~40 req/s); chỉ cache lạnh đạt. Quyết định: không chạy lại ở máy dev; chạy lại trên staging/CI Linux yên tĩnh với số instance
cố định, không đạt thì mở rộng instance Next.js trước (ADR §2.7), khuyến nghị 3–4 instance sau Nginx.

Throttle `catalog`: SSR nay gửi `X-Internal-Token` nên Laravel tính nhóm SSR riêng (ADR-004 §2.8), không còn gộp với người dùng; chi tiết
ở mục SSR nội bộ phía trên.

### Backlog FW2 (ghi nhận, chưa làm)

- R13: slug đúng định dạng nhưng không tồn tại gọi API mỗi lần (404 không vào Data Cache); bot dò slug ăn hạn mức `ssr-total`. Thêm kịch
  bản slug lạ vào `loadtest/catalog.k6.js`.
- R14: QA kiểm `isomorphic-dompurify`/jsdom trên bản `next build` (không cảnh báo `default-stylesheet.css`, trang chi tiết có mô tả vẫn
  render ở production). `instrumentation.ts` đã nạp sẵn module.
- R8: `(v2-preview)/v2/layout.tsx` còn nạp lại font Be Vietnam Pro/Mali (trùng layout gốc) — file của designer, bỏ khi xoá nhóm `(v2-preview)`.
