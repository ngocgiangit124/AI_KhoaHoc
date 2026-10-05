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
  - Tạo phiên upload (kiểm size ≤ 2 GB, hạn mức 20 GB/ngày); webhook `whereIn(provider)` + pull-verify; `videos:check-stuck`.
- [ ] **T12 Module VideoLab** (~4 ngày) **[SEC]** — song song T13 sau T11
  - Toàn bộ ADR-002 §3a: magic bytes; ffprobe/ffmpeg với `-protocol_whitelist file` + `-format_whitelist` qua `Process` mảng tham số.
  - **Container `infra/worker-video`** (non-root, không mount `.env`, network `internal`, giới hạn CPU/RAM).
  - Ràng buộc `{guid}`/`{path}` + kiểm `realpath`; thư mục source ≠ hls.
  - TUS: `max_bytes`, 413, 1 upload/guid, TTL ≤ 6h, HMAC.
  - CORS riêng, `library/*` chỉ nội bộ, `X-Accel-Redirect` + `internal`.
  - Test đủ 5 case ở §3a.7.
- [ ] **T13 Học & tiến độ** (~2 ngày) **[DBA] [SEC]** — phụ thuộc T11, T05
  - `LessonAccessService`, playback (TTL 15 phút, ràng IP bài không preview, throttle 30/phút/user, log `playback`, cảnh báo bất thường).
  - Heartbeat dùng `lockForUpdate` (DBA 2.5) + kẹp delta; 90%.
  - Preview chỉ bài chưa xoá của khóa published.

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
- [ ] **T16 Giỏ hàng** (~1,5 ngày) — phụ thuộc T15, T14
  - `PricingCalculator`, `CouponEvaluator`.
  - Mã không tồn tại/chưa bắt đầu/vô hiệu → cùng `COUPON_INVALID`; limiter 30 lần sai/ngày (S18).
- [ ] **T17 Thanh toán: abstraction + MoMo** (~2 ngày) **[SEC]** — song song T15/T16
  - `enabled_gateways` + boot guard + Fake chỉ local/testing (S4).
  - `MoMoSigner` (unit test vector mẫu).
  - `accessKey` từ config; kiểm partnerCode/requestId/orderId; parse amount nghiêm ngặt; bảng mã (chỉ `0` là thành công) (S12).
  - `orderInfo` không PII; TLS verify; log không secret/PII.
  - `queryStatus` verify chữ ký phản hồi.
  - Kiểm chứng sandbox.
  - _Dev 2026-10-05: code + 63 test xong (tests/Feature/T17), chờ Reviewer; sandbox MoMo + vector chính thức chưa kiểm (xem backlog-v2 T17-1). `queryStatus` nhận DTO `PaymentStatusQuery` thay vì model `PaymentAttempt` (model thuộc T18)._
- [ ] **T18 Checkout** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T16, T17
  - Bảng: `orders` (+ `coupon_hold_until`), `order_items`, `order_status_logs`, `payment_attempts`.
  - **Migration thêm FK `enrollments.order_id` → `orders.id` (restrictOnDelete)** (T07 chỉ tạo cột + index vì `orders` chưa tồn tại); `down()` `dropForeign`; kèm test kiểm FK.
  - `CheckoutService` (khoá `carts → orders → coupons`, sức chứa theo `coupon_hold_until`), middleware `parent.consent` (tạm cho qua nếu `parent_consent_status` ∈ {not_required, granted}; luồng đầy đủ ở T29).
  - Test: race 2 tab; N HS cùng mã cuối (DBA checklist §5.6).
  - **Khi có bảng `order_items`/`orders`: sửa `CourseService::delete` (T08)** kiểm thêm khóa còn nằm trong đơn đang chờ thanh toán (`pending`, chưa hết hạn) → 409 `COURSE_HAS_ENROLLMENTS` (hoặc mã riêng); xoá mềm khóa đang có đơn chờ làm IPN về sau không cấp được quyền học. Kèm test. (Review T08 R1.)
  - Khi thêm FK `enrollments.order_id`: sửa `tests/Feature/T14/ConcurrentEnrollmentTest.php` (worker `grant` dùng `order_id=42` không có FK; test nhóm `race`).
- [ ] **T19 IPN & fulfillment** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T18, T14
  - `payment_webhook_events` (IX `received_at`, payload chỉ trường đã biết, `source`).
  - `PaymentWebhookService::apply`, `markPaid` theo thứ tự khoá chuẩn (DBA #2).
  - Rà lại `CourseService::delete` (T08) đã chặn khóa có đơn chờ thanh toán (xem T18).
  - Gọi `EnrollmentService::grantPurchase` (T14): so `order_id` của dòng trả về với đơn đang xử lý (không dựa vào `wasRecentlyCreated`); retry deadlock của grantPurchase chỉ có tác dụng ở transaction ngoài cùng nên `markPaid` phải tự bọc retry deadlock ở mức ngoài.
  - `grantPurchase` ném `DomainException` `COURSE_UNAVAILABLE` (409) khi khóa đã xoá mềm (khóa chỉ unpublished vẫn cấp quyền): `markPaid` phải bắt mã này để chuyển đơn sang `needs_review`/hoàn tiền, không nuốt lỗi và không để IPN trả 5xx lặp.
  - Giới hạn body 16 KB + 120/phút/IP.
  - `needs_review` khi `used_count > max_uses`.
  - `OrderPaidMail` ShouldBeEncrypted.
  - Test: bảng mã, `amount` lạ, body 1 MB → 413, IPN trùng.
- [ ] **T20 Đối soát + huỷ 12h + `/pay` + đơn của tôi** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T19
  - Như bản trước, thêm: `/pay` khoá `carts → orders`, vi phạm unique → trả attempt hiện có; kiểm lại sức chứa mã khi quá `coupon_hold_until` (S12.5, S18).
  - Test: 2 request `/pay` song song → 1 attempt mới.

## Giai đoạn 6 — Quiz & tiến độ (US-007, US-008)
- [ ] **T21 Soạn quiz** (~1,5 ngày) **[SEC]** — phụ thuộc T09
  - scopeBindings; `course_id` suy ra; nội dung văn bản thuần ≤ 5.000; ≤ 200 câu; copy-on-write.
- [ ] **T22 Làm quiz** (~2 ngày) **[DBA]** — phụ thuộc T21, T13
  - CHECK `chk_quiz_attempts_question_count` (DBA #6); `JSON_SET` với `question_id` ép int.
  - Resource lượt đang làm không có `is_correct`/`explanation` (test khẳng định — I3).
- [ ] **T23 Khóa học của tôi + tiến độ** (~1 ngày) — phụ thuộc T13, T22. Ghi thời gian xử lý vào log để theo dõi mốc p95 300 ms (DBA #10).

## Giai đoạn 7 — Quản lý đơn (US-010)
- [ ] **T24 Admin đơn hàng** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T19, T28
  - `OrderFilterQuery` (bắt buộc khoảng ngày ≤ 366, áp ngày/trạng thái trước; `q` theo 4 dạng, escape LIKE).
  - **`cursorPaginate`** + `COUNT` riêng (DBA #9).
  - `PiiMasker` che email/SĐT ở danh sách; chi tiết ghi audit `order.view_pii`; `RefundService` + audit.
  - `EXPLAIN ANALYZE` với ≥ 100k đơn seed (DBA checklist §5.4).
- [ ] **T25 Xuất CSV/XLSX** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T24
  - `include_contact` chỉ admin + lý do; không bao giờ xuất thông tin phụ huynh.
  - `CsvCell` chống injection (`= + - @ \t \r`), XLSX kiểu string.
  - Giới hạn 10 lần/ngày; audit tạo/tải; tên file cố định; signed URL 10 phút đúng host admin-api.
  - `exports:purge` + file mồ côi 48h.

## Giai đoạn 8 — Dữ liệu cá nhân, vận hành, phát hành
- [ ] **T29 Đồng ý của phụ huynh** (US-017 — BA viết story; nội dung pháp lý chờ pháp chế) (~2 ngày) **[SEC]** — phụ thuộc T03, T04
  - `ParentConsentService`, `ParentConsentMail` (link ký 72h, dùng 1 lần), endpoint `/parent-consents/{token}`.
  - Middleware `parent.consent` đầy đủ; rút lại đồng ý; audit.
- [ ] **T30 Job dọn dữ liệu & bộ đếm** (~1 ngày) **[DBA]** — phụ thuộc T19, T04, T25 (DBA #3)
  - `payments:purge-webhook-events` (> 24 tháng, lô 5.000, nghỉ 200ms).
  - `audit:purge`, `otp:prune`, `users:purge-unverified` (> 7 ngày, chỉ tài khoản không có đơn/enrollment).
  - `queue:prune-failed --hours=168`; lịch trong `routes/console.php`.
  - Lên lịch `counters:recount` (T14, hiện chỉ chạy tay; thêm `coupons.used_count` ở T15).
- [ ] **T34 Quyền dữ liệu cá nhân** (US-018 — BA viết story) (~2 ngày) **[SEC]** — phụ thuộc T29
  - `/me/data-export`, xoá tài khoản bằng OTP → `AccountAnonymizer` (giữ đơn hàng), audit.
- [ ] **T33 Quản lý tài khoản staff** (US-016 — BA viết story) (~1,5 ngày) **[SEC]** — phụ thuộc T28
  - API admin: tạo/khoá/mở khoá/đặt lại mật khẩu staff (`manage-system` chỉ admin); xem audit log (chỉ đọc).
- [ ] **T26 Vận hành queue/scheduler** (~1 ngày)
  - Lưu ý `counters:recount` (T14) chưa được lên lịch.
  - Đăng ký toàn bộ lịch (README §4) với `withoutOverlapping()->onOneServer()`; Supervisor mẫu; cảnh báo `failed_jobs`; tài liệu tunnel IPN.
- [ ] **T31 Checklist production & DNS** (~1 ngày) **[SEC]**
  - ADR-004 §6 (S22): Nginx 4 host + tên miền tĩnh; header; Redis; secret; staging domain riêng; rà DNS chống subdomain takeover (S6).
  - Kiểm `php artisan about` staging = production.
  - Staging (`APP_ENV=staging`) **không được** dùng `CAPTCHA_DRIVER=fake` (ProductionConfigGuard chỉ chặn ở `production`): mở rộng guard hoặc kiểm tay; mặc định config đã là `turnstile` (R3, review T03).

## Frontend Next.js (agent `nextjs-dev`)

| Mã | Nội dung | Phụ thuộc API | Ước lượng |
|---|---|---|---|
| FE0 | Khởi tạo (mục trên) | T01 | 2 |
| FW1 | Đăng ký (checkbox đồng ý, Turnstile, phụ huynh), đăng nhập, OTP, quên/đổi mật khẩu, overlay phiên | T03–T05, T27 | 3 |
| FW2 | Danh mục `/khoa-hoc`, `/lop-{grade}`, chi tiết `/khoa-hoc/{slug}`:<br>• **render động + CSP nonce** (ADR-004 §2.7 — không ISR/PPR), dữ liệu qua `publicFetch` với `revalidate: 60`, `tags: ['catalog']`<br>• `generateMetadata` + JSON-LD có `nonce`<br>• `robots.txt` / `sitemap.xml` là route handler `revalidate = 3600`<br>• `viewer-state` gọi phía client bằng `authFetch`<br>• mô tả khóa qua DOMPurify (thêm allowlist ESLint cho đúng 1 component)<br>• **load test**: p95 TTFB ≤ 500 ms ở 50 req/s khi cache ấm, ≤ 1,2 s khi cache lạnh — ghi kết quả vào `frontend/README.md` | T10 | 3,5 |
| FW3 | Giỏ hàng, checkout, `/checkout/ket-qua` (poll, "Kiểm tra lại", link hết hạn), đơn của tôi; kiểm host `pay_url` | T16–T20 | 3 |
| FW4 | Học video (hls.js, tự lấy lại link khi 403, heartbeat, overlay), iframe link ngoài sandbox | T13 | 3 |
| FW5 | Quiz (KaTeX `trust:false`, đồng hồ theo `server_now`, autosave) | T22 | 2,5 |
| FW6 | Khóa học của tôi, tiến độ | T23 | 1,5 |
| FW7 | Xác nhận phụ huynh (trang công khai), quyền dữ liệu cá nhân | T29, T34 | 1,5 |
| FA1 | Layout quản trị, đăng nhập + MFA + đổi mật khẩu lần đầu, idle, menu theo vai trò, 403 | T28 | 1,5 |
| FA2 | Chuyên đề | T06 | 0,5 |
| FA3 | Khóa học (multi-select GV chỉ với staff, upload ảnh) | T08 | 2 |
| FA4 | Cây chương/bài (dnd-kit), form bài, upload TUS, trạng thái video | T09, T11, T12 | 3 |
| FA5 | Soạn quiz (xem trước KaTeX) | T21 | 2 |
| FA6 | Duyệt đăng ký | T14 | 1 |
| FA7 | Mã giảm giá | T15 | 1,5 |
| FA8 | Đơn hàng: cursor Trước/Tiếp + tổng, PII che, chi tiết, hoàn tiền | T24 | 2 |
| FA9 | Xuất file (tuỳ chọn kèm liên hệ chỉ admin + lý do), poll, tải | T25 | 0,5 |
| FA10 | Quản lý tài khoản staff | T33 | 1,5 |

**Tổng frontend ≈ 35,5 ngày** (FE0 2 — đã xong; web 18; admin 15,5).

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
