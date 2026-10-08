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
cp .env.example .env   # sửa DB_PASSWORD/REDIS_PASSWORD — xem M6 bên dưới

cd ../infra
cp .env.example .env   # BẮT BUỘC (M6 — review bảo mật T01/T02): không còn giá
                        # trị mặc định "secret" trong docker-compose.yml
```

`backend/.env` dùng hostname **service Docker** (`DB_HOST=mysql`,
`REDIS_HOST=redis`, `MAIL_HOST=mailpit`) — không đổi nếu chạy qua
`infra/docker-compose.yml`.

**M6 (review bảo mật T01/T02) — 2 file `.env` phải khớp mật khẩu:**
`infra/.env` (`VV_DB_PASSWORD`, `VV_REDIS_PASSWORD`) cấu hình mật khẩu THẬT
cho container MySQL/Redis; `backend/.env` (`DB_PASSWORD`, `REDIS_PASSWORD`)
là mật khẩu Laravel dùng để KẾT NỐI tới 2 service đó. Đây là **2 file khác
nhau mô tả cùng 1 mật khẩu** — đặt sai lệch thì `php` không kết nối được
DB/Redis. `docker-compose.yml` không còn fallback `:-secret`: thiếu
`infra/.env` hoặc thiếu khoá sẽ báo lỗi rõ ràng khi `docker compose up`, thay
vì âm thầm chạy với mật khẩu yếu cố định trong file được commit.

### 2.2 Khởi động hạ tầng

```bash
cd infra
VV_UID=$(id -u) VV_GID=$(id -g) docker compose up -d --build
```

`VV_UID`/`VV_GID` để container `php` chạy đúng UID/GID của bạn trên host (WSL2)
— file tạo ra từ container không bị root hoá. Có thể đặt 2 biến này thẳng
trong `infra/.env` để khỏi gõ lại (xem `infra/.env.example`).

**M6/N3 — MySQL/Redis/Mailpit/Nginx đều chỉ bind `127.0.0.1`** (không mở ra
mạng LAN/Wi-Fi, kể cả khi `APP_DEBUG=true` ở local): `docker compose ps` phải
luôn hiện `127.0.0.1:3306->3306`, `127.0.0.1:6379->6379`,
`127.0.0.1:8025->8025`/`127.0.0.1:1025->1025`, **và `127.0.0.1:8000->80`**.

Service khởi động: `php` (php-fpm), `nginx` (cổng host **8000**, chỉ
`127.0.0.1` → container `80`, đúng `http://api.localhost:8000` /
`http://admin-api.localhost:8000` theo ADR-004 §2.1), `mysql` (cổng 3306, DB
`vitaminvui` + `vitaminvui_testing`), `redis` (cổng 6379), `mailpit` (UI
`http://localhost:8025`), `queue` (`queue:work --queue=default,exports`),
`scheduler` (`schedule:work`).

Frontend (container riêng của `nextjs-dev`, **không cấu hình ở đây**) gọi
backend qua `http://host.docker.internal:8000` (từ container) hoặc
`http://api.localhost:8000` / `http://admin-api.localhost:8000` (từ trình
duyệt trên host). **Đã kiểm chứng thật** (N3, không chỉ suy đoán): bind
Nginx vào `127.0.0.1:8000` rồi gọi `http://host.docker.internal:8000` từ một
container Docker độc lập khác (bridge network riêng, mô phỏng đúng container
`web`/`admin` của `frontend/docker-compose.yml`) — vẫn trả `200`, kể cả không
khai `--add-host` tường minh (Docker Desktop tự cấp DNS này). Vì vậy không
cần mở `0.0.0.0` cho Nginx. Ứng dụng admin Next.js (`admin-api.localhost:3001`)
do `nextjs-dev` tự phục vụ bằng dev server của Next.js, **không** đi qua
Nginx của backend.

### 2.3 Trình duyệt phân giải `*.localhost`

Chrome/Firefox tự phân giải `*.localhost` về `127.0.0.1` — không cần sửa
`/etc/hosts`. **`curl` trên một số máy/WSL không tự phân giải** `*.localhost`
(khác `getent hosts`) — dùng `--resolve` khi test bằng curl, ví dụ:

```bash
curl -i --resolve api.localhost:8000:127.0.0.1 http://api.localhost:8000/api/v1/health
curl -i --resolve admin-api.localhost:8000:127.0.0.1 http://admin-api.localhost:8000/api/v1/csrf-token \
  -H "Origin: http://admin-api.localhost:3001"
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

**BUG-2 (QA T01/T02) — Redis rate-limiter cũng phải cách ly:**
`RateLimiter::for()`/middleware `throttle` mặc định dùng
`config('cache.limiter')` = store `redis-limiter` (Redis THẬT, `REDIS_LIMITER_DB`)
— khác `DB_DATABASE`, biến này **không** được cách ly riêng cho `testing`
trước đây, khiến bộ đếm rate-limit dùng CHUNG giữa test và dev/production
(test chạy 2 lần liên tiếp có thể bị 429 giả ở request đáng lẽ phải qua).
`phpunit.xml` giờ ép `CACHE_LIMITER=array` (`force="true"` trên cả `<env>` lẫn
`<server>`, cùng lý do ở trên) — store trong tiến trình, mỗi test Pest có
Application riêng nên không rò trạng thái giữa các lần chạy, không cần Redis
khi test.

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
- **Dữ liệu cá nhân:** `PRIVACY_POLICY_VERSION` (bản tạm `2026-10-tam`), `PRIVACY_PARENT_CONTACT_SUGGEST_AGE`, `PRIVACY_PARENT_NOTICE_DAILY_CAP`, `PRIVACY_NOTICE_TOKEN_KEY` (tuỳ chọn). Cờ `FEATURE_PARENT_NOTICES` (mặc định `true`): thư thông báo phụ huynh (ADR-006), mail thật phải hoạt động trước go-live; tắt = công tắc khẩn.
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

- `config/video.php` riêng — chưa cần tới khi chưa có T11+.
- `StudentSessionService`, MFA staff, idle timeout thật (T05, T28) — hiện là
  middleware pass-through có alias sẵn.
- Mọi route nghiệp vụ (auth, catalog, cart, checkout, learn, admin...) —
  chỉ có `/csrf-token`, `/config/public`, `/health` ở T01.
- **M5 (review bảo mật):** quyền MySQL của user ứng dụng trên bảng
  `audit_logs` vẫn có UPDATE/DELETE ở tầng DB (chỉ bị chặn ở tầng
  Eloquent/app — xem `App\Models\AuditLog`). TODO(DBA, task sau): giới hạn
  còn INSERT/SELECT hoặc trigger `BEFORE UPDATE/DELETE`.

## 8. Test bảo mật/kiến trúc đáng chú ý khi review

- `tests/Feature/T01/*`: host trust, CORS theo host, cookie SameSite
  Lax/Strict theo host, envelope lỗi, TrustedProxy/X-Forwarded-For, collation
  utf8mb4_0900_ai_ci, isolation READ-COMMITTED, thứ tự middleware toàn cục
  (`TrustedProxyHostSpoofTest`), boot guard production (`ProductionConfigGuardTest`),
  endpoint công khai không tạo session (`PublicEndpointNoSessionTest`).
- `tests/Feature/T02/*`: mass assignment (S17), mã hoá `parent_phone/
  parent_email` (S7), AuditLog bất biến ở nhiều lớp (instance method + sự
  kiện model + query builder), `staff:create` chạy được ở production nhưng
  seeder demo thì không (S15), Gate/middleware phân quyền, route admin-api
  bắt buộc `admin.origin` + `auth:sanctum` + `role`/`can:access-admin-area`
  (`RouteMiddlewareGroupsTest`).
- `tests/Arch/*`: cấm `$guarded = []` trên mọi Model; cấm `$request->all()`
  trong Controllers.

## 9. Đã sửa theo review bảo mật + QA (2026-09-28)

Chi tiết đầy đủ: `docs/security/review-T01-T02.md`, `docs/qa/T01-T02.md`.
Tóm tắt các điểm đã xử lý trong mã nguồn:

- **H1 (High):** `TrustProxies` chạy TRƯỚC `TrustHosts`/`ConfigureHostContext`
  trong middleware toàn cục; chỉ tin `X-Forwarded-For/Port/Proto` (không tin
  `X-Forwarded-Host/Prefix`); host đã chọn truyền qua
  `$request->attributes` (`ConfigureHostContext::HOST_ATTRIBUTE`).
- **M1:** test kiến trúc bắt buộc `admin.origin` + `auth:sanctum` +
  `role:.../can:access-admin-area` cho mọi route có thể gọi trên admin-api
  (allowlist theo TÊN route, không dùng `str_contains` trên URI); có test
  chứng minh logic bắt được route giả thiếu quyền.
- **M2:** Nginx chặn PATH_INFO qua `/index.php/`, webhook giới hạn 16 KB ngay
  cả khi `chunked`, chỉ đúng `/index.php` được chạy PHP.
- **M3:** `/config/public`, `/health` không còn khởi tạo session/Set-Cookie
  (`withoutMiddleware(EnsureFrontendRequestsAreStateful::class)`); `csrf-token`
  có `throttle:csrf` riêng (30/phút/IP).
- **M4:** `session.secure` tính theo `isProduction()`/`isSecure()` trong
  `ConfigureHostContext`; `App\Support\ProductionConfigGuard` chặn boot khi
  `APP_DEBUG`, `session.secure`, captcha fake, stateful domains chứa
  localhost, `TRUSTED_PROXIES=*`, gateway/endpoint MoMo sai (allowlist).
- **M5:** `AuditLog` bất biến ở nhiều lớp (instance `update()/delete()`/
  `saveQuietly()`, sự kiện `saving`/`updating`/`deleting`,
  `ImmutableAuditLogBuilder` chặn `update/delete/increment/decrement/
  incrementEach/decrementEach/touch/upsert/forceDelete/truncate` qua query
  builder). Không chặn `DB::table()`/quyền MySQL trực tiếp — TODO(DBA).
- **M6:** `infra/docker-compose.yml` bind MySQL/Redis/Mailpit vào `127.0.0.1`,
  bỏ mật khẩu mặc định `secret` (bắt buộc `infra/.env`).
- **BUG-1:** header bảo mật chỉ còn đặt ở Laravel (`SecurityHeaders`), bỏ
  `add_header` trùng ở Nginx.
- **BUG-2:** `CACHE_LIMITER=array` trong `phpunit.xml` — cách ly rate limiter
  khỏi Redis dev thật khi chạy test.
- **L1:** `filesystems.disks.local.serve = false` — tắt hẳn route
  `storage.local`/`storage.local.upload`.
- **L3:** `server_tokens off`, `server { listen 80 default_server; return 444; }`
  cho host lạ.
- **L4:** `AuditLogger` lọc thêm `secret/otp/address/*_key`; ghi
  `actor_role=cli` khi chạy từ console không có actor đăng nhập; `staff:create`
  ghi audit TRONG cùng transaction với tạo user; `staff:lock/unlock` ghi
  `changes` có giá trị `{from, to}` của `status`.
- **L6:** `ApiExceptionRenderer` giữ lại header `Retry-After`/`Allow` của
  `HttpExceptionInterface`; bỏ `HasApiTokens` khỏi `User` (không phát hành
  token — S24), có test kiến trúc cấm dùng lại trait/`createToken(`.
- **N1 (hồi quy từ L4):** `AuditLogger` đưa `password/secret/otp/token` về so
  khớp "chứa chuỗi" (không còn khớp chính xác — làm lọt `new_password`,
  `otp_code`, `verification_code`...); thêm hậu tố `_code` có allowlist
  `coupon_code`/`referral_code_used`.
- **N3:** Nginx cũng chỉ bind `127.0.0.1:8000` (đã kiểm chứng thật
  `host.docker.internal` vẫn gọi được từ container Docker Desktop độc lập).

## 10. Vận hành queue/scheduler (T26)

**Queue.** Redis, 2 queue: `default` (mail OTP/duyệt/thiết bị mới, `SyncVideoAssetStatusJob`) và `exports` (xuất file
lớn, chưa có job). Mọi job/Mailable khai báo `$tries`, `$timeout`, `$backoff`; `$timeout` < `REDIS_QUEUE_RETRY_AFTER`
(90s). Hiện tại: mail `tries=3, timeout=30, backoff=[10,60]`; sync video `tries=3, timeout=60, backoff=[60,300]`.
Tên queue/ngưỡng: `config/ops.php`.

**Lịch** (đăng ký ở `OperationsServiceProvider`, tất cả `withoutOverlapping()->onOneServer()`): `counters:recount` 03:30,
`otp:prune` 03:00, `queue:prune-failed` 03:10 (giữ 720 giờ), `videos:check-stuck` 15 phút, `videos:prune-orphans` mỗi giờ,
`queue:monitor` 5 phút (log warning khi tồn đọng), `ops:health --log` 5 phút (log error khi có vấn đề).
`onOneServer` cần cache Redis dùng chung. Xem: `php artisan schedule:list`.

**Health check.** `php artisan ops:health [--json]` (exit 1 nếu worker/scheduler im > 120s/180s, `failed_jobs` > 10, queue
> 500 job). Worker ghi nhịp vào cache mỗi 15s, scheduler mỗi phút. Gắn vào monitoring/cron hoặc healthcheck container.
Cảnh báo: chuyển log level `error` ("Cảnh báo vận hành…", "Job queue thất bại") sang kênh cảnh báo (Slack/Sentry...).

**failed_jobs.** `php artisan queue:failed` (xem), `queue:retry <id|all>`, `queue:forget <id>`, `queue:flush`.
Payload mail OTP/thiết bị mới được mã hoá (S21). Sau khi sửa nguyên nhân mới retry; job không idempotent thì forget.

**Production (Supervisor)** — `/etc/supervisor/conf.d/vitaminvui.conf`:

```ini
[program:vv-queue]
command=php /var/www/backend/artisan queue:work redis --queue=default,exports --tries=3 --timeout=60 --max-time=3600 --sleep=1
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopasgroup=true
stopwaitsecs=90
redirect_stderr=true
stdout_logfile=/var/log/vitaminvui/queue.log

[program:vv-scheduler]
command=php /var/www/backend/artisan schedule:work
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/vitaminvui/scheduler.log
```

(Hoặc cron `* * * * * php artisan schedule:run` thay cho `schedule:work`; chỉ chọn một.) Mỗi lần deploy chạy
`php artisan queue:restart` để worker nạp code mới. Nếu thêm worker riêng cho `exports`, tăng `--timeout` cho worker đó
và `REDIS_QUEUE_RETRY_AFTER` lớn hơn nó. Tunnel nhận IPN MoMo ở local: xem tài liệu T17/T20.

## 11. Throttle `catalog` cho Next.js SSR

SSR gọi API từ 1 IP nên cần phân biệt khách. Đặt `INTERNAL_API_TOKEN` (≥ 32 ký tự, `openssl rand -hex 32`, cùng giá trị ở
env của Next.js server). Request SSR gửi `X-Internal-Token: <token>` và `X-Client-IP: <IP khách thật>`; token đúng → limiter
theo IP khách (120/phút) + trần chung 6000/phút. Token trống/sai → theo IP kết nối như trước. Nginx phải **xoá** 2 header này
khỏi request từ internet (`proxy_set_header X-Internal-Token ""` ở vhost public). Không đưa token vào code chạy trên trình duyệt.

Ở production, SSR Next.js phải gọi Laravel qua đường nội bộ (mạng riêng/listener nội bộ) không đi qua server block công khai:
block công khai (`infra/nginx/snippets/vv-common.conf`) luôn xoá `X-Internal-Token` và `X-Client-IP` khỏi request. Đặt
`INTERNAL_API_REQUIRED=true` khi SSR đã gửi header để production từ chối khởi động nếu quên token (token rỗng ở production
chỉ ghi log warning khi boot).

## 12. VideoLab (T12)

- Module `app/VideoLab`, route `routes/videolab.php` (host `VIDEOLAB_HOST`), bảng `vl_videos`, queue `video` (connection `redis_video`, `retry_after` 3900 > timeout job 3600).
- Worker transcode chạy ở container `worker-video` (xem `infra/docker-compose.yml`): non-root, rootfs chỉ đọc, `.env` bị `/dev/null` đè, chỉ network `internal`. **Env của worker phải khớp app:** `APP_NAME` và `REDIS_PREFIX` (app dùng `slug(APP_NAME)-database-` = `vitaminvui-database-`). Lệch prefix thì worker không thấy queue `video` và webhook đẩy vào hàng đợi không ai đọc. Đổi `APP_NAME` ở `.env` thì đặt `VV_REDIS_PREFIX` ở `infra/.env`.
- ffmpeg/ffprobe chạy với env sạch (chỉ `PATH`, `HOME=/tmp`), không thấy mật khẩu DB/Redis.
- Khoá `VIDEOLAB_API_KEY/TOKEN_KEY/WEBHOOK_SECRET`: chỉ local/testing tự suy từ `APP_KEY`; môi trường khác (staging, production) bắt buộc đặt riêng ≥ 32 ký tự, thiếu thì app không khởi động.
- Production phát video: bật `VIDEOLAB_ACCEL_REDIRECT=true` (Nginx `location /_protected_hls/ { internal; }`), thêm `limit_req` Nginx cho `/videolab/cdn/` (route Laravel chỉ có `throttle:1200,1` theo IP); allow-list `/videolab/library/` dùng IP app server cụ thể + `real_ip` (local đang allow cả dải private).
