# VitaminVui — Backend (Laravel API)

API JSON thuần cho 2 frontend Next.js (web học sinh, admin quản trị). Không có
Blade/Livewire cho người dùng (chỉ dùng Blade cho email). Xem thiết kế đầy đủ ở
`../docs/architecture/` (README, data-model, api-contract, tasks) và
`../docs/adr/ADR-004` (nền tảng/topology/phân quyền).

## 1. Phiên bản thực tế đã cài (T01)

| Thành phần | Phiên bản |
|---|---|
| PHP | 8.3.33 |
| Laravel Framework | 13.33.0 |
| Laravel Sanctum | 4.3.3 |
| Pest | 4.7.8 |
| pestphp/pest-plugin-laravel | 4.1.0 |
| pestphp/pest-plugin-arch | 4.0.2 |
| Larastan (larastan/larastan) | 3.12.2 |
| PHPStan | 2.2.15 |
| Laravel Pint | 1.32.1 |
| MySQL | 8.4.11 (image `mysql:8.4`) |
| Redis | 7 (image `redis:7`) |
| Nginx | 1.27 (image `nginx:1.27`) |

Không có package nào trong danh sách G2 (tasks.md) gặp vấn đề tương thích với
Laravel 13 — Larastan 3.12.2 hỗ trợ tốt (không cần phương án dự phòng).

## 2. Chạy bằng Docker (bắt buộc — máy host không có PHP 8.3/Composer)

Toàn bộ lệnh composer/artisan/pest/pint/phpstan chạy **trong container**
`php` (image tự build từ `infra/php/Dockerfile`, PHP 8.3-fpm). Không cần cài
gì thêm trên host ngoài Docker.

### 2.1 Chuẩn bị

```bash
cd backend
cp .env.example .env   # đã có sẵn giá trị mặc định phù hợp Docker Compose ở dưới
```

`.env` dùng hostname **service Docker** (`DB_HOST=mysql`, `REDIS_HOST=redis`,
`MAIL_HOST=mailpit`) — không đổi nếu chạy qua `infra/docker-compose.yml`.

### 2.2 Khởi động hạ tầng

```bash
cd infra
VV_UID=$(id -u) VV_GID=$(id -g) docker compose up -d --build
```

`VV_UID`/`VV_GID` để container `php` chạy đúng UID/GID của bạn trên host (WSL2)
— file tạo ra từ container không bị root hoá. Có thể xuất 2 biến này vào
`~/.bashrc`/`infra/.env` để khỏi gõ lại.

Service khởi động: `php` (php-fpm), `nginx` (cổng host **8000** → container
`80`, đúng `http://api.localhost:8000` / `http://admin-api.localhost:8000`
theo ADR-004 §2.1), `mysql` (cổng 3306, DB `vitaminvui` + `vitaminvui_testing`),
`redis` (cổng 6379), `mailpit` (UI `http://localhost:8025`), `queue`
(`queue:work --queue=default,exports`), `scheduler` (`schedule:work`).

Frontend (container riêng của `nextjs-dev`, **không cấu hình ở đây**) gọi
backend qua `http://host.docker.internal:8000` (từ container) hoặc
`http://api.localhost:8000` / `http://admin-api.localhost:8000` (từ trình
duyệt trên host) — `host.docker.internal` phân giải sẵn với Docker Desktop
(WSL2). Ứng dụng admin Next.js (`admin.localhost:3001`) do `nextjs-dev` tự
phục vụ bằng dev server của Next.js, **không** đi qua Nginx của backend.

### 2.3 Trình duyệt phân giải `*.localhost`

Chrome/Firefox tự phân giải `*.localhost` về `127.0.0.1` — không cần sửa
`/etc/hosts`. **`curl` trên một số máy/WSL không tự phân giải** `*.localhost`
(khác `getent hosts`) — dùng `--resolve` khi test bằng curl, ví dụ:

```bash
curl -i --resolve api.localhost:8000:127.0.0.1 http://api.localhost:8000/api/v1/health
curl -i --resolve admin-api.localhost:8000:127.0.0.1 http://admin-api.localhost:8000/api/v1/csrf-token \
  -H "Origin: http://admin.localhost:3001"
```

### 2.4 Khởi tạo ứng dụng lần đầu

```bash
cd infra
docker compose exec php php artisan key:generate      # nếu .env chưa có APP_KEY
docker compose exec php php artisan migrate --force
docker compose exec php php artisan db:seed            # chỉ tạo dữ liệu demo khi APP_ENV=local
```

Seeder demo (`admin@vitaminvui.test`, `teacher@vitaminvui.test`,
`student@vitaminvui.test`) **chỉ chạy ở `APP_ENV=local`** (S15 — không tạo
admin mặc định ở production). Tạo tài khoản staff thật bằng:

```bash
docker compose exec php php artisan staff:create qlt@vitaminvui.vn --role=quan_ly_trang
# In ra MỘT LẦN: mật khẩu ngẫu nhiên 20 ký tự, must_change_password=true
docker compose exec php php artisan staff:lock qlt@vitaminvui.vn
docker compose exec php php artisan staff:unlock qlt@vitaminvui.vn
```

### 2.5 Lệnh hay dùng

```bash
# Chạy artisan bất kỳ
docker compose exec php php artisan <command>

# Test (Pest) — dùng DB riêng `vitaminvui_testing`, xem §3
docker compose exec php ./vendor/bin/pest

# Định dạng code (Pint)
docker compose exec php ./vendor/bin/pint          # tự sửa
docker compose exec php ./vendor/bin/pint --test   # chỉ kiểm tra (CI)

# Phân tích tĩnh (Larastan/PHPStan level 6)
docker compose exec php ./vendor/bin/phpstan analyse

# Cả 3 bước trên theo đúng thứ tự CI
docker compose exec php composer ci
```

Nếu gặp lỗi `Writing to directory /var/www/.config/psysh is not allowed`
(dùng `tinker`) hoặc muốn chắc chắn có `$HOME` ghi được, thêm `-e HOME=/tmp`:

```bash
docker compose exec -e HOME=/tmp php php artisan tinker
```

### 2.6 Dừng / dọn

```bash
docker compose down          # giữ volume (dữ liệu DB/Redis)
docker compose down -v       # xoá luôn volume (mất dữ liệu local — chỉ khi chắc chắn)
```

## 3. Database test riêng biệt (quan trọng)

`infra/mysql-init/01-testing-db.sql` tạo sẵn database **`vitaminvui_testing`**
(tách khỏi `vitaminvui` — DB dev thật) khi container `mysql` khởi tạo lần đầu.
`phpunit.xml` trỏ `DB_DATABASE=vitaminvui_testing` cho Pest.

**Lưu ý kỹ thuật quan trọng (đã tốn thời gian debug ở T02, đừng bỏ):**
container `php`/`queue`/`scheduler` dùng `env_file: ../backend/.env`, tức là
mọi biến trong `.env` (kể cả `APP_ENV=local`, `DB_DATABASE=vitaminvui`) đã là
**biến môi trường OS thật** của tiến trình PHP, tồn tại ở cả `$_ENV` lẫn
`$_SERVER` **trước khi** PHPUnit/Pest chạy. Thẻ `<env>` mặc định của PHPUnit
**không ghi đè** biến đã tồn tại trừ khi có `force="true"`, và ngay cả vậy vẫn
chỉ ghi `$_ENV`/`putenv()` — Laravel `env()` lại ưu tiên đọc `$_SERVER`. Vì
vậy `phpunit.xml` phải khai **cả `<env force="true">` lẫn `<server
force="true">`** cho mọi biến quan trọng (`APP_ENV`, `DB_*`...), nếu không
test sẽ **âm thầm chạy nhầm vào DB dev thật** (`vitaminvui`) thay vì
`vitaminvui_testing` — không báo lỗi, chỉ sai môi trường. Đã xác minh bằng
kiểm chứng thực tế (dump `$_ENV`/`$_SERVER`/`config('app.env')`) trước khi vá.
Nếu sau này thêm biến `.env` mới cần khác giá trị lúc test, nhớ thêm **cả
2 thẻ** vào `phpunit.xml`.

RefreshDatabase (Pest, bật ở `tests/Pest.php`) bọc mỗi test Feature trong 1
transaction rồi rollback — DB test không tích luỹ dữ liệu giữa các lần chạy.

## 4. Cấu trúc route & host (ADR-004 §2.1)

| Host local (cổng **8000**, xem §2.2) | Dùng cho | File route |
|---|---|---|
| `api.localhost:8000` | Học sinh + webhook | `routes/api.php` (tự bọc `Route::domain(config('app.api_host'))->prefix('v1')`) |
| `admin-api.localhost:8000` | Quản trị | `routes/admin.php` (tự bọc `Route::domain(config('app.admin_api_host'))->prefix('api/v1')->middleware('admin.origin')`) |
| `video.localhost:8000` | VideoLab (chưa có route — thêm ở T12) | — |

Ghi chú: `config('app.api_host')`/`config('app.admin_api_host')` cố ý **không
có cổng** (chỉ `api.localhost`) — `Route::domain()`/`Request::getHost()` khớp
theo hostname, tự bỏ qua cổng; `TrustHosts` tuỳ biến (App\Http\Middleware\
TrustHosts) cũng khớp theo hostname nên không cần đổi khi đổi cổng Nginx.

`bootstrap/app.php` nạp `routes/admin.php` qua closure `then:` của
`withRouting()`. **Thứ tự middleware toàn cục** (`TrustHosts` →
`ConfigureHostContext` → `AssignRequestId` → `SecurityHeaders` → mặc định
Laravel) phụ thuộc **thứ tự gọi `prepend()`** (gọi sau = chạy trước) — đọc kỹ
comment trong `bootstrap/app.php` trước khi sửa, có test bảo vệ
(`tests/Feature/T01`, `tests/Feature/T02/RouteMiddlewareGroupsTest.php`).

`App\Http\Middleware\TrustHosts` (không phải class gốc của Laravel) ép luôn
bật kiểm tra host kể cả ở `local`/khi chạy test — mặc định của Laravel bỏ qua
TrustHosts trong 2 trường hợp này, không phù hợp vì 2 host là ranh giới bảo
mật thật của dự án (ADR-004 §2.2).

## 5. Biến môi trường (`.env.example`)

Xem đầy đủ trong `.env.example` (đã điền giá trị mặc định hợp lý cho Docker
Compose). Nhóm chính:

- **Topology:** `APP_API_HOST`, `APP_ADMIN_API_HOST`, `FRONTEND_URL`,
  `ADMIN_URL`, `STATIC_URL`.
- **Phiên & proxy:** `SANCTUM_STATEFUL_DOMAINS`, `SESSION_*`,
  `TRUSTED_PROXIES` (danh sách IP cụ thể — **không bao giờ `*`**).
- **DB:** `DB_*` (MySQL 8.4, collation `utf8mb4_0900_ai_ci`,
  `READ COMMITTED`).
- **Redis:** `REDIS_DB_SESSION=1`, `REDIS_CACHE_DB=2`, `REDIS_QUEUE_DB=3`,
  `REDIS_LIMITER_DB=4` — tách DB theo mục đích.
- **Thanh toán:** `PAYMENT_GATEWAYS=fake` (local; **cấm** `fake`/endpoint
  sandbox ở production — `AppServiceProvider::guardProductionPayments()`).
- **OTP/Captcha:** `AUTH_OTP_CHANNELS=email`, `CAPTCHA_DRIVER=fake`.
- **Dữ liệu cá nhân:** `PRIVACY_POLICY_VERSION`, `PRIVACY_PARENT_CONSENT_AGE`.
- **Feature flags:** `FEATURE_*` — đọc qua `config('features.*')`, `/api/v1/config/public`
  chỉ lộ ra **allowlist khoá tường minh** (không trả nguyên config).

## 6. Việc đã làm ở T01 và T02 (tham khảo nhanh — chi tiết xem báo cáo bàn giao)

- **T01:** khởi tạo Laravel 13 API-only (bỏ Vite/Blade UI mặc định), Sanctum,
  Pest, Pint, Larastan; `infra/` (Docker Compose: php/nginx/mysql/redis/
  mailpit/queue/scheduler); `config/database.php` (READ COMMITTED,
  `utf8mb4_0900_ai_ci`); Redis tách 4 DB; 2 host + middleware chuẩn
  (`ConfigureHostContext`, `EnsureAdminOrigin`, `TrustHosts` riêng,
  `AssignRequestId`, `SecurityHeaders`, `NoStoreForAuthenticated`); envelope
  lỗi JSON thống nhất (`App\Support\ApiExceptionRenderer`); `RateLimiter::for()`
  khung cho toàn bộ limiter ở api-contract §1.6; `JsonResource::withoutWrapping()`
  (api-contract §1.4 — 1 object trả JSON phẳng, danh sách vẫn `{data, meta,
  links}` qua paginator); Nginx publish cổng host **8000**; `composer ci`.
- **T02:** migration `users` (đủ cột data-model §3.1 + CHECK
  `chk_users_grade_level`) và `audit_logs`; enum `UserRole/UserStatus/
  ParentConsentStatus/OtpPurpose/ConsentType`; model `User` (`$fillable`
  tường minh, cast `encrypted` cho `parent_phone/parent_email`, helper
  `isAdmin/isStaff/isTeacher/isStudent`) và `AuditLog` (bất biến); middleware
  `role`, `account.active` + khung `student.single_session`,
  `staff.mfa_passed`, `staff.password_fresh` (pass-through, hiện thực ở
  T05/T28); Gate `manage-system`, `access-admin-area`; `App\Services\Audit\
  AuditLogger` (lọc PII/secret khỏi `changes`); lệnh `staff:create|lock|
  unlock`; seeder demo chỉ chạy ở `local`; factory `User` (`admin()`,
  `pageManager()`, `teacher()`, `student()`, `verified()`, `minor()`); test
  kiến trúc (`tests/Arch/*`, `tests/Feature/T02/RouteMiddlewareGroupsTest.php`).

## 7. Việc CHƯA làm (để lại cho task sau, không thuộc phạm vi T01/T02)

- `config/video.php`, `config/captcha.php` riêng — captcha site key hiện đọc
  qua `config('services.turnstile.*')`.
- `StudentSessionService`, MFA staff, idle timeout thật (T05, T28) — hiện là
  middleware pass-through có alias sẵn.
- Mọi route nghiệp vụ (auth, catalog, cart, checkout, learn, admin...) —
  chỉ có `/csrf-token`, `/config/public`, `/health` ở T01.

## 8. Test bảo mật/kiến trúc đáng chú ý khi review

- `tests/Feature/T01/*`: host trust, CORS theo host, cookie SameSite
  Lax/Strict theo host, envelope lỗi, TrustedProxy/X-Forwarded-For, collation
  utf8mb4_0900_ai_ci, isolation READ-COMMITTED.
- `tests/Feature/T02/*`: mass assignment (S17), mã hoá `parent_phone/
  parent_email` (S7), AuditLog bất biến, `staff:create` chạy được ở
  production nhưng seeder demo thì không (S15), Gate/middleware phân quyền.
- `tests/Arch/*`: cấm `$guarded = []` trên mọi Model; cấm `$request->all()`
  trong Controllers.
