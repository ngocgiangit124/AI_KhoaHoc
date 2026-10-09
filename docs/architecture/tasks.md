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
  - Quy tắc tuổi theo `privacy.parent_consent_age`. _(Bỏ ở T29: ADR-006, liên hệ phụ huynh tuỳ chọn.)_
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
- [ ] **T16-1 pending_order trong GET /cart** (US-022, backlog từ review FW3, ~0,25 ngày) — phụ thuộc T16, T38
  - `GET /cart` trả thêm `pending_order` (shape như checkout preview, §2.3/§2.3.1). Logic tìm đơn tách ra `App\Services\Orders\PendingOrderPresenter`, dùng chung với preview.
  - Xong khi: field có đúng shape/`null`; mọi phương thức; không lộ đơn người khác; đúng 1 truy vấn `orders`; test `tests/Feature/T16/CartPendingOrderTest.php` + T16/T18/T38 pass.
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
  - `CheckoutService` (khoá `carts → orders → coupons`, sức chứa theo `coupon_hold_until`), middleware `parent.consent` (tạm cho qua nếu `parent_consent_status` ∈ {not_required, granted}; luồng đầy đủ ở T29). _(T29 xoá middleware này: ADR-006.)_
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
  - _Từ T29/T34 (ADR-006):_
    - `markPaid` đã gọi `ParentNotifier::orderPaid` (T29). Giữ lời gọi đó, thêm test IPN thật → đúng 1 thư phụ huynh.
    - IPN về cho tài khoản đã ẩn danh (`users.anonymized_at` khác NULL): vẫn cấp quyền như bình thường (tiền đã thu). Không gửi `OrderPaidMail` vì không còn email; không gửi thư phụ huynh. Log `info` không PII.
  - _Từ review T18:_ (R3) test "tiền về link của đơn đã `superseded`" (`cancelled → paid` + `needs_review`); (R6) rà `PaymentInitResult::rawResponse` của T17 (đã lưu vào `payment_attempts.create_response`) không chứa chữ ký/PII, mask và đặt thời hạn lưu `create_response`.
- [ ] **T20 Đối soát + huỷ 12h + `/pay` + đơn của tôi** (~2 ngày) **[SEC] [DBA]** — phụ thuộc T19
  - _**V2 (PO 2026-10-06):** chuyển V2, chờ kết nối MoMo; khi làm V2 bật `FEATURE_PAID_CHECKOUT=true`._
  - _**US-022:** `GET /orders`, `GET /orders/{code}` làm ở T38 (api-contract §2.3.1). T20 chỉ điền khối `payment` cho đơn cổng; job huỷ 12h KHÔNG được đụng đơn `payment_method = manual` (đã có `orders:expire-manual`). `/pay` kiểm `PaymentMethods::isAvailable($order->payment_method)` thay cho cờ `paid_checkout`. Mã lỗi khi gọi `/pay` cho đơn `manual` (không có link thanh toán) do Architect chốt lúc thiết kế lại T20._
  - Như bản trước, thêm: `/pay` khoá `carts → orders`, vi phạm unique → trả attempt hiện có; kiểm lại sức chứa mã khi quá `coupon_hold_until` (S12.5, S18).
  - _Từ review T18:_ (R3) khi đơn bị `superseded`, attempt còn `pending` vẫn phải được job đối soát query tới hết `expires_at` của link (kể cả đơn đã `cancelled`); (R4) job huỷ 12h phải huỷ được đơn 0đ treo (`payment_method=none`, không có attempt).
  - _Từ T34 (R2):_ job huỷ 12h phải quét cả đơn `pending` của user đã ẩn danh (`users.anonymized_at` khác NULL): pha B xoá tài khoản bỏ qua đơn còn link sống và chỉ tự phát lại tối đa 6 lần cách 15 phút; hết link thì đơn chỉ còn job này dọn. IPN về cho tài khoản đã ẩn danh: cấp quyền bình thường, không gửi mail học sinh.
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
  - _**US-022 (Architect 2026-10-08):** làm trước dưới tên **T24-V1** (mục "Sau MVP"), phụ thuộc T38 + T28, không cần T19. Phần attempts/IPN của chi tiết đơn tự có dữ liệu khi T19 xong; không cần task T24 riêng nữa._
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
- [ ] **T29 Liên hệ & thông báo phụ huynh** (US-017, **thiết kế lại 2026-10-08 theo ADR-006**: PO bỏ yêu cầu phụ huynh đồng ý; nội dung chính sách là bản TẠM) (~1,5 ngày) **[SEC] [DBA]** — phụ thuộc T03, T04 (đã xong). Hợp đồng: api-contract §2.2 (khối "Bổ sung từ T29"), §2.8.1–2.8.2, §1.6, §1.7.
  - **KHÔNG làm** (đã bỏ): trang/endpoint `/parent-consents/{token}`, `POST /me/parent-consent/resend`, `ParentConsentService`, `ParentConsentMail`, link ký 72h, luồng rút đồng ý của phụ huynh.
  - **T29.1 Migration [DBA]:**
    - (a) `2026_10_20_100000_add_parent_notice_opt_out_at_to_users`: cột `users.parent_notice_opt_out_at` `timestamp NULL`. Thêm cột nullable nên là `ALGORITHM=INSTANT` trên 8.4. `down()` drop cột.
    - (b) `2026_10_20_110000_backfill_parent_consent_status`: `UPDATE users SET parent_consent_status='not_required' WHERE parent_consent_status <> 'not_required' AND id BETWEEN ? AND ?`, chạy theo lô 1.000 id. `down()` không làm gì (ghi chú: không khôi phục được, dữ liệu cũ chưa từng được dùng để chặn vì cờ đã tắt).
    - Khối lượng: production chưa có dữ liệu; local/dev vài trăm dòng.
    - Không đụng `consents`: chưa từng có dòng `parent_consent`.
  - **T29.2 Gỡ luồng đồng ý:**
    - Xoá `app/Http/Middleware/EnsureParentConsent.php` và alias `parent.consent` ở `bootstrap/app.php`.
    - Gỡ `parent.consent` khỏi nhóm route checkout ở `routes/api.php` (giữ `account.verified`).
    - Xoá cờ `features.parent_consent_enforced` cùng `FEATURE_PARENT_CONSENT_ENFORCED` ở `backend/.env.example` và `infra/production/.env.production.example`.
    - Xoá `OtpPurpose::ParentConsent` (chưa từng phát mã).
    - Giữ `ConsentType::ParentConsent` và enum `ParentConsentStatus`, thêm docblock "deprecated — ADR-006, không ghi mới".
    - Sửa test T18 đang thử nhánh `PARENT_CONSENT_REQUIRED`.
    - Ghi chú đã đóng T18-3, T18-7 ở `docs/security/backlog-v2.md`.
  - **T29.3 Đăng ký:**
    - `RegisterRequest`: `parent_phone`/`parent_email` luôn `nullable` (bỏ `isMinor()`/`required_without`). Thêm rule khác email/SĐT của chính học sinh, so sau chuẩn hoá, thông điệp như api-contract.
    - `RegistrationService`:
      - lưu liên hệ phụ huynh với mọi độ tuổi;
      - `parent_consent_status` luôn `NotRequired`;
      - bỏ `isBelowConsentAge` nếu không còn nơi dùng;
      - sau khi tạo tài khoản gọi `ParentNotifier::accountCreated($user)` trong `try/catch` + `report()`, không làm hỏng đăng ký.
  - **T29.4 Cấu hình:**
    - `config/privacy.php`:
      - `policy_version` mặc định `2026-10-tam`;
      - `parent_consent_age` → `parent_contact_suggest_age` (`PRIVACY_PARENT_CONTACT_SUGGEST_AGE`, 18);
      - thêm `data_export_daily_limit` (2, T34 dùng), `parent_notice_daily_cap_per_address` (5), `notice_token_key` (mặc định dẫn xuất từ `APP_KEY` bằng HMAC với nhãn `parent-notice-unsub`).
    - `config/features.php`: thêm `parent_notices` (`FEATURE_PARENT_NOTICES`, mặc định `true`).
    - `PublicConfigController`: `parent_consent_age` lấy từ khoá mới, thêm `parent_contact_required: false`.
    - `ProductionConfigGuard`: `privacy.policy_version` khác rỗng.
  - **T29.5 Liên hệ phụ huynh:**
    - `App\Services\Privacy\ParentContactService::update(User, array $validated)`:
      - kiểm `CurrentPasswordGuard`;
      - transaction: khoá dòng `users` FOR UPDATE, so khác biệt, ghi, đổi email thì reset `parent_notice_opt_out_at`;
      - audit `parent_contact.update` chỉ ghi `added|changed|removed|unchanged`;
      - `DB::afterCommit` → `ParentNotifier::contactAdded`.
    - `UpdateParentContactRequest` (thiếu key = giữ, `null`/`""` = xoá) và `ParentContactResource` (che email/SĐT, `notices_enabled`).
    - `Privacy\ParentContactController@show|update`. `MeController` thêm `parent_contact`.
  - **T29.6 Thông báo:**
    - `ParentNotifier::accountCreated|contactAdded|orderPaid(Order)`. Điều kiện và trần theo api-contract §2.8.2. Trần 5 thư/ngày/địa chỉ bằng `RateLimiter` với khoá `parent-notice:`.sha256(email). Gửi bằng `Mail::to()->queue()` sau commit. Audit `parent_notice.sent {kind}`.
    - `ParentNoticeToken::make|verify`: HMAC + `hash_equals`; chỉ hợp lệ khi khớp `parent_email` hiện tại.
    - `ParentNoticeMail`:
      - `ShouldQueue`, `ShouldBeEncrypted`, tries 3, backoff 60/300/900;
      - Markdown tiếng Việt;
      - header `List-Unsubscribe` + `List-Unsubscribe-Post` qua `headers()`;
      - tên học sinh che theo quy tắc trong api-contract.
    - **Móc cho T19:** `OrderFulfillmentService::markPaid` gọi `ParentNotifier::orderPaid($order)` trong `DB::afterCommit` khi đơn vừa chuyển `paid` và `total_amount > 0`. Làm ngay ở T29 và test bằng `markPaid(..., 'ipn')` trên đơn giả. Khi cờ thanh toán tắt thì không phát sinh ở môi trường thật.
  - **T29.7 Huỷ nhận thông báo:**
    - `Privacy\ParentNoticeUnsubscribeController`: route công khai `POST /parent-notices/unsubscribe`, `withoutMiddleware(EnsureFrontendRequestsAreStateful)`. Nhận `token` (JSON) hoặc `?t=` + form one-click. Luôn trả cùng body.
    - Đặt opt-out, audit `parent_notice.opt_out`.
  - **T29.8 Limiter:** `privacy-read`, `parent-contact`, `parent-notice-unsub` ở `AppServiceProvider` (§1.6). Thêm route vào test kiến trúc `RouteMiddlewareGroupsTest`: unsubscribe là ngoại lệ công khai, như webhook.
  - **Test (Pest, `tests/Feature/T29`):**
    - (a) học sinh 13 tuổi đăng ký không có liên hệ phụ huynh → 201, `parent_consent_status = not_required`;
    - (b) có `parent_email` → đúng 1 `ParentNoticeMail` (`kind = account_created`) tới đúng địa chỉ. Mail là `ShouldBeEncrypted`. Nội dung render không chứa email, SĐT hay ngày sinh học sinh. Tên đã che;
    - (c) `parent_email` = email học sinh → 422 field `parent_email`; `parent_phone` = SĐT (dạng `+84…`) → 422;
    - (d) 20 tuổi nhập `parent_email` → được lưu (DB là ciphertext) và có thư;
    - (e) học sinh có dữ liệu cũ `parent_consent_status = pending|revoked` (dựng bằng `forceFill`) gọi `GET /checkout/preview`, `POST /checkout` (đơn 0đ), `POST /courses/{id}/free-enrollments` → không còn 403 `PARENT_CONSENT_REQUIRED`. Test kiến trúc: không route nào mang alias `parent.consent`, class `EnsureParentConsent` không tồn tại;
    - (f) migration backfill: chèn dòng `pending`/`granted`/`revoked`, gọi `up()` của migration → mọi dòng `not_required`, chạy lần 2 không lỗi;
    - (g) `PUT /me/parent-contact`:
      - sai mật khẩu → 422, không đổi gì;
      - thiếu key → giữ; `null` → xoá;
      - đổi email → opt-out về NULL và thư `parent_contact_added` tới địa chỉ mới; cùng email (khác hoa thường) → không thư;
      - `changes` của audit không chứa `@` hay chữ số SĐT;
      - vượt 5/giờ → 429;
    - (h) `GET /auth/me`, `GET /me/parent-contact` chỉ có bản che; chuỗi email phụ huynh đầy đủ không xuất hiện trong body;
    - (i) unsubscribe:
      - token đúng → `parent_notice_opt_out_at` được đặt, lần gửi sau bị bỏ;
      - token sai / email phụ huynh đã đổi / tài khoản đã ẩn danh / gọi lần 2 → 200 cùng body, DB không đổi;
      - one-click (form `List-Unsubscribe=One-Click` + `?t=`) → 200;
      - response không có `Set-Cookie`;
      - 31 lần/giờ/IP → 429;
    - (j) 6 học sinh cùng một email phụ huynh đăng ký trong ngày → 5 thư;
    - (k) cờ `parent_notices` tắt → không thư, đăng ký vẫn 201;
    - (l) `markPaid` đơn `total_amount > 0` → 1 thư `order_paid`; gọi lại (IPN trùng) → không thêm thư; đơn 0đ → không thư;
    - (m) `/config/public` có `parent_contact_required = false`, `parent_consent_age = 18`, `policy_version = 2026-10-tam`.
  - **Xong khi:**
    - (a)–(m) xanh, `composer ci` xanh (Pint, PHPStan, Pest);
    - api-contract §2.8 khớp code (không field ngoài hợp đồng);
    - `backend/README.md` và `docs/ops/production-checklist.md` có `FEATURE_PARENT_NOTICES` (mail thật phải bật trước go-live);
    - DBA review 2 migration;
    - Security review: thư gửi bên thứ ba, token huỷ nhận, mật khẩu ở PUT.
  - Backlog **T29-1** (release sau / API v2, ~0,25 ngày, [DBA]): xoá cột `users.parent_consent_status` và enum `ParentConsentStatus`, field trong `UserResource` trả hằng `"not_required"` cho tới v2.
- [ ] **T30 Job dọn dữ liệu & bộ đếm** (~1 ngày) **[DBA]** — phụ thuộc T19, T04, T25 (DBA #3)
  - _Dev 2026-10-06 (phần không liên quan thanh toán): xong `audit:purge` (giữ 24 tháng — PO uỷ quyền chọn, `OPS_AUDIT_RETENTION_MONTHS`; lô 1.000, `--dry-run`) và `users:purge-unverified` (HS chưa xác thực > 7 ngày, loại trừ có đơn/ghi danh/tiến độ/quiz/coupon_usages; xoá kèm `consents`). Lịch 03:40/03:50 ở OperationsServiceProvider. `otp:prune`, `queue:prune-failed`, `counters:recount` đã có lịch (T26). `payments:purge-webhook-events` để V2._
  - `payments:purge-webhook-events` (> 24 tháng, lô 5.000, nghỉ 200ms).
  - `audit:purge`, `otp:prune`, `users:purge-unverified` (> 7 ngày, chỉ tài khoản không có đơn/enrollment).
  - `queue:prune-failed --hours=168`; lịch trong `routes/console.php`.
  - Lên lịch `counters:recount` (T14, hiện chỉ chạy tay; thêm `coupons.used_count` ở T15).
- [ ] **T34 Quyền dữ liệu cá nhân** (US-018; **thiết kế 2026-10-08 theo ADR-006**) (~2,5 ngày) **[SEC] [DBA]** — phụ thuộc T29 (config `privacy`, cột `parent_notice_opt_out_at`, `ParentContactResource`), T04, T36 (`TeacherProfileService::erase()`). Hợp đồng: api-contract §2.8.1, 2.8.3–2.8.5, §1.6, §1.7. Không có migration mới.
  - **T34.1 Đồng ý của chính học sinh:**
    - `ConsentService::listFor`, `needsAcceptance`, `acceptCurrent`. `acceptCurrent` khoá dòng `users` FOR UPDATE rồi chỉ chèn loại chưa có ở phiên bản hiện hành, để double submit không tạo dòng trùng.
    - `Privacy\ConsentController@index|accept`, `AcceptPolicyRequest`, `ConsentResource` (không có ip/ua).
    - `MeController` thêm `needs_policy_acceptance`.
    - Audit `privacy.policy_accepted`.
  - **T34.2 Xuất dữ liệu:**
    - `DataExportService::build(User): array` theo đúng schema §2.8.4. Mỗi mục là một truy vấn lọc `user_id`, eager load tiêu đề `withTrashed`, không N+1. `lesson_progress`/`quiz_attempts` đọc bằng `lazyById`.
    - `Privacy\DataExportController@status|store`:
      - hạn mức 2 lần/ngày VN theo 4 bước ở §2.8.4 (kiểm sơ → dựng → khoá `users` + đếm + audit → trả);
      - kiểm `CurrentPasswordGuard`;
      - log kênh `privacy` (`duration_ms`, `bytes`).
    - `config/cors.php` thêm `Content-Disposition` vào `exposed_headers`.
  - **T34.3 Xoá tài khoản:**
    - `OtpPurpose::DeleteAccount = 'delete_account'`. `OtpMail` có tiêu đề/câu riêng cho purpose này ("Mã xác nhận xoá tài khoản VitaminVui", kèm cảnh báo không chia sẻ).
    - `App\Services\Privacy\AccountAnonymizer`:
      - `assertDeletable(User)`: email đã xác thực, ngược lại 403 `ACCOUNT_NOT_VERIFIED`; không có đơn `pending` kèm attempt `pending` chưa hết hạn, ngược lại 409 `ACCOUNT_HAS_PENDING_PAYMENT` + `retry_after_at`.
      - `sendOtp(User)`: `assertDeletable` → `OtpService::issue($user, DeleteAccount, 'email', $user->email)` → audit `privacy.account_delete_otp_sent`.
      - `confirm(User, code)`: `assertDeletable` → `OtpService::consume($user, DeleteAccount, $code, destinationValid: email hiện tại == destination và đã xác thực, apply: anonymizeLocked)`.
      - **`anonymizeLocked($locked)`** chạy TRONG transaction của `consume` (dòng `users` đã khoá X). Thứ tự khoá **users → teacher_profiles → consents → otp_codes**, khớp data-model §4 đoạn hồ sơ giáo viên:
        1. `TeacherProfileService::erase($locked)` (transaction lồng thành savepoint; không có hồ sơ thì không làm gì);
        2. ghi cột `users` theo bảng dưới;
        3. `consents` của user: `revoked_at = now()` cho dòng chưa thu hồi; `ip`, `user_agent`, `destination_masked` = NULL cho mọi dòng;
        4. xoá mọi `otp_codes` của user (cột `destination` chứa email);
        5. audit `privacy.account_anonymized` (actor = chính học sinh, `changes` rỗng);
        6. `DB::afterCommit` → dispatch `FinalizeAccountDeletionJob($userId)`.
      - Sau khi `consume` trả về:
        - `StudentSessionService::revoke($user, REASON_ACCOUNT_DELETED = 'account_deleted')`. Renderer ánh xạ tombstone `account_deleted` → 401 `SESSION_REVOKED`;
        - `Auth::guard('web')->logout()`, `session()->invalidate()`, `regenerateToken()`.
    - **Cột `users` khi ẩn danh:**

      | Cột | Giá trị |
      |---|---|
      | `name` | `Tài khoản đã xoá` |
      | `email`, `phone`, `date_of_birth`, `parent_email`, `parent_phone`, `parent_notice_opt_out_at`, `referral_code_used`, `bio`, `avatar_path`, `remember_token`, `current_device_id` | `NULL` |
      | `password` | `Hash::make(Str::random(64))` |
      | `anonymized_at` | `now()` |
      | `current_session_id` | `logged_out` (qua `revoke`) |
      | Giữ nguyên | `id`, `role`, `status`, `grade_level`, `email_verified_at`, `phone_verified_at`, `created_at`, `last_login_at` (không định danh khi đứng riêng; giữ `*_verified_at` để `users:purge-unverified` không chọn nhầm) |

    - `EnsureAccountActive` thêm: `anonymized_at` khác NULL → 401 `SESSION_REVOKED` (phòng thủ thêm).
    - `users:purge-unverified` thêm `whereNull('anonymized_at')`.
  - **T34.4 Pha B: `AccountDeletionFinalizer` + `FinalizeAccountDeletionJob`:**
    - Job: queue `default`, tries 5, backoff 10/60/300/900, `ShouldBeUnique` theo user. Idempotent: chạy lại không lỗi, không ghi audit trùng.
    - Mỗi bước là một transaction riêng, đi đúng đoạn con của thứ tự khoá chuẩn:
      - (1) **giỏ:** khoá `carts` của user → xoá `cart_items`, `coupon_id = NULL`;
      - (2) **đơn `pending` không còn link sống:** `carts → orders` (FOR UPDATE theo PK) → kiểm lại → `OrderStateMachine::transition(Cancelled, 'account_deleted', 'system')`. Có link sống (race) thì bỏ qua, log `info`; job huỷ 12h (T20) sẽ dọn;
      - (3) **yêu cầu học miễn phí `pending_approval`:** method mới `EnrollmentService::withdrawPending(Enrollment)`. Khoá `courses → enrollments`, chỉ khi còn `pending_approval`: `status = rejected`, `rejection_reason = 'Học sinh đã xoá tài khoản'`, **không gửi mail**, audit `enrollment.withdraw` (actor null).
    - Enrollment `active` giữ nguyên.
  - **T34.5 Rà nơi đọc `email`/`name` của học sinh:** danh sách duyệt đăng ký FA6 / `PiiMasker` và màn đơn admin phải chịu được `email = NULL`, hiển thị `Tài khoản đã xoá`. `EnrollmentService::notifyDecision` đã bỏ qua email NULL. Viết test cho danh sách duyệt.
  - **T34.6 Route + limiter:** `consent-accept`, `data-export`. Thêm route vào `RouteMiddlewareGroupsTest`.
  - **Test (Pest, `tests/Feature/T34`):**
    - (a) `GET /me/consents` chỉ có dòng của mình, không có ip/ua. `needs_acceptance = true` khi phiên bản cũ. `accept` → 2 dòng mới, gọi lại không thêm. `policy_version` sai → 409 `CONSENT_VERSION_CHANGED` + `errors.current_version`. 2 `accept` song song → không trùng (nhóm `race`);
    - (b) file xuất:
      - khớp snapshot tập khoá §2.8.4;
      - học sinh A và B cùng khóa/đơn → file của A không chứa id/tên/email của B;
      - có email phụ huynh đầy đủ;
      - không có `password`, `current_session_id`, OTP;
      - header `Content-Disposition`, `no-store`; preflight CORS expose `Content-Disposition`;
    - (c) hạn mức:
      - lần 1, 2 → 200; lần 3 → 429 `DATA_EXPORT_LIMIT` + `Retry-After` + `resets_at` = 00:00 hôm sau giờ VN;
      - `travelTo` 00:00:01 giờ VN → 200;
      - sai mật khẩu và lỗi dựng file (mock ném lỗi) không bị tính;
      - đã dùng 1 lần, 4 tiến trình song song → đúng 1 thành công (nhóm `race`);
    - (d) `GET /me/data-export` trả `used_today`/`remaining` đúng;
    - (e) xoá, bước gửi mã:
      - chưa xác thực → 403;
      - có đơn pending + attempt pending còn hạn → 409 có `retry_after_at`;
      - gửi mã purpose `delete_account` tới email; gửi lại trong 60 s → 429;
    - (f) xoá, bước xác nhận:
      - sai/hết hạn → 422 và **mọi cột** tài khoản giữ nguyên;
      - đúng → cột `users` khớp bảng T34.3; `consents` đã thu hồi + ip/ua NULL; `otp_codes` của user = 0;
      - đúng 1 audit `privacy.account_anonymized`, actor = user;
      - `erase()` được gọi: test service với user có dòng `teacher_profiles` → dòng bị xoá;
    - (g) sau khi xoá:
      - phiên hiện tại `/auth/me` → 401; phiên thiết bị khác → 401 `SESSION_REVOKED`;
      - đăng nhập bằng email/SĐT cũ → 422 thông điệp chung;
      - đăng ký lại bằng cùng email + SĐT → 201;
      - `forgot` với email cũ → 202, không gửi mã;
    - (h) job:
      - đơn pending không link → `cancelled` / `account_deleted`;
      - `pending_approval` → `rejected`, không có mail; `active` giữ;
      - giỏ rỗng;
      - chạy job 2 lần → không lỗi, không thêm audit;
    - (i) 2 request xác nhận song song cùng mã → ẩn danh đúng 1 lần, 1 audit (nhóm `race`);
    - (j) đơn đã `paid` của tài khoản đã xoá vẫn nguyên (`order_items`, `order_status_logs`); danh sách duyệt đăng ký hiện `Tài khoản đã xoá`, không 500;
    - (k) `users:purge-unverified` không đụng tài khoản đã ẩn danh.
  - _Dev 2026-10-08: backend xong (không migration), chờ Reviewer/Security/DBA/QA; chi tiết và điểm lệch ở `docs/review/T34.md`. 101 test ở `tests/Feature/T34` (có nhóm `race` và `perf`)._
  - **Xong khi:**
    - (a)–(k) xanh, `composer ci` xanh;
    - DBA review:
      - thứ tự khoá pha A/pha B;
      - `EXPLAIN` các truy vấn xuất với 1 học sinh có 5.000 `lesson_progress` + 500 `quiz_attempts`; `POST /me/data-export` < 2 s trên Docker local;
    - Security review [SEC]: IDOR file xuất, mật khẩu, OTP purpose mới, huỷ phiên;
    - QA PASS.
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
| FW1 | Đăng ký (checkbox đồng ý, Turnstile, phụ huynh), đăng nhập, OTP, quên/đổi mật khẩu, overlay phiên<br>• **Ghi chú (gom sửa lỗi nhỏ):** lỗi OTP nay là 422 với `code` = `OTP_INVALID` (sai) / `OTP_EXPIRED` (hết hạn/không có mã) thay vì `VALIDATION_ERROR` gộp; `errors.code[]` vẫn còn nên FE cũ không vỡ — FE đổi sang phân biệt theo `code` envelope (hiện thông điệp/nút "Gửi lại mã" theo `OTP_EXPIRED`)<br>• **Ghi chú (Sửa lỗi nhỏ 2, 2026-10-06):** `POST /auth/password/reset` nay trả MỌI lỗi mã (sai, hết 5 lượt, không tồn tại) là 422 `OTP_EXPIRED` cùng thông điệp "hết hạn" (không còn `OTP_INVALID`/429 `TOO_MANY_ATTEMPTS` riêng ở reset; verify tài khoản/đổi liên hệ giữ nguyên) → FE màn đặt lại hiển thị thông điệp hết hạn + nút "Gửi lại mã" cho mọi lỗi mã<br>• **Ghi chú (Bảo mật cụm 1, 2026-10-06):** **BẮT BUỘC phát hành cùng backend Bảo mật cụm 1: `ChangeContactForm.tsx` hiện tại luôn nhận 422 vì chưa gửi `current_password`.** (a) form đổi liên hệ (`PUT /auth/contact`) PHẢI có ô "Mật khẩu hiện tại" gửi kèm `current_password` (thiếu/sai → 422 field `current_password`, 429 khi sai nhiều lần; cả với tài khoản chưa xác thực); sau khi đổi email cookie phiên được xoay nên gọi `/auth/me` lại; phiên ở thiết bị khác nhận 401 `SESSION_REVOKED`. (b) Mật khẩu đăng ký/đặt lại/đổi vẫn min 8 nhưng 422 field `password` nếu thuộc danh sách phổ biến (vd `12345678`, `password123`, `matkhau123`): hiển thị `errors.password[0]`. (c) `forgot` với email chưa xác thực vẫn 202 nhưng không có mã: màn xác nhận nên nói "nếu tài khoản có email đã xác thực" (giữ thông điệp server trả)<br>• Thiếu `Accept: application/json` giờ vẫn nhận envelope JSON; lấy `/csrf-token` với cookie phiên cũ không còn 401 → FE không cần "thử lại một lần"<br>• **Ghi chú (T29, ADR-006, PO 2026-10-08): liên hệ phụ huynh TUỲ CHỌN, phát hành cùng T29.** (a) `RegisterForm` (bản v2 và bản cũ): khối "Thông tin phụ huynh (không bắt buộc)". Mở sẵn khi tuổi < `parent_consent_age` của `/config/public` (chỉ để gợi ý), ngược lại thu gọn. Bỏ kiểm "≥ 1 trong 2" ở `schemas.ts` và `v2/auth/RegisterForm.tsx`; vẫn kiểm định dạng khi có nhập. Ô trống thì bỏ key khỏi payload. Hiện lỗi 422 mới ở `parent_email`/`parent_phone` ("…phải khác email/số của bạn"). Câu phụ: "Nếu nhập email phụ huynh, VitaminVui sẽ gửi thư thông báo cho phụ huynh. Phụ huynh không cần làm gì thêm." (b) `config.ts` zod thêm `parent_contact_required: z.boolean()` (luôn `false`). (c) `AccountBanner`, `AccountGateRoute` bỏ nhánh `parent_consent_status === "pending"`. Zod `parent_consent_status` giữ enum 4 giá trị nhưng không dùng để rẽ nhánh. (d) `auth/me` zod thêm `parent_contact` (`ParentContact`, api-contract §2.8.1) và `needs_policy_acceptance`. Banner chấp nhận lại chính sách thuộc FW7 | T03–T05, T27, T29 | 3 |
| FW2 | Danh mục `/khoa-hoc`, `/lop-{grade}`, chi tiết `/khoa-hoc/{slug}`:<br>• **SSR gọi catalog qua đường nội bộ (T31, ADR-004 §2.8):** production `API_INTERNAL_URL=http://<IP_NOI_BO_NGINX>:8081` (Nginx ép Host = host api, sau Sửa lỗi nhỏ 4; Node không đặt được `Host`); MỌI request catalog gửi `X-Internal-Token` (= `INTERNAL_API_TOKEN`, chỉ ở server Next); `X-Client-IP` (IP khách thật) CHỈ gửi cho truy vấn có `q` (header nằm trong khoá Data Cache). Host công khai xoá 2 header này. `INTERNAL_API_REQUIRED=true` chỉ bật sau khi FE đã gửi<br>• **Bổ sung sau ADR-004 §2.8 (~0,5 ngày):** (1) sửa chú thích `env.server.ts`, `apps/web/.env.example`, README (IP trần dùng được ở production nhờ Nginx ép Host; không khuyến nghị `/etc/hosts` trỏ tên miền công khai); mục FW2 README thay câu "Cần Architect xác nhận" bằng tham chiếu §2.8; (2) API trả 429/5xx khi SSR: trang danh mục/chi tiết hiện thông báo "Hệ thống đang bận, vui lòng thử lại" (error boundary của segment), không lỗi 500 trần; test khẳng định response 429 KHÔNG bị lưu vào Data Cache (lần gọi sau khi hết hạn mức trả dữ liệu mới); (3) phân trang không dùng `links`/`meta.path` của API (URL nội bộ `http://api…`); (4) FW8/FW9 dùng cùng quy tắc header<br>• **render động + CSP nonce** (ADR-004 §2.7 — không ISR/PPR), dữ liệu qua `publicFetch` với `revalidate: 60`, `tags: ['catalog']`<br>• `generateMetadata` + JSON-LD có `nonce`<br>• `robots.txt` / `sitemap.xml` là route handler `revalidate = 3600`<br>• `viewer-state` gọi phía client bằng `authFetch`<br>• mô tả khóa qua DOMPurify (thêm allowlist ESLint cho đúng 1 component)<br>• **load test**: p95 TTFB ≤ 500 ms ở 50 req/s khi cache ấm, ≤ 1,2 s khi cache lạnh — ghi kết quả vào `frontend/README.md` | T10 | 3,5 |
| FW3 | **US-022 (2026-10-08): phạm vi mới, xem mục "FW3 (US-022)"** — giỏ, thanh toán "Liên hệ Quản trị viên", "Đơn đã gửi", đơn của tôi + huỷ. Phần MoMo cũ (`/checkout/ket-qua` poll, "Kiểm tra lại", link hết hạn, kiểm host `pay_url`) tách thành **FW3-MoMo** (~1 ngày, phụ thuộc T19/T20) | T38 (FW3-MoMo: T19, T20) | 3 (+1) |
| FW4 | Học video (hls.js, tự lấy lại link khi 403, heartbeat, overlay), iframe link ngoài sandbox<br>• **Ghi chú (US-021/T37):** cùng một trình phát cho VideoLab và Bunny. Khi dùng Bunny, `NEXT_PUBLIC_VIDEO_HOSTS` thêm CDN hostname của Bunny (CSP `connect-src`/`media-src`). Thử thật với video Bunny staging (+0,25 ngày)<br>• **Review security T37 (S6):** gọi `GET /learn/lessons/{id}/playback` từ TRÌNH DUYỆT (Client Component, cookie Sanctum), KHÔNG từ SSR/Route Handler (IP ký token sẽ là IP server Next và học sinh bị 403); làm mới URL trước `expires_at` (bài dài hơn 15 phút), không chờ 403 mới xin lại | T13 (và T37 để thử Bunny thật) | 3 |
| FW5 | Quiz (KaTeX `trust:false`, đồng hồ theo `server_now`, autosave) | T22 | 2,5 |
| FW6 | Khóa học của tôi, tiến độ | T23 | 1,5 |
| FW7 | **Phạm vi mới (ADR-006, PO 2026-10-08): KHÔNG còn trang phụ huynh đồng ý/từ chối/rút lại.** Làm: (1) **Gỡ luồng cũ:** trang `/cho-phu-huynh` (cả bản `v2-preview`), loại `parent-pending`/`parent-revoked` của `AccountGate`, nhánh `PARENT_CONSENT_REQUIRED` trong `lib/catalog/cta.ts` + `CourseCtaProvider` + `RegisterFreeButton` (lỗi 403 lạ rơi về thông báo chung), mục US-017 ở `v2/muc-luc`, cùng test tương ứng. (2) **`/tai-khoan/quyen-du-lieu-ca-nhan`** (design US-018 §2.1, cập nhật): khối *Đồng ý* (`GET /me/consents`, mỗi loại hiện phiên bản + ngày; dòng "Xác nhận của phụ huynh" bỏ). Khối *Thông tin phụ huynh*: hiện bản che từ `GET /me/parent-contact`, trạng thái "Đang nhận thông báo"/"Phụ huynh đã ngừng nhận"/"Chưa có email phụ huynh"; form sửa gồm `parent_email`, `parent_phone`, `current_password`, nút "Xoá" từng ô (gửi `null`) → `PUT /me/parent-contact`. Khối *Tải dữ liệu*: `GET /me/data-export` hiện "Còn N lượt hôm nay"; nút mở hộp thoại nhập mật khẩu → `POST /me/data-export` → blob → tải file; 429 `DATA_EXPORT_LIMIT` thì khoá nút và hiện "Bạn đã tải 2 lần hôm nay. Thử lại sau 00:00." (lấy `errors.resets_at`); 422 `current_password` thì hiện lỗi dưới ô. Khối *Xoá tài khoản*: modal cảnh báo → `POST /me/account/delete/otp` (403 → hướng dẫn xác thực email; 409 `ACCOUNT_HAS_PENDING_PAYMENT` → "Bạn đang có đơn chờ thanh toán, thử lại sau {retry_after_at}") → màn OTP (`OtpInput`, đếm ngược theo `resend_available_at`, gửi lại) → `POST /me/account/delete` → xoá state, chuyển `/` kèm thông báo (§2.8.5). (3) **Banner chấp nhận lại chính sách** trong layout đã đăng nhập khi `/auth/me.needs_policy_acceptance = true`: 2 checkbox không tick sẵn → `POST /me/consents/accept` (409 `CONSENT_VERSION_CHANGED` → tải lại văn bản). Không chặn học hay mua. (4) **Trang công khai `/phu-huynh/huy-nhan-thong-bao?t=`**: không layout đăng nhập, không `authFetch`. Một nút "Ngừng nhận thông báo" → `POST /parent-notices/unsubscribe {token}` (không CSRF, `credentials: 'omit'`). Luôn hiện cùng một thông điệp thành công. KHÔNG tự POST khi tải trang. (5) **`/dieu-khoan`, `/chinh-sach-du-lieu`**: văn bản TẠM theo phiên bản (`apps/web/content/policies/<policy_version>/…`, nội dung do BA/PO cung cấp, gắn nhãn "Bản tạm — sẽ cập nhật"). Phiên bản hiển thị lấy `policy_version` từ `/config/public`; thiếu thư mục đúng phiên bản thì hiện bản mới nhất kèm cảnh báo trong log dev. `nextjs-designer` cập nhật design US-017/US-018 trước (khối phụ huynh, hộp thoại mật khẩu, trạng thái hết lượt, trang huỷ nhận) | T29, T34 | 2,5 |
| FA1 | Layout quản trị, đăng nhập + MFA + đổi mật khẩu lần đầu, idle, menu theo vai trò, 403<br>• **Ghi chú (Bảo mật cụm 1, 2026-10-06):** mật khẩu mới của staff nay min **12** ký tự (không phải 8), 422 field `password` nếu thuộc danh sách phổ biến hoặc chứa phần trước `@` của email: form đổi mật khẩu (kể cả lần đầu) đặt `minLength` 12, hiện `errors.password[0]`; mật khẩu tạo/đặt lại do hệ thống sinh dài 20 ký tự nên không ảnh hưởng | T28 | 1,5 |
| FA2 | Chuyên đề | T06 | 0,5 |
| FA3 | Khóa học (multi-select GV chỉ với staff, upload ảnh) | T08 | 2 |
| FA4 | Cây chương/bài (dnd-kit), form bài, upload TUS, trạng thái video<br>• **Ghi chú (US-021/T37):** dùng chung cho VideoLab và Bunny, không có màn riêng. Endpoint TUS và header do API trả nên mã không đổi; `NEXT_PUBLIC_VIDEO_UPLOAD_URL` trỏ `https://video.bunnycdn.com` khi dùng Bunny (CSP `connect-src`); `chunkSize` ≤ 8 MB. Thử thật với Bunny staging (+0,25 ngày) | T09, T11, T12 (và T37 để thử Bunny thật) | 3 |
| FA5 | Soạn quiz (xem trước KaTeX) | T21 | 2 |
| FA6 | Duyệt đăng ký | T14 | 1 |
| FA7 | Mã giảm giá | T15 | 1,5 |
| FA8 | Đơn hàng: cursor Trước/Tiếp + tổng, PII che, chi tiết, hoàn tiền. **US-022: phạm vi mới, xem mục "FA8 (US-022)"** (thêm tab Chờ duyệt + badge, Duyệt/Duyệt muộn/Huỷ, ghi chú nội bộ) | T24-V1, T39 | 3 |
| FA9 | Xuất file (tuỳ chọn kèm liên hệ chỉ admin + lý do), poll, tải | T25 | 0,5 |
| FA10 | Quản lý tài khoản staff | T33 | 1,5 | _Ghi chú (Sửa lỗi nhỏ 3): `PATCH /admin/staff/{id}/role` trả thêm `released_course_ids`; nếu khác rỗng, hiển thị cảnh báo "N khóa không còn giáo viên phụ trách, hãy gán lại"._ |
| FA12 | Nhật ký thao tác (chỉ admin, chỉ đọc): `/quan-tri/nhat-ky`, bộ lọc trên URL (khoảng ngày mặc định 7 ngày, hành động, người làm, loại + mã đối tượng, số dòng), phân trang Trước/Sau theo `links.next` (simplePaginate, không có tổng), hộp chi tiết `changes` dạng key–value text | T33 | 1 | _Ghi chú: dùng `page` trên URL thay cho con trỏ (simplePaginate không có cursor). Link tới đơn hàng dùng `changes.code` (T33-1); bản ghi cũ không có mã thì hiện "Đơn hàng #id", không link._ |

**Tổng frontend ≈ 36,5 ngày** (FE0 2 — đã xong; web 19 (FW7 tăng 1,5 → 2,5 ngày theo ADR-006); admin 15,5).

## Sau MVP / bổ sung

Story do BA viết sau khi chốt task MVP (PO 2026-10-06). Architect đã chốt mã task ngày 2026-10-06 (chờ PO duyệt ở cổng #2 của US-020).

- **T35 không thuộc US-020.** PO đã dành mã này cho "image staging + CI build". Backend US-020 dùng **T36**. Định nghĩa chi tiết: mục **T35** bên dưới (ADR-008, chia T35-1, T35-2, T35-3).
- Câu hỏi mở của US-019/US-020 chưa có trả lời thì dùng mặc định ghi trong từng story.

| Story | Task | Phụ thuộc | Ghi chú |
|---|---|---|---|
| US-019 Trang chủ (Ready) | **FW8** (frontend web) | FW2 (đã dev, chưa review), design v2 (PO duyệt) | Backend không cần API mới |
| US-020 Hồ sơ giáo viên công khai (Ready) | **T36** (backend) [SEC] [DBA] | T08, T10, T33 (đã xong) | Thiết kế: `docs/tech/US-020.md`, ADR-005, api-contract §2.9, data-model `teacher_profiles` |
| US-020 | **FA11** (frontend admin) | T36, FA1, design US-020 (`nextjs-designer`) | **Màn riêng, không gộp FA10**: FA10 chỉ admin (`manage-system`), còn QLT cũng phải quản lý hồ sơ giáo viên |
| US-020 | **FW9** (frontend web) | T36, FW8, design US-020 | Gắn khu vực giáo viên vào chỗ FW8 đã chừa |
| US-021 Kết nối Bunny Stream (Draft) | **T37** (backend) [SEC] | T11, T12, T13 (đã xong); tài khoản Bunny (câu hỏi A1) cho bước kiểm thật | Không có task frontend riêng; dùng chung FA4/FW4 (xem ghi chú ở hai dòng đó) |
| US-021 | **T37-1** (backend, tuỳ chọn) | T37, PO trả lời A5 | `videos:migrate-provider`. **Xong 2026-10-07** (review + QA PASS; chưa chạy thật trên Bunny, làm ở staging) |
| US-022 Thanh toán thủ công: liên hệ Quản trị viên (Draft; **Architect chốt mã/phạm vi 2026-10-08**, PO chưa trả lời Q1–Q16 → dùng mặc định BA, đổi bằng cấu hình) | **T38** (backend, ~3,5 ngày) [SEC] [DBA] | T18, T34 (đã xong) | Thiết kế: `docs/tech/US-022.md`, **ADR-007**, api-contract §2.1, §2.3.1, data-model §3.5. Đặc tả chi tiết mục **T38** bên dưới |
| US-022 | **T24** (thu gọn cho V1, ~2 ngày) [SEC] [DBA] | T38 (migration, `ManualOrderService`), T28 | **Không còn phụ thuộc T19.** api-contract §2.5.1. Mục **T24-V1** bên dưới |
| US-022 | **T39** (backend, ~2 ngày) [SEC] | T38, T24 | api-contract §2.5.1. Mục **T39** bên dưới |
| US-022 | **Design US-022** (`nextjs-designer`, ~1,5 ngày) | api-contract §2.3.1/§2.5.1 (đã chốt) | Ra `docs/design/US-022.md` + component dữ liệu mẫu. Làm song song T38 |
| US-022 | **FW3** (phạm vi mới, ~3 ngày) | T38, Design US-022 | Mục **FW3 (US-022)** bên dưới. Phần MoMo của FW3 cũ tách thành **FW3-MoMo** (~1 ngày, làm khi bật MoMo, phụ thuộc T19/T20) |
| US-022 | **FA8** (phạm vi mới, ~3 ngày) | T24, T39, Design US-022 | Mục **FA8 (US-022)** bên dưới |

Thứ tự:

```
T36 ─┬─▶ FA11
     └─▶ FW9 ◀── FW8 ◀── FW2
design US-020 ──▶ FA11, FW9 ;  design v2 ──▶ FW8
```

T36 làm được ngay (backend không chờ design). FW8 làm song song với T36.

US-022 (thanh toán thủ công):
```
T38 ─┬─▶ T24-V1 ─▶ T39 ─▶ FA8 (nối hộp Duyệt/Huỷ)
     │        └──────────▶ FA8 (danh sách, chi tiết, badge — làm được sau T24)
     └─▶ FW3
Design US-022 (song song T38) ──▶ FW3, FA8
```
- Backend tuần tự 1 dev: T38 (3,5) → T24-V1 (2) → T39 (2) ≈ 7,5 ngày. 2 dev: dev A T38 → T39; dev B bắt đầu T24-V1 khi migration T38.1 đã merge (dùng `OrderFactory::manual()`), ≈ 5,5 ngày.
- Frontend: FW3 (3) và FA8 (3) song song được; cả hai bắt đầu bằng dữ liệu mẫu theo hợp đồng đã chốt, nối API khi task backend tương ứng xong.
- Quy trình mỗi task: dev → `laravel-reviewer` → `laravel-security` (T38, T24-V1, T39 đều chạm luồng tiền/PII) → `laravel-qa`. DBA review T38.1 trước khi chạy migration trên DB dùng chung; `EXPLAIN` của T24-V1 trước QA.

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
- **T37-1 (tuỳ chọn, ~1,5 ngày)** chuyển video cũ: lệnh `videos:migrate-provider` theo ADR-002 §6.4. **Xong 2026-10-07: review + QA PASS** (`docs/review/T37-1.md`, `docs/qa/T37-1.md`) (PO quyết làm ngay vì đã có tài khoản Bunny). Chi tiết: `docs/tech/US-021.md` mục T37-1; vận hành: `docs/ops/production-checklist.md` mục 2.1.

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

### T38 — Thanh toán thủ công: đặt đơn, đơn của tôi, huỷ, hết hạn (backend, ~3,5 ngày) **[SEC] [DBA]** (US-022)
Phụ thuộc T18, T34 (đã xong). Thiết kế: `docs/tech/US-022.md`, ADR-007. Hợp đồng: api-contract §1.6, §1.7, §2.1, §2.3.1. Test ở `tests/Feature/T38`. Seed/e2e dùng tiền tố riêng `e2e-us022-*`; KHÔNG chạy `migrate:fresh`/`db:seed` trong container php (quy tắc board).
- **T38.1 Migration + model [DBA]** (~0,5 ngày): 3 migration `2026_10_21_100000_add_manual_payment_columns_to_orders`, `2026_10_21_110000_create_order_notes_table`, `2026_10_21_120000_add_payment_method_check_to_orders` theo `docs/tech/US-022.md` mục "Kế hoạch migration" (migration CHECK dừng có thông báo nếu gặp giá trị lạ, không tự sửa dữ liệu). Model `OrderNote` (`$fillable = []`, `UPDATED_AT = null`), `Order` thêm `notes()`, `confirmedBy()`, docblock cột mới; `OrderFactory::manual()` (+ `pendingManual()`, `expiredManual()`), `OrderNoteFactory`. `OrderStateMachine::ACTOR_STAFF = 'staff'`.
- **T38.2 Cấu hình + guard** (~0,25 ngày): `features.manual_payment`, khối `orders.manual` (bảng "Cấu hình" trong tech doc), `.env.example` (local bật) + `infra/production/.env.production.example` (tắt, có chú thích), `ProductionConfigGuard::guardManualPayment()` (ADR-007 §11) + `ENV_KEYS_NO_INLINE_COMMENT`.
- **T38.3 `PaymentMethods` + `/config/public` + preview** (~0,5 ngày): class `App\Services\Orders\PaymentMethods`; `PublicConfigController` (`paid_checkout_enabled` định nghĩa mới, `payment_methods`, `manual_payment`); `CheckoutPreviewResource` (`payment_methods`, `default_payment_method`, `pending_order`, `can_checkout` theo phương thức).
- **T38.4 Checkout nhánh `manual`** (~1 ngày): `CheckoutRequest` (`payment_method`, bí danh `gateway`, `customer_note`, `replace_pending`), `CheckoutService` (thứ tự kiểm đúng api-contract §2.3.1; `PENDING_ORDER_EXISTS`; `MANUAL_ORDER_LIMIT` + `Retry-After`; `expires_at` 72 giờ; `holdUntil()` = `expires_at` cho `manual`; bỏ min/max cổng; không attempt; afterCommit 2 thư chỉ khi đơn mới). `CheckoutController@store` trả thêm `payment_method`, `expires_at`. Thay mọi chỗ đọc `features.paid_checkout` trong luồng checkout bằng `PaymentMethods`.
- **T38.5 Đơn của tôi + HS tự huỷ** (~0,5 ngày): `OrderPolicy` (`view`, `cancel` = chủ đơn; tra theo `code` + `user_id` để người khác nhận 404), `Order\MyOrderController@index|show`, `Order\OrderCancelController@store`, `MyOrderListResource`, `MyOrderDetailResource` (không có ghi chú nội bộ/người duyệt/mã giao dịch/`needs_review`), limiter `orders-read`, `order-cancel`. Route vào `RouteMiddlewareGroupsTest`.
- **T38.6 `ManualOrderService` + hết hạn** (~0,5 ngày): lõi `cancel()` (khoá `carts → orders`, kiểm lại, `transition`, afterCommit thư), `cancelByStudent()`, `expireDue()`; lệnh `orders:expire-manual {--limit=500}` + lịch mỗi 15 phút `withoutOverlapping(10)->onOneServer()` ở `OperationsServiceProvider`. Chỉ quét `manual`; quét cả tài khoản đã ẩn danh nhưng không gửi thư cho họ.
- **T38.7 Thư** (~0,25 ngày): `ManualOrderReceivedMail` (HS: mã đơn, khóa, tổng tiền, kênh liên hệ từ config, hạn chờ giờ VN, câu "ghi mã đơn khi chuyển khoản", link `{FRONTEND_URL}/tai-khoan/don-hang/{code}`), `NewManualOrderStaffMail` (1 thư tới `orders.manual.notify_emails`; chỉ mã, tổng, số khóa, thời điểm, link `{ADMIN_URL}/quan-tri/don-hang/{code}`), `ManualOrderCancelledMail` (biến thể `expired` ở T38, `admin_cancelled` dùng ở T39). Mẫu `EnrollmentDecisionMail` (`ShouldQueue`, `ShouldBeEncrypted`, `afterCommit()`). Không gửi cho HS không có email đã xác thực hoặc đã ẩn danh.
- **T38.8 Xoá tài khoản (BR16)**: `AccountAnonymizer::livePaymentUntil` tính thêm đơn `manual` `pending` (`retry_after_at` = MAX), `errors.pending_order_code`, thông điệp gợi ý tự huỷ đơn. `AccountDeletionFinalizer::cancelPendingOrder` gặp đơn `manual` `pending` → giữ, log `info`, không làm job phát lại.
- **Test (Pest):**
  - (a) `/config/public`: chỉ `manual` bật → `payment_methods = ["manual"]`, `paid_checkout_enabled = true`, kênh chưa cấu hình là `null`; tắt cả 2 cờ → `[]`, `false`, `manual_payment = null`; bật cả 2 (cổng `fake`) → `["manual","fake"]` (AC1, AC30).
  - (b) preview: `payment_methods`, `can_checkout` theo bảng; `pending_order` đúng; tắt hết phương thức → notice `PAYMENT_DISABLED`.
  - (c) checkout `manual`: 201, `expires_at` = now + 72 giờ, `coupon_hold_until = expires_at`, `customer_note` lưu đã chuẩn hoá, không `payment_attempts`, không enrollment, giỏ nguyên; `Mail::assertQueued` đúng 1 thư HS + 1 thư quản trị và thư quản trị KHÔNG chứa tên/email/SĐT/ghi chú HS (AC3, BR20). Tổng 500đ và 60.000.000đ vẫn tạo được (BR11).
  - (d) dùng lại: gửi lại cùng giỏ → 200 `reused`, không thêm thư (AC4, AC5); ghi chú mới bị bỏ qua.
  - (e) đơn chờ `manual` khác nội dung: không `replace_pending` → 409 `PENDING_ORDER_EXISTS` với `errors` đúng của ĐƠN CŨ (`order_code`, `payment_method`, `items_count`, `total`, `created_at`, `expires_at`), DB không đổi; có `replace_pending: true` → đơn cũ `cancelled`/`superseded`, đơn mới 201 (AC6). Đơn chờ MoMo (`fake`) khác nội dung vẫn tự thay như cũ. Đơn chờ `manual` đã quá `expires_at` (job chưa chạy) → tự thay không hỏi.
  - (f) `CHECKOUT_CHANGED` như T18 khi giá/mã đổi (AC7); `customer_note` có `<b>`, `‮`, 501 ký tự → 422.
  - (g) hạn mức: 5 đơn mới trong ngày → đơn thứ 6 (khác nội dung) 429 `MANUAL_ORDER_LIMIT` + `Retry-After` + `resets_at` 00:00 VN hôm sau; `travelTo` 00:00:01 VN → 201; đơn dùng lại không tính (AC8).
  - (h) `payment_method` lạ / gửi `gateway` khác `payment_method` → 422; cờ manual tắt + MoMo tắt → 503 `PAYMENT_DISABLED` (AC29); manual tắt + MoMo bật → mặc định cổng, `manual` → 422.
  - (i) đơn của tôi: phân trang 10, mới nhất trước, `item_titles` ≤ 3, không N+1 (đếm query); chi tiết đơn người khác → 404 cùng body với mã không tồn tại (AC11); `cancel_reason` chỉ khi `admin_cancelled`; `replaced_by_code` = mã đơn mới sau khi thay (AC6) ở cả danh sách và chi tiết, `null` với lý do khác; danh sách có 3 đơn `superseded` vẫn không N+1; `items[].grade_level` có, `null` khi khóa đã xoá; không có khoá nội bộ (snapshot tập khoá).
  - (j) HS tự huỷ: 200 `user_cancelled`, mã được nhả (HS khác dùng được lượt cuối ngay), giỏ nguyên, không thư; lần 2 → 409 `ALREADY_PROCESSED`; đơn `manual` `paid` → 409 `ORDER_STATUS_CHANGED`; đơn `fake` `pending` hoặc đơn 0đ → 409 `ORDER_NOT_MANUAL` (kiểm `payment_method` trước trạng thái); đơn người khác → 404 (AC12); chạy được khi cờ tắt (AC29).
  - (k) **race (nhóm `race`, timeout 180 s):** (k1) 2 tiến trình `POST /checkout` cùng giỏ → đúng 1 đơn, 1 response 201 + 1 response 200 `reused`, đúng 1 thư HS + 1 thư quản trị (AC4); (k2) 5 HS cùng mã còn 1 lượt đặt `manual` song song → đúng 1 đơn mang mã, còn lại 409 `CHECKOUT_CHANGED`/`COUPON_EXHAUSTED`; sau `travelTo` +31 phút vẫn còn giữ chỗ (khác MoMo); (k3) HS tự huỷ ↔ `orders:expire-manual` cùng đơn → đúng 1 dòng log chuyển `cancelled`, tối đa 1 thư; (k4) 6 request khác nội dung + `replace_pending: true` song song (đã có 0 đơn hôm nay) → số đơn `manual` tạo trong ngày ≤ 5 và ≤ 1 đơn `pending`; (k5) xác nhận xoá tài khoản ↔ checkout `manual` → không bao giờ có tài khoản đã ẩn danh kèm đơn `manual` `pending` tạo SAU pha A.
  - (l) hết hạn: đơn quá `expires_at` → `cancelled`/`expired`, 1 thư; chạy lại không đổi gì, không thư lần 2; đơn vừa `paid` không bị huỷ; đơn MoMo pending quá hạn KHÔNG bị lệnh này đụng; tài khoản ẩn danh: huỷ nhưng không thư (AC27).
  - (m) xoá tài khoản: có đơn `manual` `pending` → 409 `ACCOUNT_HAS_PENDING_PAYMENT`, `retry_after_at = expires_at`, có `pending_order_code`; tự huỷ đơn xong → gửi mã xoá được (AC28). Pha B gặp đơn `manual` `pending` → giữ đơn, job không tự phát lại.
  - (n) `CourseService::delete` khóa nằm trong đơn `manual` `pending` 70 giờ trước (chưa hết 72 giờ) → 409 `COURSE_HAS_PENDING_ORDERS`; sau khi đơn huỷ → xoá được.
  - (o) guard: bật `FEATURE_MANUAL_PAYMENT` ở `production` với 0 kênh, Zalo `http://`/`https://evil.com`, email sai, `notify_emails` rỗng, TTL 0/200, per_day 0 → `RuntimeException`; cấu hình đúng + `FEATURE_PAID_CHECKOUT=false` + `ipn_ready=false` → khởi động được (mẫu `StagingGuardTest`).
- **Xong khi:** (a)–(o) xanh; `composer ci` xanh (Pint, PHPStan, Pest gồm nhóm `race`); DBA review T38.1 (INSTANT/COPY, CHECK) và thứ tự khoá; Security review (IDOR, PII thư quản trị, `customer_note`, guard); QA PASS.

### T38-1 — Tự xoá lời nhắn học sinh sau 90 ngày (backend, ~0,5 ngày) (US-022, quyết định PO 2026-10-09)
Phụ thuộc T38. Thiết kế: `docs/tech/US-022.md` mục "Lưu giữ dữ liệu". Test ở `tests/Feature/T38/CustomerNoteRetentionTest.php`.
- Lệnh `orders:purge-customer-notes` (idempotent, theo lô theo PK, đọc id rồi UPDATE có điều kiện lại) đặt `orders.customer_note = NULL` sau `orders.manual.customer_note_retention_days` ngày kể từ khi đơn kết thúc (`paid_at`/`cancelled_at`/`refunded_at`; `failed` dùng `updated_at`). Đơn pending không bị xoá; mọi đơn có ghi chú đều áp. Lịch 03:45 hằng ngày ở `OperationsServiceProvider`.
- Config + env `ORDERS_CUSTOMER_NOTE_RETENTION_DAYS` (90, 30..3650) trong `.env.example`, `.env.production.example`; `ProductionConfigGuard` kiểm chuỗi thô là số nguyên và khoảng hợp lệ.
- **Xong khi:** mốc 89/90/91 ngày đúng cho paid/cancelled/refunded/failed; pending giữ; chạy 2 lần không đổi; chỉ cột `customer_note` đổi (kể cả `updated_at`), `order_notes` giữ; guard chặn ngoài khoảng/chuỗi rác; EXPLAIN ghi ở `docs/review/T38-1.md` (không cần index mới); `composer ci` xanh; reviewer + QA PASS.

### T38-2 — Tự xoá nội dung nhân viên nhập sau 7 ngày (US-022, PO 2026-10-09 chiều)
- Lệnh `orders:purge-staff-notes` (lịch 03:46) đặt NULL `orders.refund_note`, `payment_reference`, `cancel_reason_public` và thay `order_notes.body` bằng `[Đã xoá theo chính sách lưu trữ]` sau `orders.manual.staff_text_retention_days` ngày kể từ khi đơn kết thúc (mốc như T38-1). Pending không đụng; đơn/tiền/trạng thái/`confirmed_by`/status logs/audit/`updated_at` giữ. `OrderNote` vẫn append-only qua Eloquent.
- Config + env `ORDERS_STAFF_TEXT_RETENTION_DAYS` (7, 1..3650) trong `.env.example`, `.env.production.example`; `ProductionConfigGuard` kiểm chuỗi thô và khoảng. Xoá tài khoản (pha B) xoá luôn các trường này trên mọi đơn của học sinh.
- **Xong khi:** mốc 6/7/8 ngày đúng cho 4 trạng thái; pending giữ; idempotent; chỉ 4 trường đổi; guard; xoá tài khoản; EXPLAIN ở `docs/review/T38-2.md`; `composer ci` xanh; reviewer + QA PASS.

### T33-1 — `changes.code` cho audit `order.*` + tham số `page` của nhật ký (đề xuất từ FA12, 2026-10-09)
- Mọi audit `order.manual_approve|manual_cancel|refund|note_add|view_pii` ghi thêm `changes.code` (mã đơn, không phải PII; `AuditLogger` gắn tự động vì khoá `code` bị bộ lọc OTP loại nếu truyền từ nơi gọi) để FE link `/quan-tri/don-hang/{code}`. `order.search_contact` không có đơn (subject null) nên không đổi. Audit cũ không sửa (bảng bất biến).
- `GET /admin/audit-logs` nhận `page` (integer 1..10000); api-contract §2 đã ghi.
- **Xong khi:** mỗi action order.* có `changes.code` đúng mã đơn (test T24, T39, T33); `page=0|abc|10001` → 422; Pint + PHPStan xanh; reviewer + QA PASS.

### BE-backlog-1 — Gói việc backend nhỏ trước go-live (backend, ~2 ngày) (nguồn: `docs/bao-cao-task.md` 2.1, `docs/security/backlog-v2.md` T29)
1. **FW6** `best_attempt_id` trong `quizzes[]` của `GET /me/courses/{course}/progress` (id lượt đã nộp điểm cao nhất của HS; hoà → nộp sớm nhất; null nếu chưa có; không N+1).
2. **FA5** `has_attempts` ở `QuizResource` và `QuizQuestionResource` (admin soạn quiz); `quiz_time_limit_enabled` trong `GET /admin/auth/me`.
3. **FA7** `GET /admin/coupons/cheapest-course-price` → `{cheapest_course_price}` (thay FE quét ≤200 khóa).
4. **FA10** `released_courses: [{id,title}]` ở `PATCH /admin/staff/{id}/role` (giữ `released_course_ids`).
5. **T29-S4** che email trong lỗi gửi `ParentNoticeMail`; **T29-S6** trần tổng thư gửi bên thứ ba `PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP` (mặc định 500/giờ).
6. Race T18: tiến trình con dọn đủ người tạo khóa/mã (giáo viên từng khóa, admin tạo mã) để không rò sang DB test.
- **Xong khi:** test Pest `tests/Feature/BEB1/*` xanh; Pint + PHPStan xanh; không có migration; reviewer + QA PASS. Hợp đồng: api-contract (đánh dấu "BE-backlog-1").

### GL-A34 — Giảm PII trong log `QueryException` + throttle route học sinh/ghi (backend, ~1,5 ngày) (nguồn: `docs/security/go-live-triage.md` A3, A4)
- **A3:** hook `report` trong `bootstrap/app.php` ghi `db.query_failed` chỉ với SQL placeholder, `sqlstate`, `driver_code`, `connection`, vị trí gọi trong `app/`; không `getMessage()`, không bindings; chặn report mặc định.
- **A4:** limiter `free-enrollment` (10/phút + 30/ngày/user) cho `free-enrollments`; `learn-read` (120/phút/user) cho `GET /learn/courses/{course}` và `GET /learn/lessons/{lesson}`; `admin-write` (120/phút/user, chỉ method ghi) cho nhóm `staff` của admin-api (T09/T21 chưa có throttle). Test kiến trúc: mọi route ghi có `throttle:*` (ngoại lệ: 2 logout).
- **Xong khi:** test `tests/Feature/GL/*` xanh; Pint + PHPStan xanh; không migration; reviewer + QA PASS. Hợp đồng: api-contract §1.6 (đánh dấu "GL-A34").

### GL-A2 — Đăng nhập sai nhiều thì đòi captcha thay vì khoá (backend, ~0,5 ngày) **[SEC]** (nguồn: `docs/security/go-live-triage.md` A2 + "Quyết định PO 2026-10-09"; backlog T03-M1, T28-1)
- `LoginService::reserveWithCaptchaGate` (dùng chung học sinh + `StaffAuthService`): đếm nguyên tử trước; lượt thứ N+1 (N = `AUTH_LOGIN_CAPTCHA_THRESHOLD`/`AUTH_STAFF_LOGIN_CAPTCHA_THRESHOLD`, mặc định 5) phải kèm `captcha_token` Turnstile, thiếu/sai → 422 `CAPTCHA_REQUIRED`/`CAPTCHA_INVALID` (hoàn lượt, chưa so mật khẩu). Trần cứng theo tài khoản 100/giờ (`AUTH_LOGIN_MAX_FAILURES`, staff `AUTH_STAFF_LOGIN_MAX_FAILURES`) và IP 200/giờ (chỉ đếm lượt không có captcha hợp lệ; chờ PO xác nhận) → 429; giữ chỗ tất-cả-hoặc-không (Lua `AtomicCounter::hitAll`); limiter riêng cho lượt captcha bị từ chối; guard S6 trong `ProductionConfigGuard`; nginx `vv-real-ip.conf` Cloudflare + script cập nhật dải IP. Mọi 422 đăng nhập có `captcha_required: bool`.
- `LoginChallengeException` + `ApiExceptionRenderer`; `captcha_token` ở `LoginRequest`/`StaffLoginRequest`; env mẫu; test `tests/Feature/GL/LoginCaptchaGateTest.php`, `LoginCaptchaRaceTest.php` (group race); T03/T28 chỉnh để giữ trần 10 và gửi captcha.
- **Xong khi:** test GL + T03/T04/T05/T27/T28 xanh; Pint + PHPStan xanh; không migration; reviewer + QA PASS; FE (nextjs-dev) hiện Turnstile ở web + admin. Hợp đồng: api-contract §1.6, §2.1, admin login (đánh dấu "GL-A2"). Ghi chú guard: `ProductionConfigGuard` chưa kiểm cấu hình mới (xem `docs/review/GL-A2.md`).

### T24-V1 — Admin đơn hàng, thu gọn cho thanh toán thủ công (backend, ~2 ngày) **[SEC] [DBA]** (US-022, US-010)
Thay phạm vi T24 ở Giai đoạn 7 cho V1. Phụ thuộc T38 (T38.1 migration + factory; `ManualOrderService` để thêm `approvalState`), T28. **Không phụ thuộc T19.** Hợp đồng api-contract §2.5.1. Test ở `tests/Feature/T24`.
- `OrderFilterRequest` + `AdminOrderQuery`: bộ lọc §2.5.1 (khoảng ngày bắt buộc ≤ 366 trừ `status[] = [pending]`; `payment_method`; `sort` newest/oldest; `q` 4 dạng, escape LIKE); luôn áp ngày/trạng thái trước; `cursorPaginate` theo `sort` + `COUNT(*)` riêng (DBA #9).
- `GET /admin/orders/pending-count`.
- `GET /admin/orders/{code}`: `AdminOrderDetailResource` đủ khoá §2.5.1 (`student.phone_verified`/`email_verified`/`account_status`/`is_deleted`, items có `course_status`/`current_price`, logs có tên actor, notes, attempts, `needs_review_reasons`, `approval{}` gồm `warnings` + `late_approval_warnings` qua `ManualOrderService::approvalState()`), đúng 1 audit `order.view_pii`; không N+1 (đếm query cố định). Danh sách: `items_count` + `first_item_title` bằng `withCount` + subquery.
- `PiiMasker` (dùng `App\Support\Mask`) ở danh sách; tài khoản đã ẩn danh hiện "Tài khoản đã xoá", không 500 (T34.5).
- `RefundService` + `POST /admin/orders/{code}/refund` như T24 gốc (thu hồi mọi enrollment của đơn qua `EnrollmentService::revoke`, `refunded_by`, `refund_note`, audit `order.refund`; khoá `carts → orders → courses → enrollments`; lần 2 → 409 `ALREADY_PROCESSED`; chưa `paid` → 409 `ORDER_STATUS_CHANGED`).
- `OrderPolicy` thêm `viewAny`, `refund` (`isStaff`); quyền kiểm trước validate; limiter `admin-order-read`, `admin-order-action`.
- `markPaid`: `recordCouponUsage` trả thêm lý do `coupon_over_limit` (vượt `max_uses`) hoặc `coupon_already_used` (trùng unique `(coupon_id, user_id)`) vào `review` (để `needs_review_reasons` đầy đủ).
- Badge menu chỉ lấy từ `GET /admin/orders/pending-count` (không thêm vào `/admin/auth/me`).
- **Test:** lọc từng tham số; thiếu `from`/`to` → 422 trừ tab chờ duyệt; > 366 ngày → 422; `sort=oldest` + cursor qua 2 trang không trùng/sót; `pending-count` chỉ đếm `manual` `pending`, `expiring_soon` đúng mốc 12 giờ; email/SĐT che ở danh sách, đầy đủ ở chi tiết, đúng 1 `order.view_pii` mỗi lần gọi chi tiết; không có khoá phụ huynh (snapshot); GV → 403 trước validate; HS ở admin-api → 401/403; `approval` đúng ma trận (pending / cancelled trong & ngoài 30 ngày / `account_deleted` / không phải `manual`); `warnings` đủ 6 loại; `late_approval_warnings` = `[]` khi không duyệt muộn được, có `ALREADY_OWNED` / `COUPON_OVER_LIMIT` / `COUPON_ALREADY_USED` đúng dữ liệu khi duyệt muộn được; `student.account_status`/`phone_verified`, `items[].course_status` (4 giá trị) đúng; danh sách có `items_count` + `first_item_title` đúng dòng `id` nhỏ nhất, đếm query không tăng theo số đơn trên trang; hoàn tiền đơn `manual` đã `paid` thu hồi enrollment (AC26).
- **Xong khi:** test xanh, `composer ci` xanh; **DBA:** `EXPLAIN ANALYZE` với ≥ 100k đơn seed (danh sách theo ngày + `payment_method`, tab chờ duyệt không khoảng ngày, `pending-count`), quyết có thêm IX `(payment_method, status, created_at)` hay không; Security review (PII, IDOR, quyền GV); QA PASS.

### T39 — Duyệt / duyệt muộn / huỷ / ghi chú đơn thủ công (backend, ~2 ngày) **[SEC]** (US-022)
Phụ thuộc T38, T24-V1. Hợp đồng api-contract §2.5.1. Test ở `tests/Feature/T39`.
- `OrderFulfillmentService::markPaid(..., ?FulfillmentOptions $options = null)` theo `docs/tech/US-022.md`: `guard` chạy ngay sau khoá `orders` và TRƯỚC nhánh "đã paid → trả về"; `after` chạy sau `transition` trong cùng transaction; `strictCourses` gom khóa đã xoá → 409 `COURSE_UNAVAILABLE` rollback; nguồn `manual` → `status_reason = manual_confirmed`, actor `staff` + id, meta `{source, late, review?}`. Hành vi `checkout`/`ipn`/`query` không đổi (test T18 cũ phải xanh).
- `OrderPaidMail` (mới, dùng chung T19): afterCommit khi `total > 0`, HS có email đã xác thực, chưa ẩn danh; nguồn `checkout` (0đ) không gửi.
- `ManualOrderService::approve()`, `cancelByStaff()`, `addNote()`; `ApproveOrderRequest`, `CancelOrderRequest`, `StoreOrderNoteRequest` (`authorize()` qua Policy để quyền kiểm trước validate); `Admin\OrderApprovalController`, `OrderCancellationController`, `OrderNoteController`; `OrderNoteResource`. Response approve/cancel = `AdminOrderDetailResource` không ghi `view_pii`.
- `confirmed_by`, `cancel_reason_public`, `order_notes` ghi trong cùng transaction với đổi trạng thái. Audit `order.manual_approve`, `order.manual_cancel`, `order.note_add` (không có nội dung ghi chú/lý do/mã giao dịch trong `changes`).
- `ManualOrderCancelledMail` biến thể `admin_cancelled` (kèm lý do gửi HS).
- **Test:**
  - (a) duyệt `pending`: `paid`, `payment_reference`, `confirmed_by`, enrollment `active` mọi khóa, `coupon_usages` + `used_count` +1, khóa bị xoá khỏi giỏ HS, đúng 1 `OrderPaidMail`, đúng 1 thư phụ huynh khi có email phụ huynh + chưa huỷ nhận + cờ bật, 0 thư khi thiếu một điều kiện; audit 1 dòng (AC17, Q7).
  - (b) thiếu/`false` `confirm` → 422 (AC18); `payment_reference` 101 ký tự/có `<` → 422.
  - (c) ma trận guard: không phải `manual` → `ORDER_NOT_MANUAL`; đã `paid`/`refunded` → `ALREADY_PROCESSED`; `late=false` khi `cancelled` → `ORDER_STATUS_CHANGED` kèm `can_approve_late` (AC21); `late=true` khi `pending` → `ORDER_STATUS_CHANGED`; `late=true` sau 30 ngày hoặc `account_deleted` → `ORDER_APPROVAL_WINDOW_PASSED`. Mọi nhánh lỗi: DB không đổi, không thư, không audit.
  - (d) duyệt muộn mỗi lý do huỷ (`expired`, `user_cancelled`, `admin_cancelled`, `superseded`) trong 30 ngày → `paid` + `needs_review` + `late_payment`; mã đã đủ lượt → vẫn duyệt, `coupon_over_limit`; HS đã sở hữu khóa từ đơn khác → giữ enrollment cũ, `already_owned` (AC22). Với từng trường hợp, `late_approval_warnings` đọc TRƯỚC khi duyệt khớp `needs_review_reasons` SAU khi duyệt (trừ `late_payment` luôn có).
  - (e) khóa ngừng bán → vẫn duyệt và cấp quyền (AC23); khóa đã xoá (chỉ có khi duyệt muộn) → 409 `COURSE_UNAVAILABLE` có tên khóa, không enrollment nào được tạo, đơn giữ `cancelled` (AC24).
  - (f) huỷ: lý do 4 ký tự/thiếu → 422; hợp lệ → `admin_cancelled`, `cancel_reason_public`, ghi chú nội bộ (nếu có), nhả mã, giỏ nguyên, 1 thư HS có lý do, audit (AC20); HS xem chi tiết thấy `cancel_reason`, không thấy ghi chú (AC14).
  - (g) ghi chú: 201, đơn không đổi trạng thái, audit; `GET /orders/{code}` của HS không có ghi chú (AC25); ghi chú cho đơn MoMo/0đ cũng được.
  - (h) **race (nhóm `race`, timeout 180 s):** (h1) 2 tiến trình duyệt cùng đơn → đúng 1 200 + 1 409 `ALREADY_PROCESSED`; enrollment, `coupon_usages`, `used_count`, `OrderPaidMail`, thư phụ huynh, audit đều đúng 1 (AC19); (h2) duyệt ↔ HS tự huỷ → đúng 1 bên thắng, bên kia 409, không bao giờ có đơn `paid` mà log cuối là `cancelled`; (h3) duyệt ↔ `orders:expire-manual` (đơn đã quá `expires_at`) → đúng 1 bên thắng; (h4) duyệt ↔ admin huỷ; (h5) duyệt ↔ HS checkout `replace_pending: true` (thay đúng đơn đó) → hoặc đơn `paid` và checkout tạo đơn mới không chứa khóa đã mua (hoặc 409 `CHECKOUT_CHANGED`), hoặc đơn `superseded` và duyệt nhận 409; (h6) duyệt muộn ↔ `CourseService::delete` khóa trong đơn → hoặc duyệt xong và xoá nhận 409 `COURSE_HAS_ENROLLMENTS`, hoặc xoá xong và duyệt nhận 409 `COURSE_UNAVAILABLE`; không deadlock treo (retry 1213 tối đa 3 lần là chấp nhận được).
  - (i) quyền: GV gọi approve/cancel/notes với payload sai → 403 (trước validate); HS gọi admin-api → 401/403 (AC31).
  - (j) cờ `FEATURE_MANUAL_PAYMENT` tắt: duyệt/huỷ/ghi chú vẫn chạy (AC29).
- **Xong khi:** (a)–(j) xanh, test T18/T29/T34 cũ xanh, `composer ci` xanh; review; Security review (lạm quyền, PII, race, idempotent); QA PASS.

### FW3 (US-022) — Giỏ, thanh toán "Liên hệ Quản trị viên", đơn của tôi (`nextjs-dev`, ~3 ngày)
Phụ thuộc T38, Design US-022. Hợp đồng api-contract §2.1, §2.3, §2.3.1. Thay phạm vi FW3 ở bảng frontend.
- Nút "Thêm vào giỏ"/"Mua" cho khóa có phí theo `/config/public.paid_checkout_enabled` + `viewer_state` (`can_buy`/`in_cart`), thay chữ "Sắp mở bán" (BR3). Số giỏ ở header lấy `cart_count` của `/auth/me`.
- `/gio-hang`: `GET /cart`, xoá khóa, áp/gỡ mã (mã lỗi T16), khóa không khả dụng, notice.
- `/thanh-toan`: `GET /checkout/preview` (khóa, giảm giá, tổng); khối "Phương thức thanh toán" dạng radio card render từ `payment_methods` (hiện 1 lựa chọn, chọn sẵn `default_payment_method`); ô "Ghi chú cho Quản trị viên (không bắt buộc)" đếm 500 ký tự + câu nhắc không ghi mật khẩu/OTP; báo trước khi `pending_order` khác null; nút "Gửi đơn" → `POST /checkout` với `expected_total`, `payment_method`, `customer_note`. Xử lý: 409 `PENDING_ORDER_EXISTS` → hộp "Đặt đơn mới / Giữ đơn cũ" (gửi lại với `replace_pending: true` hoặc mở đơn cũ); 409 `CHECKOUT_CHANGED` → hiện `preview` mới, bắt bấm lại; 429 `MANUAL_ORDER_LIMIT` → thông điệp server + `resets_at`; 422 `CART_EMPTY` → về giỏ; 403 `ACCOUNT_NOT_VERIFIED` → hướng dẫn xác thực; 503 `PAYMENT_DISABLED` → thông điệp, không tự thử lại. Nút khoá khi đang gửi; mất mạng bấm lại an toàn (server dùng lại đơn).
- `/tai-khoan/don-hang/[code]` (cũng là màn "Đơn đã gửi"): mã đơn to + nút sao chép, khóa, tổng, trạng thái theo bảng US-022, hạn chờ giờ VN, kênh liên hệ từ `/config/public.manual_payment.contact` (bỏ kênh `null`; SĐT `tel:`, Zalo tab mới `noopener noreferrer`, email `mailto:`; nút ≥ 44px ở 375px), câu "Khi chuyển khoản, vui lòng ghi mã đơn {mã}", "chưa phải trả tiền qua website"; nút "Huỷ đơn" (hộp xác nhận → `POST /orders/{code}/cancel`, 409 → tải lại) khi `can_cancel`; đơn không còn `pending` thì không hiện hướng dẫn liên hệ; `cancel_reason` khi `admin_cancelled`; `replaced_by_code` có link.
- `/tai-khoan/don-hang`: danh sách 10/trang (`GET /orders`), nhãn trạng thái tiếng Việt, hạn chờ với đơn `pending`.
- FW7: thông điệp 409 `ACCOUNT_HAS_PENDING_PAYMENT` có `pending_order_code` → link tới đơn (AC28).
- Ghi chú/lý do hiển thị dạng text (`white-space: pre-line`), cấm `dangerouslySetInnerHTML`.
- **Không làm** (sang **FW3-MoMo**): `/checkout/ket-qua`, poll, "Kiểm tra lại", link hết hạn, kiểm host `pay_url`.
- **Xong khi:** Vitest cho schema zod (khoá mới §2.1/§2.3.1), mapping lỗi, nhãn trạng thái; Playwright với backend thật (seed `e2e-us022-*`) cho AC1–AC14 + thông điệp AC28 (gồm 2 tab gửi đơn → 1 đơn, đổi giỏ → hộp thay đơn, huỷ đơn); 375/1280px không cuộn ngang; lint, typecheck, test, build xanh; review + QA.

### FA8 (US-022) — Đơn hàng quản trị + duyệt thủ công (`nextjs-dev`, ~3 ngày)
Phụ thuộc T24-V1, T39, Design US-022. Hợp đồng api-contract §2.5.1. Thay phạm vi FA8 ở bảng frontend.
- Menu "Đơn hàng" chỉ admin/QLT (`permissions.view_orders`), badge số `pending_manual` (`GET /admin/orders/pending-count` khi tải layout, mỗi 60 giây khi tab hiển thị, sau mỗi thao tác).
- `/quan-tri/don-hang`: tab mặc định "Chờ duyệt" (`status[]=pending&payment_method=manual&sort=oldest`, không khoảng ngày; cột mã, tên HS, email/SĐT đã che, số khóa, tổng, thời điểm đặt, còn bao lâu, nhãn "Sắp hết hạn" theo `expiring_soon`); tab "Tất cả" (khoảng ngày bắt buộc ≤ 366, lọc trạng thái/phương thức/`needs_review`/`q`), cursor Trước/Tiếp + tổng.
- `/quan-tri/don-hang/[code]`: thông tin HS đầy đủ (nhãn "chưa xác thực", "Tài khoản đang bị khoá", "Tài khoản đã xoá"), ghi chú HS, khóa (giá chốt, cảnh báo `warnings`), lịch sử trạng thái, ghi chú nội bộ + ô thêm ghi chú. Không gọi lại chi tiết khi không cần (mỗi lần gọi = 1 audit).
- Hộp **Duyệt** (khi `approval.can_approve`): checkbox "Đã nhận đủ {total}" (chưa tick thì nút xác nhận vô hiệu), "Mã giao dịch / nội dung chuyển khoản", "Ghi chú nội bộ". Hộp **Duyệt muộn** (khi `can_approve_late`): màu cảnh báo, nêu thời điểm huỷ + hạn `approval_window_until`, xác nhận thứ hai, gửi `late: true`. Hộp **Huỷ**: ô "Lý do (sẽ gửi cho học sinh)" 5–500 tách khỏi "Ghi chú nội bộ (học sinh không thấy)". **Hoàn tiền** (đơn `paid`) như FA8 gốc.
- Lỗi: 409 `ALREADY_PROCESSED`/`ORDER_STATUS_CHANGED` → thông báo + tải lại chi tiết (hiện nút "Duyệt muộn" nếu `can_approve_late`); `ORDER_APPROVAL_WINDOW_PASSED`, `COURSE_UNAVAILABLE` (liệt kê khóa) → thông báo; 403 → trang không có quyền.
- Nội dung do người dùng nhập hiển thị dạng text, cấm `dangerouslySetInnerHTML`.
- **Xong khi:** Vitest (form, mapping lỗi, đếm ngược hạn); Playwright với backend thật cho AC15–AC26, AC31 (gồm 2 tab cùng duyệt → 1 thành công, tab kia hiện 409 và trạng thái mới; GV không thấy menu và URL trực tiếp → 403); lint, typecheck, test, build xanh; review + QA.

## T35 — Đóng gói & triển khai staging/production (ADR-008, 2026-10-09) **[SEC] [DBA]**

Giải quyết readiness H1, H2, D2, D3, D5 (`docs/ops/go-live-readiness.md`). Thiết kế: **ADR-008** (Proposed, chờ PO các mục "Điểm chờ PO"). Không đổi cấu trúc mẫu T31 (Nginx, `grants.sql`, ACL): chỉ điền giá trị theo ADR-008 §8.4.

**Hợp đồng chung (2 dev làm song song phải khớp, nguồn: ADR-008 §8.2–8.6):**

| Mục | Giá trị |
|---|---|
| Registry | `ghcr.io/ngocgiangit124` (CI); local/smoke `vv-local` (biến `VV_REGISTRY`) |
| Image | `vitaminvui-backend:<sha>`, `vitaminvui-worker-video:<sha>`, `vitaminvui-web:<env>-<sha>`, `vitaminvui-admin:<env>-<sha>`; `<env>` ∈ `staging`, `production`; local tag `dev` (`vitaminvui-web:production-dev`...) |
| Bake | `infra/production/docker-bake.hcl` (target `php-base`, `backend`, `worker-video`; biến `VV_REGISTRY`, `IMAGE_TAG`); `frontend/docker-bake.hcl` (target `web`, `admin`; biến `VV_REGISTRY`, `IMAGE_TAG`, `VV_ENV`, các `NEXT_PUBLIC_*`) |
| Label | `org.opencontainers.image.revision`, `vv.compose-version` (backend, worker-video), `vv.env` (web, admin) |
| Compose | `infra/production/docker-compose.yml`, project `vvstack`; service `php`, `queue` (x2), `scheduler`, `worker-video`, `migrate` (profile `tools`), `mysql`, `redis`, `redis-video`, `web`, `admin` |
| Cổng host | `127.0.0.1:9000` php, `127.0.0.1:3000` web, `127.0.0.1:3001` admin; Nginx host `10.231.10.1:8081` (SSR); không publish gì khác |
| Mạng | `front` 10.231.10.0/24 (gateway .1), `app` 10.231.11.0/24, `video` 10.231.12.0/24 `internal` |
| UID | backend/worker `10001:10001`; Next user `node` |
| Thư mục host = container | `/var/www/backend/storage/app`, `/var/www/backend/storage/logs`, `/var/www/uploads`; cấu hình `/opt/vitaminvui/{env,conf}`; dữ liệu `/srv/vitaminvui/{mysql,redis,redis-video,backups}` |
| Env file server | `env/app.env`, `env/migrate.env`, `env/worker-video.env`, `env/mysql.env`, `env/web.env`, `env/admin.env`; `/opt/vitaminvui/.env` (biến compose) |
| Redis healthcheck | Không có env file Redis: user ACL `vv_healthcheck on nopass -@all +ping` trong cả 2 file ACL; healthcheck `redis-cli --user vv_healthcheck --pass x ping` (T35-1, review R3) |
| Biến chạy web | `API_INTERNAL_URL=http://10.231.10.1:8081`, `INTERNAL_API_TOKEN`, `V2_PREVIEW` (production trống); admin: `V2_PREVIEW` |
| Smoke local | thư mục `infra/production/smoke/`, project `vvsmoke`, subnet `10.231.20-22.0/24`, Nginx smoke `127.0.0.1:18080`, env sinh vào `infra/production/smoke/.generated/` (gitignore) |

Thứ tự: T35-1 ∥ T35-2 (theo hợp đồng trên) → T35-3 phần image. T35-3 phần test (job `backend-*`, `frontend`) làm song song ngay từ đầu. Sau cả 3: dựng staging theo `docs/ops/production-checklist.md` (việc hạ tầng, chờ PO P1–P4 và các điểm chờ của ADR-008).

### T35-1 — Image backend + compose production + deploy script (`laravel-dev`, ~2 ngày) **[SEC] [DBA]**
- `infra/php/Dockerfile.prod` + `infra/php/Dockerfile.prod.dockerignore`, `infra/php/prod/{zz-opcache.ini, zz-fpm-pool.conf, entrypoint.sh}` theo ADR-008 §8.8. KHÔNG sửa `infra/php/Dockerfile` (local dùng tiếp); `infra/worker-video/Dockerfile` không sửa, nhận `BASE_IMAGE` = image backend.
- `infra/production/docker-bake.hcl` (target `php-base` build `infra/php/Dockerfile` với `UID=10001 GID=10001`; `backend` dùng named context `vv-php-base`; `worker-video` dùng named context `vv-backend`). Nền tảng `linux/amd64`; base image ghim bản vá + digest.
- `infra/production/docker-compose.yml` đủ 10 service theo bảng ADR-008 §8.3 (gồm khối `web`, `admin` đúng image/cổng/env file của hợp đồng; T35-2 chỉ sửa 2 khối này nếu cần). Healthcheck: php (FPM ping qua `cgi-fcgi`), mysql (`mysqladmin ping`), redis (`redis-cli ping` có xác thực), web/admin (gọi `http://127.0.0.1:<port>/robots.txt` hoặc tương đương bằng `node -e`). Anchor chung cho log `json-file` 20m x 5, `cap_drop`, `no-new-privileges`.
- Mẫu mới: `infra/production/compose.env.example`, `.env.migrate.example`, `.env.mysql.example`, `mysql/my.cnf` (utf8mb4/`utf8mb4_0900_ai_ci`, `READ-COMMITTED`, `binlog_format=ROW`, `binlog_expire_logs_seconds` 7 ngày, `skip-name-resolve`, KHÔNG ghi `log_bin_trust_function_creators`), `redis/redis.conf` và `redis/redis-video.conf` (`aclfile`, `protected-mode yes`, `appendonly yes`), `systemd/nginx.service.d/after-docker.conf`.
- Sửa mẫu: `.env.production.example` và `.env.worker-video.example` theo ADR-008 §8.6 (`DB_HOST=mysql`, `REDIS_HOST=redis`, `REDIS_VIDEO_*` → `redis-video`, `APP_MAINTENANCE_DRIVER=cache` + `APP_MAINTENANCE_STORE=redis`, worker `CACHE_STORE=array`); dòng đầu `supervisor/vitaminvui.conf` ghi "thay bởi compose (ADR-008), giữ làm tham chiếu". KHÔNG đổi cấu trúc file Nginx/`grants.sql`/ACL.
- `infra/production/deploy.sh` (`deploy <sha>`, `rollback`, `status`, cờ `--maintenance`, `--ack-irreversible`, `--worker-video=graceful|skip`, `--force-schema-ahead`, `--local`) (backup = snapshot nhà cung cấp, PO 2026-10-10; không còn `backup-db.sh`) đúng ADR-008 §8.11–8.12 và mục "Điều chỉnh khi hiện thực T35-1". `set -euo pipefail`; `trap` luôn trả `log_bin_trust_function_creators` về 0; không có `docker compose down`; không in giá trị secret ra log.
- Dấu `// VV-IRREVERSIBLE: backfill không hoàn tác (T29)` ở dòng đầu migration `2026_10_20_110000_backfill_parent_consent_status` (chỉ thêm ghi chú, không đổi hành vi).
- `infra/production/smoke/`: `make-env.sh` (sinh env từ các mẫu, secret bằng `openssl rand`, `APP_ENV=staging`, tên miền `*.vvsmoke.test`, `TRUSTED_PROXIES=10.231.20.0/24`), `docker-compose.smoke.yml` (ghi đè subnet sang 10.231.20-22, thêm `nginx-smoke` (nginx 1.27) dùng NGUYÊN `snippets/vv-api-common.conf` + `vv-deny.conf` của production, nghe `127.0.0.1:18080` cho host api/admin-api và `:8081` trong mạng front cho SSR; thêm `mailpit`), `grants.smoke.sh` (điền `grants.sql` bằng mật khẩu đã sinh), `smoke.sh` (chạy toàn bộ kiểm bên dưới), `check-images.sh`.
- Cập nhật tài liệu: `infra/production/README.md` (bảng file mới; mục "Dựng server lần đầu": Docker Engine repo chính thức, Nginx >= 1.25.1 từ nginx.org, certbot webroot, user `vvdeploy`/`vvapp` (10001), thư mục + quyền theo ADR-008 §8.5, ufw chỉ 22/80/443, `docker login ghcr.io`); checklist §1.1 (kiểm PHP bằng `docker compose exec php php -i` và `php-fpm -i`), §6, §7, §11 (tag thay symlink, quy trình deploy/rollback mới).
- **Xong khi** (tất cả chạy được trên máy local, Docker Desktop):
  - (a) `docker buildx bake -f infra/production/docker-bake.hcl --load backend worker-video` (`VV_REGISTRY=vv-local IMAGE_TAG=dev`) xanh.
  - (b) `check-images.sh` đạt: backend chạy UID 10001; `php -m` có `Zend OPcache`, `redis`, `pdo_mysql`, `gd`, `intl`, `bcmath`, `pcntl`, `exif`, `zip`; `php -i` và `php-fpm -i` ra `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On`, `expose_php=Off`; FPM `opcache.validate_timestamps=0`; không có `/var/www/backend/.env`, `tests/`, `phpunit*.xml`, `vendor/pestphp`, `vendor/larastan`, `vendor/laravel/pint`, `.git`, `node_modules`; `env` của image không có `APP_ENV`/`APP_KEY`; `ffmpeg` chỉ có trong worker-video; label `revision`, `vv.compose-version` đúng; `docker history --no-trunc` không có chuỗi secret nào của smoke env.
  - (c) `smoke.sh` (project `vvsmoke`) chạy hết không lỗi: dựng mysql/redis/redis-video → `grants.smoke.sh` → `deploy.sh deploy --local dev`. Kiểm: preflight `about` qua cho cả env app và env worker; migrate bằng `vv_migrate` xong, sau đó `SELECT @@GLOBAL.log_bin_trust_function_creators` = 0 (kể cả khi cố tình làm migrate lỗi); `SHOW TRIGGERS LIKE 'audit_logs'` có 2 trigger; `UPDATE audit_logs ...` bằng `vv_app` lỗi; đăng nhập `vv_worker_video` `SELECT * FROM users` → 1142; trong worker-video: không phân giải được `redis` (Redis chính), không ra Internet (kết nối ra ngoài thất bại), `LLEN` queue video qua `redis-video` chạy được; `check-acl.sh` với `SKIP_SIGNALS=1` in `ACL đạt.` trên `redis-video`.
  - (d) Qua `nginx-smoke`: `Host: api.vvsmoke.test` `/up` 200, `/api/v1/config/public` 200 có `paid_checkout_enabled=false`, `/.env` và `/index.php/x` không trả 200; `php artisan down` chạy trong container `scheduler` làm request qua php trả 503, `up` trả lại 200 (chứng minh driver bảo trì `cache` dùng chung).
  - (e) `php artisan schedule:list` trong `scheduler` có đủ lệnh ở readiness §5; sau 2 phút `php artisan ops:health` không báo worker/scheduler chết; một mail test (OTP đăng ký) tới `mailpit` qua queue.
  - (f) Rollback: build lại với `IMAGE_TAG=dev2` kèm một migration giả, deploy `dev2`, rồi `deploy.sh rollback` → dừng vì "DB có migration image cũ không biết"; thêm `--force-schema-ahead` → mọi service về `dev`, `releases.log` ghi đủ 2 lần. Migration giả mang dấu `VV-IRREVERSIBLE` làm `deploy` dừng khi thiếu `--ack-irreversible`.
  - (g) `docker compose -p vvsmoke restart php` → dữ liệu `/var/www/uploads`, `storage/app` còn nguyên; `grep -n "compose down" infra/production/*.sh` rỗng; `shellcheck` (image `koalaman/shellcheck`) 0 lỗi cho mọi script.
  - (h) `composer ci` xanh (nếu chỉ thêm ghi chú migration thì chạy Pint + test T29). Review; DBA review `my.cnf`, cách bật/tắt `log_bin_trust_function_creators`; Security review (image, quyền file, mạng worker, script không lộ secret); QA chạy lại `smoke.sh` trên máy sạch.

### T35-2 — Image frontend standalone + mẫu env production (`nextjs-dev`, ~1 ngày) **[SEC]**
- `apps/web/next.config.ts`, `apps/admin/next.config.ts`: `output: "standalone"`, `outputFileTracingRoot` = gốc `frontend/`. Đọc `node_modules/next/dist/docs/` của Next 16.3.6 về standalone trong monorepo trước khi sửa (AGENTS.md). Giữ nguyên `distDir`/`NEXT_DIST_DIR` cho e2e.
- `frontend/Dockerfile` + `frontend/.dockerignore` (loại `**/.env*` trừ `*.example`, `.next*`, `node_modules`, `.pnpm-store`, `e2e`, `loadtest`, `test-results`) + `frontend/docker-bake.hcl` (target `web`, `admin`, tag `${VV_REGISTRY}/vitaminvui-<app>:${VV_ENV}-${IMAGE_TAG}`, label `vv.env`) theo ADR-008 §8.9. Mọi `ARG NEXT_PUBLIC_*` mặc định `""`; KHÔNG có `ARG`/`ENV` cho `INTERNAL_API_TOKEN` hay biến server-only nào khác.
- Mẫu env production: `apps/web/.env.production.example`, `apps/admin/.env.production.example`, mỗi file 2 phần có tiêu đề rõ: "BUILD (GitHub Environment vars, nhúng vào bundle)" và "RUNTIME (`/opt/vitaminvui/env/web.env`, không nhúng)". Giá trị production: web `NEXT_PUBLIC_API_URL=https://api.vitaminvui.vn`, `NEXT_PUBLIC_SITE_URL=https://vitaminvui.vn`, `NEXT_PUBLIC_STATIC_URL=https://static.vitaminvui-media.net`, `NEXT_PUBLIC_VIDEO_HOSTS=https://video.vitaminvui.vn`, `NEXT_PUBLIC_TURNSTILE_SITE_KEY=<site key production>`, `NEXT_PUBLIC_MOMO_HOSTS=` (rỗng); runtime `API_INTERNAL_URL=http://10.231.10.1:8081`, `INTERNAL_API_TOKEN=` (giống backend), `V2_PREVIEW=` (trống). Admin `NEXT_PUBLIC_ADMIN_API_URL=https://admin-api.vitaminvui.vn`, `NEXT_PUBLIC_ADMIN_URL=https://admin.vitaminvui.vn`, `NEXT_PUBLIC_STATIC_URL=https://static.vitaminvui-media.net`, `NEXT_PUBLIC_VIDEO_UPLOAD_URL=https://video.vitaminvui.vn` (Bunny thì `https://video.bunnycdn.com`). Ghi chú dòng riêng cho staging (`*-staging.vn`, `static.vitaminvui-staging-media.net`, key Turnstile staging; `V2_PREVIEW=1` được phép ở staging). Không chú thích cuối dòng.
- `apps/web/env.ts`: mặc định `NEXT_PUBLIC_MOMO_HOSTS` thành rỗng (readiness E2, D5); `.env.example` local vẫn đặt rõ `test-payment.momo.vn`. Sửa `env.test.ts` (undefined → `[]`, `""` → `[]`). Rà `proxy.ts` web: danh sách MoMo rỗng thì CSP không sinh token rỗng/thừa dấu cách.
- Build không cần backend: `next build` không được gọi API hay đòi biến runtime thật (CI không có backend). Nếu `env.server.ts` bắt buộc biến lúc build thì sửa để chỉ kiểm lúc chạy, không truyền giá trị thật vào build.
- Cập nhật `frontend/README.md` (mục "Production image": build, chạy, biến BUILD/RUNTIME, readiness gọi `/khoa-hoc` sau khởi động).
- **Xong khi** (local):
  - (a) `docker buildx bake -f frontend/docker-bake.hcl --load web admin` với `VV_REGISTRY=vv-local IMAGE_TAG=dev VV_ENV=production` và các biến nạp từ phần BUILD của 2 mẫu `.env.production.example` → xanh; lặp lại với `VV_ENV=staging` + giá trị staging.
  - (b) Image chạy user `node`; `find /app -name '.env*'` rỗng; `grep -rE "test-payment\.momo\.vn|api\.localhost|localhost:8000|video\.localhost" /app` trong image production rỗng; `docker history --no-trunc` không có `INTERNAL_API_TOKEN`; label `vv.env` đúng.
  - (c) `docker run --rm -p 127.0.0.1:3000:3000 --env-file <web runtime env> vv-local/vitaminvui-web:production-dev`: `curl -sI http://127.0.0.1:3000/` có `Content-Security-Policy` chứa `nonce-` và `https://video.vitaminvui.vn`, KHÔNG chứa `momo` hay `localhost`; `/_next/static/...` 200; `/v2` và `/%76%32/khoa-hoc` 404 có CSP; `-e V2_PREVIEW=1` → `/v2` 200 (không build lại). Admin tương tự ở cổng 3001 (`/dang-nhap` 200, CSP `connect-src` có `NEXT_PUBLIC_ADMIN_API_URL` và `NEXT_PUBLIC_VIDEO_UPLOAD_URL`). Bản staging: CSP chỉ có host staging.
  - (d) Với stack smoke của T35-1 đang chạy (`smoke.sh` có tuỳ chọn bật web/admin): web SSR gọi `API_INTERNAL_URL` của `nginx-smoke` → `/khoa-hoc` 200 (hoặc trang "Hệ thống đang bận" có `noindex` nếu chưa seed, không 500), log `:8081` của `nginx-smoke` có request mang `X-Internal-Token`; `/_next/image?url=<STATIC_URL>/...` không trả 500 (sharp có trong standalone).
  - (e) `frontend/scripts/pnpm.sh run lint|typecheck|test|build` xanh; review; Security review (CSP, không lộ biến server-only, image không có secret); QA lặp lại (c).

### T35-3 — GitHub Actions CI + build/push image GHCR (`laravel-dev`, ~1 ngày) **[SEC]**
- `.github/workflows/ci.yml` và `.github/workflows/release-images.yml` đúng ADR-008 §8.10. Bước dài viết thành script `scripts/ci/backend-prepare.sh` (hosts, SQL tạo DB/user, `SET GLOBAL transaction_isolation`/`log_bin_trust_function_creators`, `.env` từ `.env.example` + `key:generate`, mật khẩu khớp service) để chạy lại được ngoài GitHub (giống `scripts/cloud-setup.sh`).
- Job `images` gọi đúng 2 file bake của T35-1/T35-2 với `--push`, `VV_REGISTRY=ghcr.io/ngocgiangit124`, `IMAGE_TAG=${{ github.sha }}`, cache `type=gha`; `NEXT_PUBLIC_*` lấy từ `vars` của GitHub Environment `staging` (job image staging) và `production` (`release-images.yml`, có người duyệt). Ghi vào `infra/production/README.md` danh sách `vars` cần tạo ở mỗi Environment (tên giống hệt build arg của ADR-008 §8.9).
- Bảo mật workflow: `permissions: contents: read` mặc định, `packages: write` chỉ ở job image; không `pull_request_target`; PR không bao giờ push image; action ghim commit SHA (ghi phiên bản ở chú thích); không echo biến; `concurrency` cho `main`.
- **Xong khi:**
  - (a) Local: `actionlint` (image `rhysd/actionlint`) 0 lỗi; `shellcheck` cho `scripts/ci/*.sh` 0 lỗi.
  - (b) Local: chạy `scripts/ci/backend-prepare.sh` trong một container `ubuntu:24.04` có `mysql:8.4` + `redis:7` cạnh bên (hoặc trên Claude Code on the web), rồi `vendor/bin/pest --exclude-group=race` và `--group=race` xanh; lệnh frontend giống job `frontend` xanh qua `frontend/scripts/pnpm.sh`.
  - (c) Local: lệnh bake y hệt job `images` nhưng `--load` thay `--push` chạy được (dùng lại (a) của T35-1, T35-2).
  - (d) Trên GitHub (cần PO push nhánh/mở PR): PR chạy `backend-static`, `backend-test`, `backend-race`, `frontend` xanh và KHÔNG chạy `images`; sau merge vào `main`, GHCR có 4 image (`vitaminvui-backend:<sha>`, `vitaminvui-worker-video:<sha>`, `vitaminvui-web:staging-<sha>`, `vitaminvui-admin:staging-<sha>`), package private, label `revision` = SHA; `release-images.yml` với SHA đó chờ duyệt rồi tạo `production-<sha>`. Tổng thời gian CI ghi vào báo cáo.
  - (e) Review; Security review (quyền workflow, token, nguồn biến). QA không bắt buộc ngoài (d).

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
