# Chia task — VitaminVui MVP

**Liên quan:** [README.md](README.md) · [data-model.md](data-model.md) · [api-contract.md](api-contract.md) · [review-traceability.md](review-traceability.md) · ADR-001..004
**Cập nhật 2026-09-25:** Laravel 13 (PO chốt dùng ngay từ đầu) / PHP 8.3 / MySQL 8.4; admin tách origin; đã gắn yêu cầu từ review Security (S1–S24) và DBA (#1–#10).

## Quy ước chung

- **Done của mọi task backend:** migration có `down()` + model (`$fillable` tường minh) + factory + Form Request + Policy + feature test Pest cho mọi AC và mọi test bảo mật được nêu trong task. `./vendor/bin/pint --test`, `./vendor/bin/phpstan` (Larastan level 6) và `./vendor/bin/pest` đều pass.
- **Nhãn:**
  - **[SEC]**: phải qua `laravel-security` review code trước khi merge vào nhánh release.
  - **[DBA]**: `laravel-dba` review migration/truy vấn trước khi chạy trên môi trường chung (MySQL 8.4, không phải SQL Server).
- Ước lượng tính cho 1 dev.
- **Agent:** backend → `laravel-dev`; frontend → `nextjs-dev`.

## Cấu trúc repo (áp dụng từ T01 / FE0)

```
/home/ngocgiang/TestAI_Agent/          (gốc repo — khởi tạo git ở T01)
├── CLAUDE.md
├── backend/                            Laravel 13 (T01)
├── frontend/                           pnpm workspace Next.js (FE0)
│   ├── apps/web/                       vitaminvui.vn — học sinh, cổng 3000
│   ├── apps/admin/                     admin.vitaminvui.vn — quản trị, cổng 3001
│   └── packages/{api-client,ui,config}/
├── infra/                              docker-compose.yml, nginx/, php/, worker-video/ (T01, T12)
└── docs/
```
Đề nghị PO/Tech bổ sung vào mục "Quy ước" của CLAUDE.md: "backend ở `backend/`, frontend ở `frontend/`; chạy lệnh artisan/pnpm trong đúng thư mục".

## Cổng chặn

- [x] **G0:** Laravel 13 + PHP 8.3 + MySQL 8.4 LTS; admin origin riêng; staging domain riêng (Quyết định của PO 2026-09-25).
- [x] **G3:** UI quản trị = Next.js (Quyết định của PO).
- [ ] **G1:** Có người/agent `nextjs-dev` phụ trách frontend — cần cho FE0.
- [ ] **G2 — duyệt package (cài ở task dùng tới):**
  - backend: `mews/purifier` (T08), `intervention/image` ^3 (T08), `openspout/openspout` ^4 (T25), `larastan/larastan` dev (T01);
  - frontend: xem FE0.
  - Không cần package thanh toán/phân quyền/Horizon.
  - **Kiểm tương thích Laravel 13 khi cài:**

    | Package | Mức chắc chắn | Dev cần làm khi cài |
    |---|---|---|
    | `laravel/sanctum`, `laravel/pint`, `pestphp/pest` + `pest-plugin-laravel` | Package chính chủ/hệ sinh thái — cài bằng ràng buộc để Composer tự chọn bản hỗ trợ Laravel 13 | Không ghim major cũ |
    | `larastan/larastan` | **Chưa chắc** — mỗi major Laravel cần bản Larastan tương ứng | Chạy `composer require --dev larastan/larastan` (không ghim) và kiểm `composer why-not laravel/framework ^13`. Nếu chưa hỗ trợ: tạm bỏ khỏi `composer ci`, ghi TODO, báo Architect |
    | `mews/purifier` | **Chưa chắc** — package cộng đồng, phụ thuộc `illuminate/*` theo từng bản | Kiểm `composer.json` của bản mới nhất có `illuminate/support ^13`. Nếu chưa: dùng thẳng `ezyang/htmlpurifier` (không phụ thuộc Laravel) bọc trong `HtmlSanitizer` — cấu hình allowlist không đổi |
    | `intervention/image` ^3 | Không phụ thuộc Laravel (chỉ cần PHP ≥ 8.1 + GD/Imagick) | Không dùng `intervention/image-laravel` (không cần), tránh phụ thuộc thêm |
    | `openspout/openspout` ^4 | Không phụ thuộc Laravel | Kiểm yêu cầu PHP của bản mới nhất khớp 8.3 |

    Quy tắc: **không** dùng `--ignore-platform-reqs` hay fork để ép cài; package không hỗ trợ → báo Architect chọn thay thế.
- [ ] **G4 (không chặn dev local):** mua/tên miền staging + tên miền tĩnh; Turnstile site key; hộp thư gửi mail (SMTP) — cần trước khi dựng staging.

---

## T01 — Khởi tạo backend Laravel 13 + hạ tầng local (~1,5 ngày) **[SEC] [DBA]** — SẴN SÀNG

**Phiên bản:**
- PHP 8.3, **Laravel 13.x** (yêu cầu PHP ≥ 8.3).
- Sanctum, Pest (+ `pest-plugin-laravel`), Pint, Larastan: **bản mới nhất hỗ trợ Laravel 13**, để Composer tự chọn — không ghim major cũ (Sanctum 4 / Pest 3 của bản thiết kế trước có thể không còn đúng).
- MySQL 8.4 LTS, Redis 7 (extension `phpredis`, không dùng predis), Nginx 1.27, Mailpit.

**Lệnh khởi tạo (tham khảo):**
```bash
cd /home/ngocgiang/TestAI_Agent && git init
composer create-project laravel/laravel:^13.0 backend
cd backend && php artisan install:api                     # Sanctum bản tương thích + routes/api.php
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan -W   # không ghim major
./vendor/bin/pest --init
composer show laravel/framework laravel/sanctum pestphp/pest larastan/larastan   # ghi phiên bản thực tế vào backend/README.md
```
Nếu skeleton Laravel 13 đã cấu hình Pest sẵn (tuỳ lựa chọn của installer) thì bỏ bước cài Pest. Larastan chưa hỗ trợ Laravel 13 → xử lý theo bảng ở G2.

**Hạ tầng local `infra/docker-compose.yml`:**

| Service | Cấu hình |
|---|---|
| `php` | PHP 8.3-fpm; extension `pdo_mysql`, `redis`, `gd`, `intl`, `bcmath`, `zip`, `pcntl`, `exif` |
| `nginx` | 3 server block `api.localhost`, `admin-api.localhost`, `video.localhost` → cùng `backend/public` |
| `mysql` | `mysql:8.4` với `--character-set-server=utf8mb4 --collation-server=utf8mb4_0900_ai_ci --transaction-isolation=READ-COMMITTED --binlog-format=ROW` |
| `redis` | `redis:7`, `requirepass` |
| `mailpit` | |
| `queue` | `php artisan queue:work --queue=default,exports` |
| `scheduler` | `php artisan schedule:work` |

- Worker video sandbox được thêm ở T12.
- Nginx:
  - chặn `/.env`, `/.git`, `/storage`;
  - `client_max_body_size 16k` cho `/api/v1/webhooks/`;
  - header `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: DENY`.

**`.env.example` (đủ khoá):**
- Ứng dụng: `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE=Asia/Ho_Chi_Minh`, `APP_API_HOST=api.localhost`, `APP_ADMIN_API_HOST=admin-api.localhost`, `FRONTEND_URL=http://api.localhost:3000`, `ADMIN_URL=http://admin-api.localhost:3001`, `STATIC_URL`
- Phiên & proxy: `SANCTUM_STATEFUL_DOMAINS=api.localhost:3000,admin-api.localhost:3001`, `SESSION_DRIVER=redis`, `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=false` (local), `SESSION_COOKIE=vv_session`, `SESSION_ADMIN_COOKIE=vv_admin_session`, `TRUSTED_PROXIES=`
- DB: `DB_*` (mysql)
- Redis: `REDIS_PASSWORD`, `REDIS_DB_SESSION=1`, `REDIS_CACHE_DB=2`, `REDIS_QUEUE_DB=3`, `REDIS_LIMITER_DB=4`
- Mail: `MAIL_*` (Mailpit)
- Thanh toán: `PAYMENT_GATEWAYS=fake` (local), `MOMO_*` (trống)
- Video: `VIDEO_PROVIDER=internal`
- Chống bot & OTP: `CAPTCHA_DRIVER=fake`, `AUTH_OTP_CHANNELS=email`
- Dữ liệu cá nhân: `PRIVACY_POLICY_VERSION=2026-09`, `PRIVACY_PARENT_CONSENT_AGE=18`
- `FEATURE_*` (README §5)

**Việc phải làm trong code:**
1. **`config/database.php` (mysql):**
   - `charset=utf8mb4`, `collation=utf8mb4_0900_ai_ci`, `timezone=+07:00`, `strict=true`;
   - `options` có `PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'` (DBA #1 — **không dùng khoá `isolation_level`**).
2. **Redis:** tách connection theo DB (session / cache / queue / limiter) trong `config/database.php` + `session.connection`, `cache.stores.redis.connection`, `queue.connections.redis.connection`, `cache.limiter`.
3. **`bootstrap/app.php`:**
   - Routing: `routes/api.php` bọc `Route::domain(config('app.api_host'))`; tạo `routes/admin.php` bọc `Route::domain(config('app.admin_api_host'))`, cùng tiền tố `api/v1`.
   - Middleware: `statefulApi()`; `trustHosts(at: [api_host, admin_api_host])`; `trustProxies(at: env TRUSTED_PROXIES)` (không `*`).
   - Prepend global `ConfigureHostContext` (session cookie/lifetime/same_site/expire_on_close + `cors.allowed_origins` theo host — ADR-004 §2.2), `AssignRequestId`, `SecurityHeaders`.
   - Alias middleware: `no_store` (`NoStoreForAuthenticated`), `admin.origin` (`EnsureAdminOrigin`), `staff.idle` (`StaffIdleTimeout`) — 2 middleware đầu có test ở T01, `staff.idle` là khung để T28 dùng.
   - Exceptions: render JSON cho mọi request `api/*` theo envelope api-contract §1.7 (`DomainException`, `ValidationException` → 422 `VALIDATION_ERROR`, `AuthenticationException` → 401 `UNAUTHENTICATED` (tombstone thêm ở T05), 404/405/429/413); `dontFlash` thêm `password`, `password_confirmation`, `code`, `parent_phone`, `parent_email`, `captcha_token` (S21).
4. **`config/cors.php`:** `paths ['api/*']`, `supports_credentials true`, headers theo ADR-004 §2.3 (origin do middleware đặt).
5. **Endpoint:**
   - `GET /api/v1/csrf-token` trên cả 2 host.
   - `GET /api/v1/config/public` (host api) trả **allowlist khoá** (api-contract §2.1).
   - `GET /api/v1/health` (host api, không lộ phiên bản).
6. **`config/features.php`, `config/privacy.php`, `config/payments.php`** (`enabled_gateways` từ env; boot guard production: cấm `fake` + endpoint sandbox — S4), `config/auth.php` (khối `otp`).
7. **`AppServiceProvider`:**
   - `Model::shouldBeStrict(! $this->app->isProduction())`;
   - `Model::preventLazyLoading` ở local/testing;
   - `Password::defaults(min 8)`;
   - `JsonResource::withoutWrapping()` — object đơn lẻ trả phẳng, danh sách giữ `data`/`meta`/`links` (api-contract §1.4); test: `/config/public` trả object phẳng đúng ví dụ ở api-contract §2.1;
   - `RateLimiter::for(...)` khai báo đủ limiter ở api-contract §1.6 (trả khung, dùng dần ở các task sau).
8. **Logging:** channel `payments`, `video`, `playback` (daily, `days=90`); không log body `/auth/*`.
9. **Script composer `ci`:** `pint --test && phpstan analyse && pest`. `phpstan.neon` level 6.
10. **README backend:** hướng dẫn chạy docker, thêm `*.localhost` (Chrome/Firefox tự phân giải), tạo DB, chạy test.

**Test bắt buộc (T01):**
- `SELECT @@transaction_isolation` = `READ-COMMITTED`.
- Bảng tạm có unique trên `varchar` collation `utf8mb4_0900_ai_ci`: "Hình học" vs "Hinh hoc", "Đại số" vs "Dai so" → ghi lại hành vi thực tế vào test (DBA checklist §5.7).
- `GET http://api.localhost/api/v1/config/public` → 200, `Cache-Control: public`; cùng path trên `admin-api.localhost` → 404.
- CORS: preflight từ `http://localhost:3000` tới host api → cho phép; từ `http://admin.localhost:3001` tới host api → không cho; ngược lại với host admin-api.
- Host lạ (`Host: evil.test`) → 404/400 (TrustHosts).
- `X-Forwarded-For` giả từ IP không tin cậy không đổi `request()->ip()` (S10).
- Response có `X-Request-Id`, `X-Content-Type-Options: nosniff`; route test có `auth` trả `Cache-Control: no-store, private`.
- Lỗi 404/422/500 trả đúng envelope JSON; 500 có `request_id`, không stack trace khi `APP_DEBUG=false`.

**Định nghĩa "xong" T01:**
- `docker compose up` chạy đủ service.
- `composer ci` pass.
- Các test trên pass.
- `.env.example` đủ khoá.
- `php artisan about` hiện Laravel 13.x, PHP 8.3, MySQL 8.4.
- Phiên bản thực tế của Sanctum/Pest/Larastan đã ghi vào `backend/README.md`.
- Security + DBA review cấu hình.

## T02 — Users, vai trò, audit log, quy ước bảo vệ (~1 ngày) **[SEC]** — SẴN SÀNG (sau T01)

1. **Migration `users`:**
   - đủ cột data-model §3.1 (email `varchar(254)`, `parent_phone`/`parent_email` kiểu `text`, `parent_consent_status`, `must_change_password`, `password_changed_at`, `current_session_id`, `current_device_id`, `anonymized_at`...);
   - CHECK `chk_users_grade_level`;
   - index (email_verified_at, phone_verified_at, created_at).
2. **Migration `audit_logs`** (data-model §3.1) + model `AuditLog`: không cho update/delete, ném exception nếu gọi.
3. **Enums:** `UserRole`, `UserStatus`, `ParentConsentStatus`, `OtpPurpose`, `ConsentType`.
4. **Model `User`:**
   - `$fillable` chỉ gồm `name`, `email`, `phone`, `password`, `grade_level`, `date_of_birth`, `parent_phone`, `parent_email`, `bio`, `referral_code_used` — **không** có `role`/`status`/`*_verified_at`/`current_session_id`/`parent_consent_status`;
   - casts (`parent_phone`/`parent_email` → `encrypted`, enum, datetime);
   - `$hidden` (password, remember_token, current_session_id, parent_*);
   - helpers `isAdmin/isStaff/isTeacher/isStudent`.
5. **Middleware:** `role` (`EnsureRole`), `account.active` (`EnsureAccountActive` → 403 `ACCOUNT_LOCKED`). Gate `manage-system`, `access-admin-area`.
6. **`App\Services\Audit\AuditLogger`:** tự lấy actor/role/ip/user_agent; lọc `changes` qua danh sách khoá cấm (`password`, `code`, `token`, `parent_*`, `email`, `phone`, `signature`).
7. **Command:**
   - `staff:create {email} {--role=}`: sinh mật khẩu ngẫu nhiên 20 ký tự, in ra **một lần**, `must_change_password=true`, ghi audit.
   - `staff:lock` / `staff:unlock` (ghi audit).
   - Seeder: chỉ dữ liệu demo ở `local` (guard `app()->environment('local')`), **không tạo admin ở production** (S15).
8. **Factory `User`** với state `admin()`, `pageManager()`, `teacher()`, `student()`, `verified()`, `minor()`.
9. **Test kiến trúc** (`tests/Arch/*`):
   - `arch()->expect('App\Models')->not->toHaveProperty('guarded')` hoặc kiểm `$guarded` khác `[]`;
   - `arch()->expect('App\Http\Controllers')->not->toUse('Illuminate\Http\Request::all')` (hoặc test grep `->all()`);
   - test duyệt `Route::getRoutes()`: mọi route có `auth:sanctum` trên host api phải có `account.active`, `student.single_session`, `no_store`; trên host admin-api phải có `admin.origin`, `account.active`, `staff.idle`, `staff.mfa_passed`, `staff.password_fresh`, `no_store` (trừ logout/mfa/password). Tạm cho phép `student.single_session`/`staff.*` là alias rỗng tới khi T05/T28 hiện thực.

**Test bắt buộc:**
- Tạo user với mảng chứa `role=admin`, `email_verified_at` → không được gán (S17).
- `parent_phone` trong DB là ciphertext.
- `AuditLog::first()->update()` → exception.
- `staff:create` ở `APP_ENV=production` vẫn chạy, còn seeder demo thì không.

**Định nghĩa "xong":** migration chạy/rollback sạch; test pass; `composer ci` pass.

## FE0 — Khởi tạo frontend Next.js (agent `nextjs-dev`, ~2 ngày) **[SEC]** — **ĐÃ XONG** (Next.js 16.3.6; xem `frontend/README.md`). Việc cần làm lại sau quyết định 2026-09-25: không có — FE0 đã dùng nonce + `force-dynamic` đúng ADR-004 §2.7

**Phiên bản:**
- Node.js **22 LTS** (`.nvmrc`), pnpm **9** (`packageManager` trong `package.json`).
- **Next.js 16.x** (App Router) — **đã cài 16.3.6** ở FE0; luôn giữ bản vá mới nhất của nhánh 16.x. React 19.2, TypeScript 5.9 `strict` (không lên TS 7 vì `typescript-eslint` chưa hỗ trợ).
- Tailwind CSS 4.3, ESLint 9 (flat config, `eslint-config-next` 16), Prettier 3, Vitest 3 + Testing Library, Playwright.
- Phiên bản thực tế và lý do chọn: `frontend/README.md`.

**Cấu trúc `frontend/`:**
```
frontend/
  package.json            ("private": true, scripts: dev, build, lint, typecheck, test, e2e — chạy -r)
  pnpm-workspace.yaml     (apps/*, packages/*)
  .nvmrc  .npmrc  .gitignore
  apps/web/               Next.js — học sinh; cổng 3000; host local api.localhost:3000
  apps/admin/             Next.js — quản trị; cổng 3001; host local admin-api.localhost:3001
  packages/api-client/    TS thuần: publicFetch, authFetch, getCsrfToken (cache trong bộ nhớ), deviceId (UUID v4 lưu localStorage),
                          xử lý lỗi theo `code` (api-contract §1.7): SESSION_REPLACED → sự kiện 'forced-logout';
                          SESSION_EXPIRED/REVOKED/UNAUTHENTICATED/STAFF_IDLE_TIMEOUT → 'login-required'; 419 → lấy lại CSRF và thử lại 1 lần;
                          lỗi mạng không bị coi là mất phiên. Kiểu dữ liệu TypeScript cho envelope lỗi/phân trang
  packages/ui/            Component Tailwind theo docs/design/design-system.md: Button, Alert, Modal, StatusPill, EmptyState, Table,
                          Toast, Spinner, ForcedLogoutOverlay (US-014 §2.1)
  packages/config/        tsconfig.base.json, eslint (cấm `dangerouslySetInnerHTML` trừ file được allowlist), prettier
```

**Package cài ở FE0 (chờ duyệt G2):**
- Nền: `next`, `react`, `react-dom`, `typescript`, `tailwindcss`, `@tailwindcss/postcss`, `zod`.
- Dev: `eslint`, `prettier`, `vitest`, `@testing-library/react`, `@playwright/test`.

Cài ở task sau:
- `react-hook-form` + `@hookform/resolvers` (form);
- `isomorphic-dompurify` (mô tả khóa);
- `katex` (quiz);
- `hls.js` (web);
- `tus-js-client` + `@dnd-kit/core` + `@dnd-kit/sortable` (admin);
- widget Turnstile (script chính thức, không cần package).

**Biến môi trường** (validate bằng zod lúc build, file `src/env.ts`; **không secret trong `NEXT_PUBLIC_*`**):

| App | Biến |
|---|---|
| web | `NEXT_PUBLIC_API_URL=http://api.localhost:8000`, `NEXT_PUBLIC_SITE_URL=http://api.localhost:3000`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_HOSTS`, `NEXT_PUBLIC_TURNSTILE_SITE_KEY`, `NEXT_PUBLIC_MOMO_HOSTS=test-payment.momo.vn` (prod: `payment.momo.vn`); server-only: `API_INTERNAL_URL` (SSR trang công khai) |
| admin | `NEXT_PUBLIC_ADMIN_API_URL=http://admin-api.localhost:8000`, `NEXT_PUBLIC_ADMIN_URL=http://admin-api.localhost:3001`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_UPLOAD_URL=http://video.localhost:8000` |

**Nền bảo mật bắt buộc (ADR-004 §2.5–2.6):**
- `proxy.ts` (tên mới của `middleware.ts` trong Next.js 16) mỗi app sinh nonce + CSP cho **mọi route HTML** (ADR-004 §2.7), web/admin khác nhau ở `frame-src`; HSTS (prod), `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'`.
- `next.config.ts`: `poweredByHeader: false`, `images.remotePatterns` chỉ `STATIC_URL`.
- Helper `safeRedirect(next)` (chỉ đường dẫn tương đối `/`, không `//`), `isAllowedPayUrl(url)`, `jsonLd(obj)` (escape `<`).
- Quy ước: mọi trang HTML render động (do CSP nonce — ADR-004 §2.7, không dùng ISR/PPR/`cacheComponents`); trang cần đăng nhập đặt `export const dynamic = 'force-dynamic'` + dùng `authFetch` (`cache: 'no-store'`); chỉ `publicFetch` được dùng Data Cache (`next: { revalidate, tags }`). Lint rule/README ghi rõ.
- Trang mẫu:
  - web `/` gọi `publicFetch('/api/v1/config/public')` và hiển thị lớp 6–12;
  - admin `/dang-nhap` gọi `GET /api/v1/csrf-token` trên admin-api và hiển thị form (chưa submit);
  - `ForcedLogoutOverlay` gắn ở layout web.

**Định nghĩa "xong" FE0:**
- `pnpm install && pnpm -r lint typecheck test build` pass.
- `pnpm dev` chạy cả 2 app (3000/3001) với backend T01.
- Playwright smoke: web `/` hiển thị lớp 6–12 lấy từ API; response có header CSP chứa nonce; admin `/dang-nhap` lấy được CSRF token (CORS đúng origin); gọi admin-api từ origin web bị chặn.
- Unit test `api-client` cho ánh xạ mã lỗi, retry 419 và `safeRedirect`.
- `pnpm audit --prod` không có mức high.
- `frontend/README.md` hướng dẫn chạy.

---

## Giai đoạn 1 — Xác thực & phiên (US-001, US-014, US-015)
- [ ] **T03 Đăng ký/đăng nhập học sinh** (~2 ngày) **[SEC]** — phụ thuộc T02
  - `RegistrationService`, `LoginService`, `PhoneNumber`, bảng `consents` + `ConsentService` (đồng ý của chính HS; checkbox không tick sẵn — S7).
  - `CaptchaVerifier` (Turnstile/fake) ở đăng ký.
  - Quy tắc tuổi theo `privacy.parent_consent_age`.
  - Chỉ dùng `validated()` (S17); bắt lỗi unique race → 422 đúng field.
  - Throttle 2 lớp (S10).
  - `ACCOUNT_LOCKED` chỉ khi mật khẩu đúng (S20).
  - Host api chỉ nhận `hoc_sinh` (`WRONG_PORTAL`).
  - Test: X-Forwarded-For giả; 11 IP cùng 1 tài khoản → 429; `role=admin` trong body bị bỏ qua; thiếu đồng ý → 422.
- [ ] **T04 OTP** (~1,5 ngày) **[SEC]** — phụ thuộc T03
  - `otp_codes`, `OtpService` (sinh bằng `random_int`, tăng `attempts` nguyên tử trước khi so, consume bằng UPDATE có điều kiện).
  - Trần 5/phút, 20/ngày verify; ≤ 10 mã/ngày.
  - Kênh theo `auth.otp.channels` (production: chỉ email, `sms` → 422).
  - `LogSmsOtpSender` chỉ bind ở local/testing và ghi `***`.
  - `OtpMail` ShouldBeEncrypted (S21).
  - `PUT /auth/contact` huỷ mã cũ + reset verified (S9).
  - Middleware `account.verified`.
  - Test: 20 verify song song (`Http::pool` vào server test hoặc nhiều process) → tối đa 5 lần được so; grep log không có mã 6 số.
- [x] **T05 Một phiên học sinh** (dev xong 2026-10-05, chờ Reviewer/QA) (~1 ngày) **[SEC]** — phụ thuộc T03
  - Theo ADR-003 mới: bind + destroy session cũ + tombstone; logout đặt `logged_out`; không có nhánh "nhận nuôi".
  - Renderer `AuthenticationException` đọc tombstone → `SESSION_REPLACED`/`SESSION_EXPIRED`/`SESSION_REVOKED`.
  - Validate `X-Device-Id`.
  - Test đủ 6 kịch bản ở ADR-003 (gồm A → B → B đăng xuất → A phải 401).
- [x] **T27 Quên/đổi mật khẩu** (dev xong 2026-10-05, chờ Reviewer/QA) (US-015; backend làm theo api-contract §2.2) (~1,5 ngày) **[SEC]** — phụ thuộc T04, T05
  - OTP `reset_password` qua email, captcha.
  - Phản hồi luôn giống nhau dù tài khoản có tồn tại hay không.
  - Huỷ mọi phiên (tombstone `password_changed`); `password_changed_at`.
  - Test HTTP thật (từ T05 review R5): A và B đăng nhập, đổi/đặt lại mật khẩu qua endpoint → phiên khác nhận `SESSION_REVOKED`; đang đăng nhập mà đổi mật khẩu thì `StudentSessionService::revoke()` rồi bind lại phiên hiện tại (regenerate + bind) để người đổi không bị văng (không chỉ gọi `revoke()` trực tiếp như test T05).
- [x] **T28 Đăng nhập quản trị** (~2 ngày) **[SEC]** — phụ thuộc T04; **phải xong trước FE-ADMIN FA1**
  - Host admin-api: login (chỉ staff/GV), `EnsureAdminOrigin`, MFA email cho admin/QLT (`staff.mfa_passed`, flag `FEATURE_STAFF_MFA`).
  - Email cảnh báo thiết bị mới cho GV.
  - `staff.idle` (120 phút / 12 giờ), `staff.password_fresh` (`must_change_password`), `PUT /admin/auth/password`.
  - Sanctum `AuthenticateSession` cho host admin-api.
  - Audit `staff.login*` (S15, S6).
  - Test: gọi admin-api với Origin web → 403; HS đăng nhập admin → `WRONG_PORTAL`; idle quá hạn → 401 `STAFF_IDLE_TIMEOUT`; đổi mật khẩu đăng xuất phiên khác.

## Giai đoạn 2 — Nội dung & danh mục (US-011, US-009, US-002, US-003)
*Song song Giai đoạn 1 sau T02.*
- [ ] **T06 Chuyên đề** CRUD + ẩn/hiện + chặn xoá khi đang gán (~0,5 ngày). Tên là văn bản thuần; audit tạo/xoá.
- [ ] **T07 Schema nội dung + ghi danh** (~1 ngày) **[DBA]**
  - Bảng: `courses` (CHECK grade), `course_subject`, `course_teacher`, `chapters`, `video_assets` (có `lesson_id`, `declared_size_bytes`), `lessons` (`external_provider`/`external_video_id`), `enrollments` (generated `live_flag`), `lesson_progress`.
  - Constraint đặt tên `chk_*`.
  - Chạy checklist DBA §5 mục 1–3.
- [ ] **T08 Quản trị khóa học** (~2,5 ngày) **[SEC]** — phụ thuộc T07, T28 (route admin)
  - `CourseService`, `CourseTeacherService`, publish/unpublish, xoá.
  - `UpdateCourseRequest` 2 bộ rule staff/GV; GV gửi `teacher_ids`/`status`/`manual_order` bị bỏ qua (S5, S17).
  - **`ImageUploadService`** (mimes/mimetypes, không SVG, mã hoá lại WebP, bỏ EXIF, tên UUID, disk `uploads`, `STATIC_URL`) — S2.
  - **`HtmlSanitizer`** profile Purifier (api-contract §4), sanitize khi ghi và khi đọc — S8.
  - Audit publish/unpublish/xoá/đổi giá/gán GV.
  - Test: SVG/HTML/polyglot → 422; EXIF bị xoá; XSS payload trong description bị lọc; GV khóa A sửa khóa B → 403.
- [ ] **T09 Chương/bài** (~2 ngày) **[SEC]** — phụ thuộc T08
  - `scopeBindings` cho toàn bộ route lồng nhau.
  - Curriculum order: tập ID phải trùng khớp đúng với tập của khóa (S5).
  - `LessonRequest` không nhận `video_asset_id`/`course_id`.
  - Link ngoài: chỉ khi `is_preview`, parse ID bằng regex (S13).
  - Test IDOR cho từng route (chapter/lesson khóa khác → 403/404; payload chứa lesson khóa khác → 422).
- [ ] **T10 Danh mục công khai + chi tiết** (~1,5 ngày) — phụ thuộc T07
  - `show` tách `viewer-state` (để cache được — S16); outline không có URL/ID video (S13).
  - Escape LIKE (S24); `Cache-Control: public, max-age=60`.

## Giai đoạn 3 — Video (US-006, US-009 BR10) — ADR-002
- [ ] **T11 Contract video** (~1,5 ngày) **[SEC]** — phụ thuộc T09
  - `VideoProvider`, `VideoProviderManager`, `FakeVideoProvider` (chỉ local/testing).
  - Phải dọn `video_asset` mồ côi: T09 gỡ `lessons.video_asset_id` khi bài đổi khỏi `upload` (kể cả asset đang `processing`) — job/lệnh dọn asset không còn bài trỏ tới (review T09 m3).
  - Tạo phiên upload (kiểm size ≤ 1 GB (PO 2026-10-07, trước đây 2 GB), hạn mức 20 GB/ngày); webhook `whereIn(provider)` + pull-verify; `videos:check-stuck`.
  - _Dev 2026-10-05: xong phần contract: `VideoProvider` + DTO, `VideoProviderManager` (allowlist `video.enabled_providers`), `FakeVideoProvider` (local/testing, boot guard cấm production), `VideoUploadService`, `VideoAssetSyncService`, `VideoWebhookService`, `OrphanVideoPruner`, `SyncVideoAssetStatusJob`, lệnh `videos:check-stuck` (15 phút) và `videos:prune-orphans` (hourly), route admin upload/trạng thái + webhook. 29 test ở tests/Feature/T11. CHƯA có `InternalVideoProvider` (T12) và `BunnyStreamProvider` (chờ tài khoản): chọn chúng → 503 `VIDEO_PROVIDER_UNAVAILABLE`. Playback (`LessonAccessService`, `/learn/.../playback`, endpoint admin playback) thuộc T13; `FakeVideoProvider::playback` đã sẵn để T13 dùng._
- [ ] **T12 Module VideoLab** (~4 ngày) **[SEC]** — song song T13 sau T11
  - Toàn bộ ADR-002 §3a: magic bytes; ffprobe/ffmpeg với `-protocol_whitelist file` + `-format_whitelist` qua `Process` mảng tham số.
  - **Container `infra/worker-video`** (non-root, không mount `.env`, network `internal`, giới hạn CPU/RAM).
  - Ràng buộc `{guid}`/`{path}` + kiểm `realpath`; thư mục source ≠ hls.
  - TUS: `max_bytes`, 413, 1 upload/guid, TTL ≤ 6h, HMAC.
  - CORS riêng, `library/*` chỉ nội bộ, `X-Accel-Redirect` + `internal`.
  - Test đủ 5 case ở §3a.7.
  - _Dev 2026-10-06: xong — module `app/VideoLab` (bảng `vl_videos`, TUS Creation+Core, API quản lý AccessKey, CDN HLS có token/IP, `TranscodeVideoJob` queue `video` qua connection `redis_video` retry_after 3900, `SendVideoLabWebhookJob`, `videolab:cleanup`), adapter `InternalVideoProvider`, `infra/worker-video` (+ network `internal`), Nginx host `video.localhost`. Tests ở tests/Feature/T12 (đủ 5 case §3a.7). Việc phải làm khác: FE đặt `chunkSize` tus-js-client ≤ 8 MB; `.env` thêm khoá `VIDEOLAB_*` (xem `.env.example`; local tự suy ra từ APP_KEY). Chờ Reviewer + Security._
  - _Sửa lỗi nhỏ 3 (2026-10-06), ghi chú cho FE: upload video sai định dạng (TUS PATCH) trả 422 với `code: 'VIDEO_INVALID'` (trước đây `HTTP_ERROR`), message tiếng Việt dùng được trực tiếp; `POST /videolab/tus` 201 có `Content-Type: text/plain`. Giáo viên bị đổi sang vai trò khác không còn gán khóa nào (T33-4)._
- [ ] **T13 Học & tiến độ** (~2 ngày) **[DBA] [SEC]** — phụ thuộc T11, T05
  - `LessonAccessService`, playback (TTL 15 phút, ràng IP bài không preview, throttle 30/phút/user, log `playback`, cảnh báo bất thường).
  - Heartbeat dùng `lockForUpdate` (DBA 2.5) + kẹp delta; 90%.
  - Preview chỉ bài chưa xoá của khóa published.
  - _Dev 2026-10-05: xong. `LessonAccessService`, `PlaybackService`/`PlaybackAuditor`, `ProgressService`, `CourseProgressService` (cho T23: `percentsFor`, `completedLessonIds`), `LearningOutlineService`, `LessonPolicy`, `CoursePolicy@learn`, 4 controller Learn, `config/learning.php`, route learn/preview/admin playback. Không có migration (schema T07 đủ). Test tests/Feature/T13 (kể cả race 8 heartbeat đồng thời và heartbeat ↔ xoá bài). Chưa làm (thuộc T23): `/me/courses`, `/me/courses/{course}/progress`._

## Giai đoạn 4 — Ghi danh miễn phí (US-012)
- [ ] **T14 EnrollmentService** (~1,5 ngày) **[SEC]** — phụ thuộc T07, T04
  - requestFree/approve/reject/revoke; Policy duyệt kiểm theo `$enrollment->course`.
  - Danh sách chờ duyệt che email/SĐT.
  - `counters:recount` (gồm `courses.enrollments_count`; phần coupon thêm ở T15).

## Giai đoạn 5 — Thương mại (US-013, US-004, US-005) — ADR-001
- [ ] **T15 Mã giảm giá quản trị** (~1,5 ngày) **[DBA]** — phụ thuộc T07, T06
  - CHECK `chk_coupons_percent_range`, `chk_coupons_full_discount_limited` (DBA #5, S18).
  - Rule app: mã fixed ≥ giá khóa rẻ nhất phải có `max_uses` + `valid_until`.
  - `course_ids`/`subject_ids` exists; audit.
  - `counters:recount` thêm `coupons.used_count` (DBA #5).
  - _Dev 2026-10-05: xong (migration `coupons`/`coupon_course`/`coupon_subject`, CRUD + activate/deactivate trên admin-api, `counters:recount` có `coupons.used_count`), 32 test ở tests/Feature/T15, chờ Reviewer. `coupon_usages`/`orders` do T18 tạo: `CouponService::isUsed` và recount tự tính khi bảng xuất hiện. `PricingCalculator`/`CouponEvaluator` thuộc T16 (T15 chỉ cấp model + `Coupon::state()`, `normalizeCode()`)._
- [ ] **T16 Giỏ hàng** (~1,5 ngày) — phụ thuộc T15, T14
  - `PricingCalculator`, `CouponEvaluator`.
  - Mã không tồn tại/chưa bắt đầu/vô hiệu → cùng `COUPON_INVALID`; limiter 30 lần sai/ngày (S18).
  - _Từ review T15:_ (a) [chốt M1 review T16, theo US-013 BR6] mã `fixed` lớn hơn phần giá áp dụng VẪN dùng được (giảm = min); riêng khi mã fixed đưa tổng về 0đ thì chỉ cho khi mã có cả `max_uses` và `valid_until`, nếu không → `COUPON_NOT_APPLICABLE`; (b) `is_restricted=true` mà pivot rỗng (sau cascade xoá chuyên đề/khóa) → `COUPON_NOT_APPLICABLE`, KHÔNG coi là áp toàn bộ; (c) so sánh thời gian phải bind Carbon theo `config('app.timezone')` (dùng `now()`; local/production là `Asia/Ho_Chi_Minh`, cột DATETIME lưu giờ theo múi giờ app), không dùng `NOW()` của MySQL và không truyền Carbon UTC vào `Coupon::scopeInState`/`state()` (QA T15).
  - _Dev 2026-10-05: xong, chờ Reviewer. Migration `carts`/`cart_items`; `App\Services\Cart\{CartService, PricingCalculator, CouponEvaluator}` + DTO `Data/`; 5 route giỏ + `cart_count` ở /auth/me + `in_cart` ở viewer-state; limiter `coupon` bỏ perDay (30 lần SAI/ngày do `CartService` đếm). Interface cho T18: `CartService::lockCart($user)` → `snapshot($cart, $user)` (items hợp lệ, `unavailableItems` = removed_items, mã còn hiệu lực, `pricing`); `PricingCalculator::calculate($prices, $coupon, $eligibleIds)` (có `lines[]` để ghi `order_items`); `CouponEvaluator::evaluate($coupon, $user, $prices)`. Test ở tests/Feature/T16 (có race 8 tiến trình)._
- [ ] **T17 Thanh toán: abstraction + MoMo** (~2 ngày) **[SEC]** — song song T15/T16
  - `enabled_gateways` + boot guard + Fake chỉ local/testing (S4).
  - `MoMoSigner` (unit test vector mẫu).
  - `accessKey` từ config; kiểm partnerCode/requestId/orderId; parse amount nghiêm ngặt; bảng mã (chỉ `0` là thành công) (S12).
  - `orderInfo` không PII; TLS verify; log không secret/PII.
  - `queryStatus` verify chữ ký phản hồi.
  - Kiểm chứng sandbox.
  - _Dev 2026-10-05: code + 63 test xong (tests/Feature/T17), chờ Reviewer; sandbox MoMo + vector chính thức chưa kiểm (xem backlog-v2 T17-1). `queryStatus` nhận DTO `PaymentStatusQuery` thay vì model `PaymentAttempt` (model thuộc T18)._
- [ ] **T18 Checkout** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T16, T17
  - _Từ review T15:_ thêm test tích hợp `CouponService::isUsed()` (COUPON_LOCKED/COUPON_IN_USE) với bảng `coupon_usages`/`orders` thật (T15 chỉ test bằng `used_count`).
  - _Từ review T16:_ khi tạo bảng `coupon_usages` thì bỏ nhánh `Schema::hasTable('coupon_usages')` trong `CouponEvaluator::alreadyUsedBy` (và thêm test tích hợp COUPON_ALREADY_USED với bảng thật).
  - Bảng: `orders` (+ `coupon_hold_until`), `order_items`, `order_status_logs`, `payment_attempts`.
  - **Migration thêm FK `enrollments.order_id` → `orders.id` (restrictOnDelete)** (T07 chỉ tạo cột + index vì `orders` chưa tồn tại); `down()` `dropForeign`; kèm test kiểm FK.
  - `CheckoutService` (khoá `carts → orders → coupons`, sức chứa theo `coupon_hold_until`), middleware `parent.consent` (tạm cho qua nếu `parent_consent_status` ∈ {not_required, granted}; luồng đầy đủ ở T29).
  - Test: race 2 tab; N HS cùng mã cuối (DBA checklist §5.6).
  - **Khi có bảng `order_items`/`orders`: sửa `CourseService::delete` (T08)** kiểm thêm khóa còn nằm trong đơn đang chờ thanh toán (`pending`, chưa hết hạn) → 409 `COURSE_HAS_ENROLLMENTS` (hoặc mã riêng); xoá mềm khóa đang có đơn chờ làm IPN về sau không cấp được quyền học. Kèm test. (Review T08 R1.)
  - Khi thêm FK `enrollments.order_id`: sửa `tests/Feature/T14/ConcurrentEnrollmentTest.php` (worker `grant` dùng `order_id=42` không có FK; test nhóm `race`).
  - _Dev 2026-10-06: code xong, chờ Reviewer. Migration `2026_10_14_100000_create_orders_tables` (orders, order_items, order_status_logs, payment_attempts, coupon_usages) + `2026_10_14_110000_add_enrollments_order_foreign_key`. `App\Services\Orders\{CheckoutService, OrderFulfillmentService, OrderStateMachine, CouponCapacity, OrderCodeGenerator, CheckoutChangedException}`; route `GET /checkout/preview`, `POST /checkout`; middleware `parent.consent`; config `orders.php`, `payments.coupon_hold_minutes`. **Thứ tự khoá thực tế: `carts → orders → courses → enrollments → coupons`** (courses TRƯỚC coupons vì `grantPurchase` khoá courses trước enrollments; checkout khoá `courses` SHARE tăng dần id rồi mới `coupons` — T19 phải theo đúng, nếu không deadlock checkout↔IPN). **Đơn 0đ:** sau khi tạo đơn pending, `OrderFulfillmentService::markPaid($order, 'checkout')` (transaction riêng) — T19 dùng lại đúng hàm này cho IPN/query (`'ipn'|'query'`, truyền `$paymentReference`), cần bổ sung: attempt `succeeded`, so số tiền, email `OrderPaid` afterCommit. `CourseService::delete` → 409 `COURSE_HAS_PENDING_ORDERS`. `CouponService::isUsed`/`CouponEvaluator::alreadyUsedBy` bỏ nhánh `Schema::hasTable`. Test ở tests/Feature/T18 (có race), sửa T14 ConcurrentEnrollmentTest + T15 test recount dùng bảng thật._
- [ ] **T19 IPN & fulfillment** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T18, T14
  - _**V2 (PO 2026-10-06):** chuyển V2, chờ kết nối MoMo; khi làm V2 bật `FEATURE_PAID_CHECKOUT=true`._
  - `payment_webhook_events` (IX `received_at`, payload chỉ trường đã biết, `source`).
  - `PaymentWebhookService::apply`, `markPaid` theo thứ tự khoá chuẩn (DBA #2).
  - Rà lại `CourseService::delete` (T08) đã chặn khóa có đơn chờ thanh toán (xem T18).
  - Gọi `EnrollmentService::grantPurchase` (T14): so `order_id` của dòng trả về với đơn đang xử lý (không dựa vào `wasRecentlyCreated`); retry deadlock của grantPurchase chỉ có tác dụng ở transaction ngoài cùng nên `markPaid` phải tự bọc retry deadlock ở mức ngoài.
  - `grantPurchase` ném `DomainException` `COURSE_UNAVAILABLE` (409) khi khóa đã xoá mềm (khóa chỉ unpublished vẫn cấp quyền): `markPaid` phải bắt mã này để chuyển đơn sang `needs_review`/hoàn tiền, không nuốt lỗi và không để IPN trả 5xx lặp.
  - Giới hạn body 16 KB + 120/phút/IP.
  - `needs_review` khi `used_count > max_uses`.
  - `OrderPaidMail` ShouldBeEncrypted.
  - Test: bảng mã, `amount` lạ, body 1 MB → 413, IPN trùng.
  - _Từ review T18:_ (R3) test "tiền về link của đơn đã `superseded`" (`cancelled → paid` + `needs_review`); (R6) rà `PaymentInitResult::rawResponse` của T17 (đã lưu vào `payment_attempts.create_response`) không chứa chữ ký/PII, mask và đặt thời hạn lưu `create_response`.
- [ ] **T20 Đối soát + huỷ 12h + `/pay` + đơn của tôi** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T19
  - _**V2 (PO 2026-10-06):** chuyển V2, chờ kết nối MoMo; khi làm V2 bật `FEATURE_PAID_CHECKOUT=true`._
  - Như bản trước, thêm: `/pay` khoá `carts → orders`, vi phạm unique → trả attempt hiện có; kiểm lại sức chứa mã khi quá `coupon_hold_until` (S12.5, S18).
  - _Từ review T18:_ (R3) khi đơn bị `superseded`, attempt còn `pending` vẫn phải được job đối soát query tới hết `expires_at` của link (kể cả đơn đã `cancelled`); (R4) job huỷ 12h phải huỷ được đơn 0đ treo (`payment_method=none`, không có attempt).
  - Test: 2 request `/pay` song song → 1 attempt mới.

## Giai đoạn 6 — Quiz & tiến độ (US-007, US-008)
- [ ] **T21 Soạn quiz** (~1,5 ngày) **[SEC]** — phụ thuộc T09
  - scopeBindings; `course_id` suy ra; nội dung văn bản thuần ≤ 5.000; ≤ 200 câu; copy-on-write.
- [x] **T22 Làm quiz** (~2 ngày) **[DBA]** — phụ thuộc T21, T13 (dev xong 2026-10-06, chờ review/QA)
  - **Việc T22 phải làm từ T21:** gỡ nhánh bắt SQLSTATE 42S02 trong `QuizContentService::hasAttempts` khi tạo bảng `quiz_attempts`; `question_ids` là mảng số nguyên; index `quiz_attempts(quiz_id)`; `lockForUpdate`/`sharedLock` dòng `quizzes` trước khi chốt `question_ids`.
  - CHECK `chk_quiz_attempts_question_count` (DBA #6); `JSON_SET` với `question_id` ép int.
  - Resource lượt đang làm không có `is_correct`/`explanation` (test khẳng định — I3).
- [x] **T23 Khóa học của tôi + tiến độ** (~1 ngày) — phụ thuộc T13, T22. Ghi thời gian xử lý vào log để theo dõi mốc p95 300 ms (DBA #10). (dev xong 2026-10-06, chờ review/QA)
  - _Dev 2026-10-06: `MyCoursesService` (batch: `percentsFor`, đếm bài, bài học tiếp theo luật AC6, điểm quiz cao nhất), `Learn\MyCourseController`, `MyCoursesRequest`, limiter `me-courses` 60/phút, log channel `learning` (`duration_ms`, warning khi > `learning.my_courses.slow_ms` = 300). Không migration. Test tests/Feature/T23 (có kiểm không N+1)._

## Giai đoạn 7 — Quản lý đơn (US-010)
- [ ] **T24 Admin đơn hàng** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T19, T28
  - _**V2 (PO 2026-10-06):** chuyển V2, chờ kết nối MoMo; khi làm V2 bật `FEATURE_PAID_CHECKOUT=true`._
  - `OrderFilterQuery` (bắt buộc khoảng ngày ≤ 366, áp ngày/trạng thái trước; `q` theo 4 dạng, escape LIKE).
  - **`cursorPaginate`** + `COUNT` riêng (DBA #9).
  - `PiiMasker` che email/SĐT ở danh sách; chi tiết ghi audit `order.view_pii`; `RefundService` + audit.
  - `EXPLAIN ANALYZE` với ≥ 100k đơn seed (DBA checklist §5.4).
- [ ] **T25 Xuất CSV/XLSX** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T24
  - _**V2 (PO 2026-10-06):** chuyển V2, chờ kết nối MoMo; khi làm V2 bật `FEATURE_PAID_CHECKOUT=true`._
  - `include_contact` chỉ admin + lý do; không bao giờ xuất thông tin phụ huynh.
  - `CsvCell` chống injection (`= + - @ \t \r`), XLSX kiểu string.
  - Giới hạn 10 lần/ngày; audit tạo/tải; tên file cố định; signed URL 10 phút đúng host admin-api.
  - `exports:purge` + file mồ côi 48h.
  - (T26 review M4) Queue `exports` cần connection Redis riêng với `retry_after` > timeout job và `--timeout` worker riêng; ngưỡng `ops.health.worker_max_age` riêng cho worker exports (tránh báo động giả "worker chết"/phát lại job).

## Giai đoạn 8 — Dữ liệu cá nhân, vận hành, phát hành
- [ ] **T29 Đồng ý của phụ huynh** (US-017 — BA viết story; nội dung pháp lý chờ pháp chế) (~2 ngày) **[SEC]** — phụ thuộc T03, T04
  - `ParentConsentService`, `ParentConsentMail` (link ký 72h, dùng 1 lần), endpoint `/parent-consents/{token}`.
  - Middleware `parent.consent` đầy đủ; rút lại đồng ý; audit.
- [ ] **T30 Job dọn dữ liệu & bộ đếm** (~1 ngày) **[DBA]** — phụ thuộc T19, T04, T25 (DBA #3)
  - _Dev 2026-10-06 (phần không liên quan thanh toán): xong `audit:purge` (giữ 24 tháng — PO uỷ quyền chọn, `OPS_AUDIT_RETENTION_MONTHS`; lô 1.000, `--dry-run`) và `users:purge-unverified` (HS chưa xác thực > 7 ngày, loại trừ có đơn/ghi danh/tiến độ/quiz/coupon_usages; xoá kèm `consents`). Lịch 03:40/03:50 ở OperationsServiceProvider. `otp:prune`, `queue:prune-failed`, `counters:recount` đã có lịch (T26). `payments:purge-webhook-events` để V2._
  - `payments:purge-webhook-events` (> 24 tháng, lô 5.000, nghỉ 200ms).
  - `audit:purge`, `otp:prune`, `users:purge-unverified` (> 7 ngày, chỉ tài khoản không có đơn/enrollment).
  - `queue:prune-failed --hours=168`; lịch trong `routes/console.php`.
  - Lên lịch `counters:recount` (T14, hiện chỉ chạy tay; thêm `coupons.used_count` ở T15).
- [ ] **T34 Quyền dữ liệu cá nhân** (US-018 — BA viết story) (~2 ngày) **[SEC]** — phụ thuộc T29
  - `/me/data-export`, xoá tài khoản bằng OTP → `AccountAnonymizer` (giữ đơn hàng), audit.
- [ ] **T33 Quản lý tài khoản staff** (US-016 — BA viết story) (~1,5 ngày) **[SEC]** — phụ thuộc T28
  - API admin: tạo/khoá/mở khoá/đặt lại mật khẩu staff (`manage-system` chỉ admin); xem audit log (chỉ đọc).
  - _Dev 2026-10-05: xong (không migration). `StaffAccountService` dùng chung với `staff:create|lock|unlock`; `StaffSessionRevoker` (phiên bản huỷ phiên trong cache, kiểm ở `staff.idle`); API `/admin/staff*` + `/admin/audit-logs` ở api-contract §2.5; 17 test ở tests/Feature/T33, chờ Reviewer._
- [ ] **T26 Vận hành queue/scheduler** (~1 ngày)
  - Lưu ý `counters:recount` (T14) chưa được lên lịch.
  - Đăng ký toàn bộ lịch (README §4) với `withoutOverlapping()->onOneServer()`; Supervisor mẫu; cảnh báo `failed_jobs`; tài liệu tunnel IPN.
- [ ] **T31 Checklist production & DNS** (~1 ngày) **[SEC]**
  - _Dev 2026-10-06: xong phần tài liệu, mẫu và guard (chưa tick: còn chờ hạ tầng/PO điền giá trị thật và security review trên staging). Checklist: `docs/ops/production-checklist.md`; mẫu Nginx 4 host + VideoLab + tên miền tĩnh, Supervisor (queue, worker-video, scheduler), `grants.sql`, `.env.production.example`, `.env.worker-video.example` ở `infra/production/` (chỉ là mẫu, không dùng local). `ProductionConfigGuard` nay chạy ở `production` VÀ `staging` (trừ allowlist endpoint/pay_url MoMo, chỉ production); thêm chặn `AUTH_OTP_E2E_RELAXED=true`, khoá `VIDEOLAB_*` thiếu/< 32 ký tự (khi VideoLab bật), `FEATURE_PAID_CHECKOUT=true` mà không có cổng, `MOMO_PAY_URL_HOSTS` khác `payment.momo.vn`. Test: `tests/Feature/T31/StagingGuardTest.php`. Chưa thêm vào guard (kiểm tay trong checklist): `TURNSTILE_SECRET` rỗng, mailer `log/array`, Redis không mật khẩu, `APP_URL` không https — vì baseline các test guard cũ (T01, T04, T11) không có các giá trị này. Còn lại cho PO/hạ tầng: tên miền, Turnstile key, SMTP, IP LB/app server, pháp chế (mục 13-14 checklist)._
  - Chính sách lưu giữ log: kênh `playback` (IP + UA, T13) giữ 90 ngày (`LOG_DAILY_DAYS`), ghi trong chính sách lưu log có IP.
  - ADR-004 §6 (S22): Nginx 4 host + tên miền tĩnh; header; Redis; secret; staging domain riêng; rà DNS chống subdomain takeover (S6).
  - Kiểm `php artisan about` staging = production.
  - Staging (`APP_ENV=staging`) **không được** dùng `CAPTCHA_DRIVER=fake` (ProductionConfigGuard chỉ chặn ở `production`): mở rộng guard hoặc kiểm tay; mặc định config đã là `turnstile` (R3, review T03).
  - VideoLab (review T12 R7): allow-list Nginx `/videolab/library/` bằng IP app server cụ thể + `real_ip` sau LB (local allow cả dải private); `TRUSTED_PROXIES` đúng (token CDN ràng IP, `Location` TUS phụ thuộc scheme); `worker-video` production = image build sẵn, `APP_ENV`/`APP_KEY`/`REDIS_PREFIX` riêng, DB user riêng chỉ quyền `vl_videos`; bật `VIDEOLAB_ACCEL_REDIRECT` + `limit_req` cho `/videolab/cdn/`; khoá `VIDEOLAB_*` ≥ 32 ký tự ở mọi môi trường ngoài local/testing.
- [ ] **Sửa lỗi nhỏ 4 — SSR catalog: limiter nội bộ + Host của listener 8081** (~0,5 ngày, backend + mẫu Nginx) — phụ thuộc T26, T31. Quyết định: ADR-004 §2.8. Làm cùng đợt với phần bổ sung FW2 (frontend), phát hành trước hoặc cùng FW2.
  - `App\Support\CatalogThrottle::limits`: token đúng + `X-Client-IP` hợp lệ → giữ nguyên (120/phút theo `ssr-client:<ip>` + `ssr-total`). Token đúng + thiếu `X-Client-IP` hoặc giá trị không phải IP → **chỉ** `ssr-total` (bỏ nhánh rơi về `$request->ip()`). Không token/token sai → giữ nguyên. Sửa docblock + chú thích `config/internal.php` (`catalog_ssr_total_per_minute` nay là trần duy nhất của request SSR không kèm IP).
  - Test Pest (tests/Feature/T26 hoặc thư mục mới): (a) token đúng, không `X-Client-IP`: request thứ 121 trong phút vẫn 200, chạm `CATALOG_SSR_TOTAL_PER_MINUTE` (đặt trong test, vd 130) → 429 envelope `TOO_MANY_ATTEMPTS`; (b) token đúng + `X-Client-IP: abc` → giống (a); (c) token đúng + 2 IP khác nhau → mỗi IP 120 riêng, IP thứ 2 không bị IP thứ 1 làm 429; (d) token sai + `X-Client-IP` giả → vẫn theo IP kết nối (120); (e) token đúng không IP và có IP cùng ăn chung `ssr-total`.
  - Mẫu `infra/production/nginx/conf.d/vitaminvui.conf`:
    - listener `:8081`: thêm `fastcgi_param HTTP_HOST api.vitaminvui.vn;` (sau `include fastcgi_params`), `access_log` riêng (vd `/var/log/nginx/vv-internal.access.log`); sửa chú thích dòng "SSR gọi … với `Host: api.vitaminvui.vn`" thành `API_INTERNAL_URL=http://<IP_NOI_BO_NGINX>:8081` (Host do Nginx ép, Node không đặt được Host).
    - host `vitaminvui.vn`: `limit_req_zone $binary_remote_addr zone=vv_web:10m rate=10r/s;`, `location /` thêm `limit_req zone=vv_web burst=200 nodelay; limit_req_status 429;`, thêm `location ^~ /_next/static/` proxy giống `/` nhưng KHÔNG `limit_req`. Ghi chú: giá trị khởi điểm, chỉnh sau đo staging (NAT lớp học).
  - Docs: api-contract §1.6 dòng `catalog` (thêm nhánh "token đúng, không `X-Client-IP` → chỉ trần tổng"); chú thích `CATALOG_SSR_TOTAL_PER_MINUTE` trong `infra/production/.env.production.example` và `backend/.env.example`.
  - **Xong khi:** test (a)–(e) xanh, `composer ci` xanh; `nginx -t` với mẫu đã thay placeholder (QA dựng như QA T31); QA chạy trên Nginx mẫu: từ IP Next gọi `http://<IP>:8081/api/v1/courses` KHÔNG đặt Host → 200; kèm token, không IP, 200 request/phút → không 429; kèm token + cùng `X-Client-IP` → request 121 bị 429; từ IP khác → 403; host web: vượt burst → 429, `/_next/static/*` không bị giới hạn. Review; security tuỳ PO (đổi limiter, không đổi ranh giới tin cậy).

## Frontend Next.js (agent `nextjs-dev`)

| Mã | Nội dung | Phụ thuộc API | Ước lượng |
|---|---|---|---|
| FE0 | Khởi tạo (mục trên) | T01 | 2 |
| FW1 | Đăng ký (checkbox đồng ý, Turnstile, phụ huynh), đăng nhập, OTP, quên/đổi mật khẩu, overlay phiên<br>• **Ghi chú (gom sửa lỗi nhỏ):** lỗi OTP nay là 422 với `code` = `OTP_INVALID` (sai) / `OTP_EXPIRED` (hết hạn/không có mã) thay vì `VALIDATION_ERROR` gộp; `errors.code[]` vẫn còn nên FE cũ không vỡ — FE đổi sang phân biệt theo `code` envelope (hiện thông điệp/nút "Gửi lại mã" theo `OTP_EXPIRED`)<br>• **Ghi chú (Sửa lỗi nhỏ 2, 2026-10-06):** `POST /auth/password/reset` nay trả MỌI lỗi mã (sai, hết 5 lượt, không tồn tại) là 422 `OTP_EXPIRED` cùng thông điệp "hết hạn" (không còn `OTP_INVALID`/429 `TOO_MANY_ATTEMPTS` riêng ở reset; verify tài khoản/đổi liên hệ giữ nguyên) → FE màn đặt lại hiển thị thông điệp hết hạn + nút "Gửi lại mã" cho mọi lỗi mã<br>• **Ghi chú (Bảo mật cụm 1, 2026-10-06):** **BẮT BUỘC phát hành cùng backend Bảo mật cụm 1: `ChangeContactForm.tsx` hiện tại luôn nhận 422 vì chưa gửi `current_password`.** (a) form đổi liên hệ (`PUT /auth/contact`) PHẢI có ô "Mật khẩu hiện tại" gửi kèm `current_password` (thiếu/sai → 422 field `current_password`, 429 khi sai nhiều lần; cả với tài khoản chưa xác thực); sau khi đổi email cookie phiên được xoay nên gọi `/auth/me` lại; phiên ở thiết bị khác nhận 401 `SESSION_REVOKED`. (b) Mật khẩu đăng ký/đặt lại/đổi vẫn min 8 nhưng 422 field `password` nếu thuộc danh sách phổ biến (vd `12345678`, `password123`, `matkhau123`): hiển thị `errors.password[0]`. (c) `forgot` với email chưa xác thực vẫn 202 nhưng không có mã: màn xác nhận nên nói "nếu tài khoản có email đã xác thực" (giữ thông điệp server trả)<br>• Thiếu `Accept: application/json` giờ vẫn nhận envelope JSON; lấy `/csrf-token` với cookie phiên cũ không còn 401 → FE không cần "thử lại một lần" | T03–T05, T27 | 3 |
| FW2 | Danh mục `/khoa-hoc`, `/lop-{grade}`, chi tiết `/khoa-hoc/{slug}`:<br>• **SSR gọi catalog qua đường nội bộ (T31, ADR-004 §2.8):** production `API_INTERNAL_URL=http://<IP_NOI_BO_NGINX>:8081` (Nginx ép Host = host api, sau Sửa lỗi nhỏ 4; Node không đặt được `Host`); MỌI request catalog gửi `X-Internal-Token` (= `INTERNAL_API_TOKEN`, chỉ ở server Next); `X-Client-IP` (IP khách thật) CHỈ gửi cho truy vấn có `q` (header nằm trong khoá Data Cache). Host công khai xoá 2 header này. `INTERNAL_API_REQUIRED=true` chỉ bật sau khi FE đã gửi<br>• **Bổ sung sau ADR-004 §2.8 (~0,5 ngày):** (1) sửa chú thích `env.server.ts`, `apps/web/.env.example`, README (IP trần dùng được ở production nhờ Nginx ép Host; không khuyến nghị `/etc/hosts` trỏ tên miền công khai); mục FW2 README thay câu "Cần Architect xác nhận" bằng tham chiếu §2.8; (2) API trả 429/5xx khi SSR: trang danh mục/chi tiết hiện thông báo "Hệ thống đang bận, vui lòng thử lại" (error boundary của segment), không lỗi 500 trần; test khẳng định response 429 KHÔNG bị lưu vào Data Cache (lần gọi sau khi hết hạn mức trả dữ liệu mới); (3) phân trang không dùng `links`/`meta.path` của API (URL nội bộ `http://api…`); (4) FW8/FW9 dùng cùng quy tắc header<br>• **render động + CSP nonce** (ADR-004 §2.7 — không ISR/PPR), dữ liệu qua `publicFetch` với `revalidate: 60`, `tags: ['catalog']`<br>• `generateMetadata` + JSON-LD có `nonce`<br>• `robots.txt` / `sitemap.xml` là route handler `revalidate = 3600`<br>• `viewer-state` gọi phía client bằng `authFetch`<br>• mô tả khóa qua DOMPurify (thêm allowlist ESLint cho đúng 1 component)<br>• **load test**: p95 TTFB ≤ 500 ms ở 50 req/s khi cache ấm, ≤ 1,2 s khi cache lạnh — ghi kết quả vào `frontend/README.md` | T10 | 3,5 |
| FW3 | Giỏ hàng, checkout, `/checkout/ket-qua` (poll, "Kiểm tra lại", link hết hạn), đơn của tôi; kiểm host `pay_url` | T16–T20 | 3 |
| FW4 | Học video (hls.js, tự lấy lại link khi 403, heartbeat, overlay), iframe link ngoài sandbox<br>• **Ghi chú (US-021/T37):** cùng một trình phát cho VideoLab và Bunny. Khi dùng Bunny, `NEXT_PUBLIC_VIDEO_HOSTS` thêm CDN hostname của Bunny (CSP `connect-src`/`media-src`). Thử thật với video Bunny staging (+0,25 ngày)<br>• **Review security T37 (S6):** gọi `GET /learn/lessons/{id}/playback` từ TRÌNH DUYỆT (Client Component, cookie Sanctum), KHÔNG từ SSR/Route Handler (IP ký token sẽ là IP server Next và học sinh bị 403); làm mới URL trước `expires_at` (bài dài hơn 15 phút), không chờ 403 mới xin lại | T13 (và T37 để thử Bunny thật) | 3 |
| FW5 | Quiz (KaTeX `trust:false`, đồng hồ theo `server_now`, autosave) | T22 | 2,5 |
| FW6 | Khóa học của tôi, tiến độ | T23 | 1,5 |
| FW7 | Xác nhận phụ huynh (trang công khai), quyền dữ liệu cá nhân | T29, T34 | 1,5 |
| FA1 | Layout quản trị, đăng nhập + MFA + đổi mật khẩu lần đầu, idle, menu theo vai trò, 403<br>• **Ghi chú (Bảo mật cụm 1, 2026-10-06):** mật khẩu mới của staff nay min **12** ký tự (không phải 8), 422 field `password` nếu thuộc danh sách phổ biến hoặc chứa phần trước `@` của email: form đổi mật khẩu (kể cả lần đầu) đặt `minLength` 12, hiện `errors.password[0]`; mật khẩu tạo/đặt lại do hệ thống sinh dài 20 ký tự nên không ảnh hưởng | T28 | 1,5 |
| FA2 | Chuyên đề | T06 | 0,5 |
| FA3 | Khóa học (multi-select GV chỉ với staff, upload ảnh) | T08 | 2 |
| FA4 | Cây chương/bài (dnd-kit), form bài, upload TUS, trạng thái video<br>• **Ghi chú (US-021/T37):** dùng chung cho VideoLab và Bunny, không có màn riêng. Endpoint TUS và header do API trả nên mã không đổi; `NEXT_PUBLIC_VIDEO_UPLOAD_URL` trỏ `https://video.bunnycdn.com` khi dùng Bunny (CSP `connect-src`); `chunkSize` ≤ 8 MB. Thử thật với Bunny staging (+0,25 ngày) | T09, T11, T12 (và T37 để thử Bunny thật) | 3 |
| FA5 | Soạn quiz (xem trước KaTeX) | T21 | 2 |
| FA6 | Duyệt đăng ký | T14 | 1 |
| FA7 | Mã giảm giá | T15 | 1,5 |
| FA8 | Đơn hàng: cursor Trước/Tiếp + tổng, PII che, chi tiết, hoàn tiền | T24 | 2 |
| FA9 | Xuất file (tuỳ chọn kèm liên hệ chỉ admin + lý do), poll, tải | T25 | 0,5 |
| FA10 | Quản lý tài khoản staff | T33 | 1,5 | _Ghi chú (Sửa lỗi nhỏ 3): `PATCH /admin/staff/{id}/role` trả thêm `released_course_ids`; nếu khác rỗng, hiển thị cảnh báo "N khóa không còn giáo viên phụ trách, hãy gán lại"._ |

**Tổng frontend ≈ 35,5 ngày** (FE0 2 — đã xong; web 18; admin 15,5).

## Sau MVP / bổ sung

Story do BA viết sau khi chốt task MVP (PO 2026-10-06). Architect đã chốt mã task ngày 2026-10-06 (chờ PO duyệt ở cổng #2 của US-020).

- **T35 không thuộc US-020.** PO đã dành mã này cho "image staging + CI build". Backend US-020 dùng **T36**.
- Câu hỏi mở của US-019/US-020 chưa có trả lời thì dùng mặc định ghi trong từng story.

| Story | Task | Phụ thuộc | Ghi chú |
|---|---|---|---|
| US-019 Trang chủ (Ready) | **FW8** (frontend web) | FW2 (đã dev, chưa review), design v2 (PO duyệt) | Backend không cần API mới |
| US-020 Hồ sơ giáo viên công khai (Ready) | **T36** (backend) [SEC] [DBA] | T08, T10, T33 (đã xong) | Thiết kế: `docs/tech/US-020.md`, ADR-005, api-contract §2.9, data-model `teacher_profiles` |
| US-020 | **FA11** (frontend admin) | T36, FA1, design US-020 (`nextjs-designer`) | **Màn riêng, không gộp FA10**: FA10 chỉ admin (`manage-system`), còn QLT cũng phải quản lý hồ sơ giáo viên |
| US-020 | **FW9** (frontend web) | T36, FW8, design US-020 | Gắn khu vực giáo viên vào chỗ FW8 đã chừa |
| US-021 Kết nối Bunny Stream (Draft) | **T37** (backend) [SEC] | T11, T12, T13 (đã xong); tài khoản Bunny (câu hỏi A1) cho bước kiểm thật | Không có task frontend riêng; dùng chung FA4/FW4 (xem ghi chú ở hai dòng đó) |
| US-021 | **T37-1** (backend, tuỳ chọn) | T37, PO trả lời A5 | `videos:migrate-provider`, chỉ làm nếu có video thật trên VideoLab |

Thứ tự:

```
T36 ─┬─▶ FA11
     └─▶ FW9 ◀── FW8 ◀── FW2
design US-020 ──▶ FA11, FW9 ;  design v2 ──▶ FW8
```

T36 làm được ngay (backend không chờ design). FW8 làm song song với T36.

### T36 — Hồ sơ giáo viên công khai, backend (~3 ngày) **[SEC] [DBA]** (US-020)
Phụ thuộc T08, T10, T33. Thiết kế: `docs/tech/US-020.md` (chia T36.1–T36.5, T36.2 và T36.3 làm song song được).
- Migration `teacher_profiles` + 2 CHECK + `INSERT … SELECT` từ `users.bio/avatar_path`. Model, factory, state `teacher()->withPublicProfile()`, `ConsentType::TeacherPublicProfile`, `config/teacher_profile.php` (hằng, không env).
- `PublicTeacher` (chốt đồng ý duy nhất). Sửa `CourseCatalog::findPublished`/`CourseDetailResource`. `teacher_id` cho `GET /courses`. `GET /home/teachers` (`HomepageTeacherQuery`, 2 câu SQL).
- `TeacherProfileService` gồm:
  - nội dung (PATCH từng phần), ảnh (`ImageUploadService` cắt vuông 800px);
  - đồng ý/rút (ghi `consents` + audit);
  - bật trang chủ với mutex khoá toàn bộ `teacher_profiles` theo PK;
  - `erase()` cho T34.
- `PlainText(allowNewlines)`. Gate `manage-teacher-profiles` (admin, QLT) và `own-teacher-profile` (giáo viên). Controller, Request, Resource, `TeacherEligibility`. Route theo api-contract §2.9.
- `images:prune-orphans` (hằng ngày, `--dry-run`). `ProductionConfigGuard::guardStaticUrl` (https, khác host app/web/admin) + kiểm `consent_version` khác rỗng.
- Bỏ `bio` khỏi `User::$fillable`. Sửa test T10 về `teachers[].bio`.
- **Xong khi:**
  - mọi AC1–AC21 phần backend có feature test Pest, và các test sau đều xanh:
    - (a) ma trận BR2: thiếu từng điều kiện → không có trong `/home/teachers`, kiểm cả `TeacherEligibility` cho cùng ma trận;
    - (b) AC5/AC7 trên CẢ `/home/teachers` và `/courses/{slug}`: rút đồng ý → lần gọi kế tiếp trả `null` ngay;
    - (c) AC10: bật người thứ 7 → 409 `TEACHER_HOMEPAGE_LIMIT`, trạng thái giữ nguyên; **race test** 2 tiến trình cùng bật khi đang có 5 → đúng 1 thành công (nhóm `race`);
    - (d) IDOR: giáo viên A gọi `/admin/teacher-profiles/{B}` → 403; admin/QLT gọi `/admin/me/teacher-profile/consent` → 403; route consent có `{user}` → 404/405; học sinh/khách → 401/403;
    - (e) upload `.svg`, `.gif`, `.html` đổi đuôi, polyglot, > 2 MB, > 4000 px → 422, không có file mới trên disk; jpg 1,5 MB → WebP vuông ≤ 800px, không EXIF, ảnh cũ bị xoá;
    - (f) `bio` 601 ký tự / `headline` 121 / có `<b>`, bidi, zero-width → 422; `\r\n` lưu thành `\n`;
    - (g) audit đủ 5 action, double submit không ghi trùng;
    - (h) `GET /courses?teacher_id=` lọc đúng, id lạ → rỗng, `links` giữ tham số;
    - (i) test kiến trúc: Resource công khai không đọc thẳng `avatar_path`/`bio`;
    - (j) `guardStaticUrl` có test như `StagingGuardTest`;
  - `composer ci` xanh (Pint, PHPStan, Pest);
  - DBA review migration + mutex; Security review.
- Backlog phát sinh: **T36-1** xoá cột `users.bio`, `users.avatar_path` ở release sau (~0,25 ngày, [DBA]). **T34** gọi `TeacherProfileService::erase()` khi ẩn danh hoá.

### T37 — Kết nối Bunny Stream, backend (~3 ngày) **[SEC]** (US-021)
Phụ thuộc T11, T12, T13 (đã xong). Làm được bằng `Http::fake` khi chưa có tài khoản; bước kiểm thật cần tài khoản Bunny từ PO (US-021 câu hỏi A1).
- `BunnyStreamProvider` đúng hợp đồng `VideoProvider`: tạo video, chữ ký TUS, `getVideo` (ánh xạ mã trạng thái, mã lạ giữ `processing`), URL HLS có token ký theo `/{guid}/` (ràng IP khi `video.bind_ip`), xoá (404 = xong), `parseWebhook` (chỉ lấy `VideoGuid`). Mọi HTTP timeout ≤ 10 s, lỗi không chứa khoá.
- `VideoProviderManager::createBunnyDriver()` dựng adapter thật; `config/video.php` `providers.bunny`; `.env.example` `BUNNY_*`.
- `VideoUploadService`: `provider_library_id` lấy từ nhà cung cấp đang dùng, không từ `video.library_id` (của VideoLab).
- `ProductionConfigGuard`: thiếu `BUNNY_*` khi Bunny bật → không khởi động; `BUNNY_CDN_HOST` https và khác host app/web/admin.
- Tài liệu: mục cấu hình thư viện Bunny + webhook trong `docs/ops/production-checklist.md`; api-contract §2.7.
- **Xong khi:** AC1–AC16 của US-021 có test Pest (trừ AC9 và phần đối chiếu thật của AC16 làm tay trên thư viện Bunny staging, ghi vào báo cáo QA); `VIDEO_ENABLED_PROVIDERS=bunny,internal` phát đúng từng nhà cung cấp; `composer ci` xanh; review + Security + QA.
- **T37-1 (tuỳ chọn, ~1,5 ngày)** chuyển video cũ: lệnh `videos:migrate-provider` theo ADR-002 §6.4. Chỉ làm nếu PO có video thật trên VideoLab.

### FW8 — Trang chủ thật (`nextjs-dev`, ~2,5 ngày, +0,25–0,5 ngày cho poster) (US-019)
Phụ thuộc FW2 (review xong), design v2 do PO duyệt.
- Trang `/` thay `/v2`: hero, chọn lớp, khóa nổi bật (`GET /courses?sort=featured`, lấy 4 đầu), một buổi học, phụ huynh.
- SSR, CSP nonce, `publicFetch` `revalidate: 60` (ADR-004 §2.7).
- Mỗi khu vực tự xử lý rỗng/lỗi. Chừa slot khu vực giáo viên sau "Khóa học nổi bật" (và sau poster nếu poster hiển thị): chưa có FW9 thì không render gì.
- Ghi chú phạm vi (PO 2026-10-06): thêm khối "poster người sáng lập" ngay sau "Khóa học nổi bật" (US-019 BR10, AC13–AC19). Nội dung cố định trong code + asset tĩnh, không API/backend; chưa có ảnh/chữ thật thì component trả `null`. Ước lượng cộng thêm khoảng 0,25–0,5 ngày, tổng ~2,75–3 ngày (chưa tính chờ PO gửi ảnh/chữ, xem Q15–Q16 của US-019).
- **Xong khi:** AC của US-019 (gồm AC13–AC19 của poster) có test (Vitest + Playwright với backend thật); khu vực giáo viên vắng mặt không để khoảng trắng (US-019 AC11); 375/1280px không cuộn ngang; lint, typecheck, test, build xanh; review + QA.

### FW9 — Khu vực giáo viên ở trang chủ (`nextjs-dev`, ~1,5 ngày) (US-020)
Phụ thuộc T36, FW8, design US-020.
- Server component gọi `GET /home/teachers` qua `publicFetch` (`revalidate: 60`, `tags: ['teachers']`, header SSR nội bộ như FW2).
- Thẻ gồm: ảnh vuông (`next/image` hoặc `<img>` từ `STATIC_URL`, alt "Ảnh thầy/cô {name}"), tên (cắt 2 dòng), `headline` nếu có, "Lớp 9, 10", "N khóa học", `bio` cắt 3 dòng bằng text (cấm `dangerouslySetInnerHTML`), liên kết "Xem N khóa học" → `/khoa-hoc?teacher_id={id}`.
- Ảnh lỗi → avatar chữ cái đầu (AC17).
- `data` rỗng hoặc API lỗi → không render gì (BR10, AC15).
- 375px: 1 cột hoặc dải cuộn tay, không tự chạy. Từ 1024px: 3 cột (AC16).
- Thêm vào FW2: trang danh mục nhận `teacher_id` (truyền qua API, hiện chip "Giáo viên: {tên}" lấy từ `teachers[]` của kết quả, có nút bỏ lọc). Trang chi tiết khóa hiện avatar chữ cái khi `avatar_url = null` và ẩn bio khi `bio = null`.
- **Xong khi:**
  - Playwright với backend thật cho AC6, AC11–AC17 và AC7 (rút đồng ý, rồi sau ≤ 60 s ảnh biến mất ở trang chủ và trang chi tiết);
  - thẻ không vỡ với tên 150 ký tự và với 1 người;
  - CSP không bị vi phạm (ảnh từ `STATIC_URL` có trong `img-src`);
  - lint, typecheck, test, build xanh; review + QA.

### FA11 — Hồ sơ giáo viên (`nextjs-dev`, ~2,5 ngày) (US-020)
Phụ thuộc T36, FA1, design US-020.
- **"Hồ sơ của tôi"** (menu chỉ cho giáo viên):
  - tải ảnh có bước cắt vuông 1:1 ở client trước khi upload (multipart `avatar`), xoá ảnh;
  - `headline`, `bio` có đếm ký tự 120/600; PATCH chỉ gửi trường đã đổi;
  - ô đồng ý hiện `consent.current_text`, gửi `version`; nhận 409 `CONSENT_VERSION_CHANGED` thì tải lại và hiện câu mới; nút rút đồng ý có xác nhận;
  - trạng thái "Đang hiển thị/Chưa hiển thị trên trang chủ" kèm lý do (`homepage_status.reasons` dịch sang tiếng Việt);
  - "Chỉnh sửa gần nhất bởi {tên}, {thời điểm}" khi `last_edited_by.is_self = false`.
- **"Giáo viên trang chủ"** (menu cho admin + QLT):
  - danh sách (`GET /admin/teacher-profiles`, lọc `homepage=1`, tìm tên), bộ đếm "đang bật X/6" từ `meta.homepage`;
  - bật/tắt, đặt thứ tự (PATCH `/homepage`), nhãn "Chưa hiện: {lý do}";
  - 409 `TEACHER_HOMEPAGE_LIMIT` hiện đúng thông điệp, giữ trạng thái cũ;
  - sửa hộ ảnh/headline/bio;
  - ô đồng ý chỉ đọc, kèm chữ "Chỉ giáo viên được đồng ý công khai" (không dùng tooltip);
  - người bị khoá hoặc đã đổi vai trò vẫn hiện để tắt được.
- **Xong khi:** Vitest cho form, cắt ảnh, mapping lỗi; Playwright với backend thật cho AC1, AC2, AC3, AC8, AC9, AC10, AC18, AC21 (2 tab sửa 2 trường khác nhau không mất dữ liệu); giáo viên không thấy menu quản lý, admin/QLT không thấy "Hồ sơ của tôi"; lint, typecheck, test, build xanh; review + QA.

## Thứ tự & ước lượng

```
T01 → T02 ─┬─ T03 → T04 → T05 → T27
           │          └─ T28 ──────────────┬──────────────────────────────┐
           ├─ T06, T07 → T08(+T28) → T09 → T11 → T12 ║ T13 → T21 → T22 → T23
           │         └─ T10                                                │
           │         └─ T14 ──────────────────────┐                        │
           T17 (sau T01) ─────────────┐           │                        │
           T15 → T16 ───────────────▶ T18 → T19 → T20                      │
                                             └─ T24(+T28) → T25 → T30      │
           T29 (sau T04) → T34 ;  T33 (sau T28) ; T26, T31 cuối
FE0 song song T01 ─▶ FW*/FA* theo cột phụ thuộc
```

- **Backend MVP: ~55 ngày công** (không đổi khi chuyển sang Laravel 13; bỏ T32 nâng cấp sau go-live, tiết kiệm ~3 ngày ngoài MVP; dự phòng +0,5 ngày ở T01/T08 nếu phải thay package chưa hỗ trợ Laravel 13) — tăng từ ~36 do bổ sung bảo mật/dữ liệu cá nhân (T27–T31, T33, T34 và các task hiện có dày hơn). **2 dev backend ≈ 30 ngày.**
  - Dev A: T01–T05, T27, T28, T14–T20, T24, T25, T30.
  - Dev B: T06–T13, T21–T23, T29, T33, T34, T26, T31.
- **Frontend: ~35 ngày** (1 dev `nextjs-dev`); nên có 2 người nếu muốn khớp tiến độ backend.
- Mốc demo: sau T10 + FW2 (danh mục) → sau T13 + FW4 (học video) → sau T20 + FW3 (mua MoMo sandbox) → sau T25 + FA8/FA9 (vận hành đơn).
- Security review theo cụm: (T01–T05, T27, T28), (T08, T09, T12, T21), (T17–T20), (T24, T25, T29, T33, T34), T31. DBA review: T01, T07, T13/T22, T15, T18–T20, T24–T25, T30.
