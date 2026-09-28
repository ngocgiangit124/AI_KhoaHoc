# SECURITY: T01 + T02 (review code đã hiện thực) | 2026-09-28

**Phạm vi:** backend `backend/` (T01 khởi tạo Laravel 13 + 2 host API; T02 users/audit_logs/vai trò/middleware/`staff:*`), `infra/` (docker-compose, Nginx, PHP). **Không** review `frontend/` lần này.
**Chuẩn đối chiếu:** ADR-004 (§2.1–2.5, §4, §6), api-contract §1, tasks.md T01/T02, báo cáo thiết kế `docs/security/audit-2026-09-25.md`.
**Xác nhận lại:** R1 (`/sanctum/csrf-cookie`), R3 (`/csrf-token` thiếu Origin), R5 (SameSite).

**Kết luận (cập nhật 2026-09-28, sau khi xác minh lại):** **PASS có điều kiện**. Xem mục "Xác minh lại" ở cuối. Kết luận lần review đầu: **FAIL**. Có 1 phát hiện High (H1) chưa sửa. Lỗi này tiềm ẩn: chỉ phát sinh khi đặt `TRUSTED_PROXIES` (staging/production). Ở local nó chưa khai thác được và hiện chưa có route quản trị nào, nhưng nó phá đúng ranh giới host mà S6 dựa vào. Sửa H1 và M2 (vài dòng cấu hình) thì chuyển thành **PASS có điều kiện**, điều kiện là các mục Medium còn lại có lịch sửa trước staging (T31).

Nền tảng làm tốt:
- TrustHosts luôn bật; host lạ bị chặn 400.
- Cookie host-only, tách tên theo host; admin dùng `SameSite=Strict` + `expire_on_close`.
- CORS đúng 1 origin cho mỗi host.
- `EnsureAdminOrigin` so khớp chính xác và fail-closed.
- Envelope lỗi không lộ stack trace; `dontFlash` đủ khoá.
- `$fillable` chặt, có test kiến trúc; `parent_*` dùng cast `encrypted`.
- `staff:create` dùng `Str::password` (CSPRNG); seeder demo chỉ chạy ở local.
- `composer audit` sạch.

## Môi trường & công cụ

| Mục | Kết quả |
|---|---|
| Phiên bản | Laravel **13.33.0**, PHP **8.3.35**, MySQL **8.4.11**, Nginx 1.27.5, Sanctum 4.3.3 |
| `composer audit` | No security vulnerability advisories found |
| `composer ci` | Pint OK, PHPStan "No errors", Pest **72 passed (210 assertions)**. Lần chạy đầu ngay sau khi khởi động lại stack: 71 test lỗi `QueryException` (DB test chưa sẵn sàng); chạy lại thì xanh. Không phải lỗi bảo mật; nên cho CI chờ healthcheck MySQL. |
| `route:list` | `api.localhost`: `csrf-token`, `config/public`, `health` · `admin-api.localhost`: `csrf-token` (+`EnsureAdminOrigin`) · **không có domain**: `up`, `GET storage/{path}`, **`PUT storage/{path}`** |
| Git | `.env` không được track; `.env.example` chỉ có giá trị giữ chỗ cho local (`secret`), `APP_KEY` rỗng |

### Kiểm tra HTTP thật (curl vào `127.0.0.1:8000`, stack docker local)

| Kiểm tra | Kết quả | Đánh giá |
|---|---|---|
| `Host: evil.test` | 400 | ✅ |
| `Host: admin-api.localhost.` (dấu chấm cuối) | 400 | ✅ |
| `GET /api/v1/config/public`: host api / host admin-api | 200 / 404 | ✅ |
| **R1** `GET /sanctum/csrf-cookie`: api / admin-api (cả khi Origin đúng) | 404 / 404; `route:list` không còn `sanctum.csrf-cookie` | ✅ đã sửa |
| admin `csrf-token`: không Origin / Origin lạ / `Origin: null` / Referer `http://admin.localhost:3001@evil.test/` | 403 `ORIGIN_NOT_ALLOWED` cả 4 | ✅ |
| admin `csrf-token` với Origin đúng / chỉ Referer đúng (GET) | 200 / 200 | ✅ đúng thiết kế |
| **R3** api `csrf-token` không Origin | 400 `ORIGIN_NOT_ALLOWED` | ✅ đã sửa |
| **R5** Cookie admin | `vv_admin_session; path=/; httponly; samesite=strict` (không có `Domain`, không có `Expires`) | ✅ |
| **R5** Cookie api | `vv_session; Max-Age=604800; httponly; samesite=lax` (không có `Domain`) | ✅ (`Secure` bị tắt ở local, xem M4) |
| Preflight chéo (web → admin-api, admin → api) | ACAO luôn là origin của đúng host; `X-Forwarded-Host` không nằm trong `allowed_headers` | ✅ |
| `X-Forwarded-For`/`X-Forwarded-Proto`/`X-Forwarded-Host` giả khi `TRUSTED_PROXIES` rỗng | bị bỏ qua (không có HSTS, vẫn route theo `Host`) | ✅ ở local, xem **H1** khi có proxy |
| `/.env`, `/.git/config`, `/storage/x`, `/%2e%65nv` | 403 (Nginx) | ✅ |
| **`/index.php/storage/x`**, `PUT /index.php/storage/p?upload=1` | 403 **có `X-Request-Id`** → request đã tới Laravel `ServeFile`/`ReceiveFile` | ❌ vượt chặn Nginx (M2, L1) |
| Webhook 100 KB có `Content-Length` | 413 | ✅ |
| **Webhook 100 KB `Transfer-Encoding: chunked`** / **qua `/index.php/api/v1/webhooks/...`** | 404 (body đã tới Laravel) | ❌ vượt giới hạn 16 KB (M2) |
| `GET /api/v1/config/public` kèm `Origin: http://localhost:3000` | `Cache-Control: max-age=60, public` **kèm** `Set-Cookie: vv_session`, `XSRF-TOKEN` | ❌ (M3) |
| Header bảo mật | `nosniff`, `Referrer-Policy`, `X-Frame-Options: DENY` có đủ nhưng **bị lặp 2 lần** (Nginx + Laravel); `Server: nginx/1.27.5` | ⚠️ (L3) |
| `/up` trên admin-api | 200, HTML tải script từ `cdn.jsdelivr.net` | ⚠️ (L1) |

**Mô phỏng production cho H1** (chạy trong bộ nhớ bằng `php` trong container: đặt `TRUSTED_PROXIES=10.9.9.9`, `SESSION_DRIVER=array`, không ghi DB, không sửa file):

| Request (REMOTE_ADDR = proxy tin cậy) | Kết quả |
|---|---|
| `Host: api.localhost`, `X-Forwarded-Host: admin-api.localhost`, `Origin: evil` | 403 `ORIGIN_NOT_ALLOWED` → **route admin đã được dispatch**, nhưng CORS = `http://localhost:3000` (cấu hình của host api) |
| như trên, `Origin: http://admin.localhost:3001` | 200, cookie phiên tên **`vv_session`** (cookie của học sinh) trên route admin |
| `Host: api.localhost`, không có XFH | 200, `vv_session` Lax (đúng) |

---

## Phát hiện

### H1 [High] Bộ định tuyến chọn host theo `X-Forwarded-Host`, còn cookie/CORS lại chọn theo `Host` gốc — phá ranh giới 2 host (S6) khi có proxy tin cậy — OWASP A01/A05
- **Vị trí:**
  - `backend/bootstrap/app.php:49-58`: `ConfigureHostContext` và `TrustHosts` được prepend nên chạy **trước** `TrustProxies`.
  - `backend/bootstrap/app.php:67`: `trustProxies(at: …)` không giới hạn `headers`, nên mặc định tin cả `X-Forwarded-Host` và `X-Forwarded-Prefix`.
  - `backend/app/Http/Middleware/ConfigureHostContext.php:24`: đọc `getHost()` lúc proxy chưa được tin.
  - `backend/app/Http/Middleware/EncryptCookies.php:25`: đọc lại `getHost()` sau khi proxy đã được tin.
- **Mô tả & tác động:**
  - Khi `TRUSTED_PROXIES` có IP LB/Next.js (bắt buộc ở production theo ADR-004 §2.4), `ConfigureHostContext` chọn tên cookie, SameSite và CORS theo header `Host`. Sau đó `TrustProxies` bật, và router cùng `EncryptCookies` dùng `X-Forwarded-Host`. Nhiều LB/CDN chuyển nguyên header này từ client.
  - Hệ quả: người dùng gửi `Host: api…` + `X-Forwarded-Host: admin-api…` sẽ gọi được **route quản trị bằng cookie `vv_session` của học sinh**. Làm ngược lại thì phiên staff chạy được trên route học sinh.
  - `EnsureAdminOrigin` không chặn được vì client ngoài trình duyệt tự đặt `Origin` được. Lúc đó chỉ còn `role`/Policy bảo vệ, mà test kiến trúc lại chưa bắt buộc `role` trên nhóm admin (M1).
  - Từ trình duyệt của nạn nhân thì không khai thác chéo được: `X-Forwarded-Host` không có trong CORS `allowed_headers`. Vì vậy đây là leo quyền bằng chính phiên của kẻ tấn công, không phải chiếm phiên người khác. Nhưng nó phá cam kết "phiên học sinh không bao giờ dùng được trên host admin-api" của ADR-004 §2.2.
- **Cách sửa:**
  ```php
  // bootstrap/app.php
  use Illuminate\Http\Middleware\TrustProxies;
  use Illuminate\Http\Request;

  // 1) Không tin X-Forwarded-Host/Prefix: Host là ranh giới bảo mật, Nginx origin đã nhận Host đúng.
  $middleware->trustProxies(
      at: $trustedProxies,
      headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
  );

  // 2) TrustProxies phải chạy TRƯỚC TrustHosts/ConfigureHostContext (prepend sau cùng = chạy trước;
  //    array_unique giữ vị trí prepend). Thứ tự đích:
  //    AssignRequestId, TrustProxies, TrustHosts, ConfigureHostContext, SecurityHeaders, ...
  $middleware->prepend(SecurityHeaders::class);
  $middleware->prepend(ConfigureHostContext::class);
  $middleware->prepend(TrustHosts::class);
  $middleware->prepend(TrustProxies::class);
  $middleware->prepend(AssignRequestId::class);
  ```
  Thêm lớp phòng thủ: trong `ConfigureHostContext`, lưu host đã chọn vào `$request->attributes` (`vv_host`). `EncryptCookies`/`EnsureAdminOrigin` đọc attribute này thay vì gọi lại `getHost()`. Ở Nginx/LB production (T31): `proxy_set_header X-Forwarded-Host "";` hoặc ghi đè bằng `$host`.
- **Kiểm chứng sau khi sửa (test Pest):** đặt `TrustProxies::at(['127.0.0.1'])` trong test (REMOTE_ADDR mặc định 127.0.0.1), gọi `GET http://api.localhost/api/v1/config/public` kèm `X-Forwarded-Host: admin-api.localhost`. Kỳ vọng 200 (vẫn là route host api). Gọi `csrf-token` với XFH admin thì cookie vẫn là `vv_session`/`lax`, CORS vẫn là `FRONTEND_URL`. Thêm 1 test khẳng định thứ tự `TrustProxies` đứng trước `ConfigureHostContext` trong `Kernel::getGlobalMiddleware()`.

### M1 [Medium] Test kiến trúc và nhóm middleware admin thiếu kiểm vai trò theo từng request, và không bắt buộc `auth:sanctum` — OWASP A01 (S19)
- **Vị trí:** `backend/tests/Feature/T02/RouteMiddlewareGroupsTest.php:20-22` (chỉ xét route đã có `auth:sanctum`), `:36` (danh sách bắt buộc của admin-api không có `role:admin,quan_ly_trang,giao_vien`, trong khi api-contract §1.3 yêu cầu).
- **Mô tả:**
  - Route admin-api quên `auth:sanctum` sẽ không bị test bắt.
  - Route có `auth:sanctum` nhưng thiếu `role:` vẫn xanh.
  - Ranh giới "chỉ staff/GV mới có phiên trên admin-api" chỉ được kiểm lúc đăng nhập (T28). Kết hợp với H1, hoặc với bất kỳ lỗi nào về phiên, học sinh sẽ vào được route admin. `staff.mfa_passed` có thể chỉ kiểm admin/QLT nên cũng không chặn học sinh.
- **Cách sửa:**
  - Test: mọi route trên admin-api phải có `auth:sanctum`, trừ allowlist tường minh theo **tên route** (`admin.csrf-token`, `admin.auth.login`, `admin.auth.mfa.*`…). Bắt buộc `role:admin,quan_ly_trang,giao_vien` (hoặc `can:access-admin-area`).
  - Host api: route `auth:sanctum` phải có `role:hoc_sinh`, trừ các route được liệt kê riêng.
  - Khai báo sẵn nhóm route trong `routes/admin.php` để T28 dùng:
  ```php
  Route::middleware(['auth:sanctum','account.active','staff.idle','staff.mfa_passed','staff.password_fresh','no_store','role:admin,quan_ly_trang,giao_vien'])->group(...)
  ```
- **Kiểm chứng:** thêm vào test một route giả có `auth:sanctum` nhưng thiếu `role` trên admin-api, test phải FAIL.

### M2 [Medium] Nginx: vượt được giới hạn 16 KB của webhook và vượt được lệnh chặn `/storage` — OWASP A05/A04 (S22)
- **Vị trí:** `infra/nginx/conf.d/vitaminvui.conf:13-20`; `infra/nginx/snippets/vv-common.conf:6, 19-28`.
- **Mô tả (đã xác minh bằng curl):**
  - `location ~ ^/api/v1/webhooks/` dùng `try_files … /index.php` nên chuyển hướng nội bộ sang `location ~ \.php$` (5m). Giới hạn 16k chỉ áp khi có `Content-Length`. Body **chunked 100 KB** tới được Laravel; `fastcgi_pass` trong block webhook không bao giờ được dùng.
  - Tiền tố `/index.php/…` không khớp các regex chặn: `/index.php/api/v1/webhooks/...` bỏ qua 16k, còn `/index.php/storage/...` tới route `storage/{path}` của Laravel (response có `X-Request-Id`).
  - `location ~ \.php$` chuyển mọi `*.php` không tồn tại cho FPM ("File not found.").
- **Cách sửa (áp cho cả cấu hình production ở T31):**
  ```nginx
  location ^~ /index.php/ { return 404; }          # chặn PATH_INFO qua front controller

  location ^~ /api/v1/webhooks/ {                   # body đọc tại đây → 16k áp cả khi chunked
      client_max_body_size 16k;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/index.php;
      fastcgi_param SCRIPT_NAME /index.php;
      fastcgi_param HTTP_PROXY "";
      fastcgi_pass php:9000;
  }

  location = /index.php {                           # thay cho location ~ \.php$
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_param HTTP_PROXY "";
      fastcgi_pass php:9000;
  }
  location ~ \.php$ { return 404; }
  ```
  Ở tầng ứng dụng (T19, ADR-001 §2): kiểm thêm `strlen($request->getContent()) > 16384`, vượt thì trả 413 (phòng thủ nhiều lớp).
- **Kiểm chứng:** các lệnh `curl` ở bảng trên (chunked, `/index.php/...`, `/foo.php`) phải trả 413/404 **do Nginx** (response không có `X-Request-Id`).

### M3 [Medium] Endpoint công khai gắn cookie phiên vào response `Cache-Control: public`, và không có throttle nào nên tạo phiên Redis không giới hạn — OWASP A04 (S16)
- **Vị trí:** `backend/routes/api.php:19-21` (không có `throttle`), `backend/app/Http/Controllers/Api/V1/PublicConfigController.php:29`, nhóm `api` có `statefulApi()` (`bootstrap/app.php:69`).
- **Mô tả:**
  - Mọi request có `Origin`/`Referer` thuộc `SANCTUM_STATEFUL_DOMAINS` đều chạy qua `StartSession`. `GET /config/public` trả về `public, max-age=60` **kèm `Set-Cookie: vv_session`** (đã xác minh).
  - Nếu một tầng cache (Nginx micro-cache ADR-004 §2.7, CDN có cấu hình "cache everything") lưu response này, cookie phiên (có thể là phiên **đang đăng nhập** của người gọi đầu tiên) sẽ bị phát cho người khác. ADR-004 §2.5 yêu cầu endpoint công khai "không đọc cookie".
  - `csrf-token`, `config/public`, `health` không có throttle. Client ngoài trình duyệt chỉ cần đặt header `Origin` là mỗi request tạo 1 phiên Redis sống 7 ngày → làm cạn bộ nhớ Redis (DB session).
- **Cách sửa:**
  ```php
  use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
  Route::middleware('throttle:catalog')
      ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
      ->group(function () {
          Route::get('/config/public', ...);
          Route::get('/health', ...);
      });
  Route::get('/csrf-token', CsrfController::class)->middleware('throttle:csrf'); // limiter mới, ví dụ 30/phút/IP
  ```
  Đặt `maxmemory` cho Redis và theo dõi dung lượng DB session. Ở Nginx/CDN, không cache response có `Set-Cookie` (giữ mặc định, ghi vào checklist T31).
- **Kiểm chứng:** test `GET config/public` với `Origin: FRONTEND_URL` + cookie phiên thì response **không có** `Set-Cookie`. Test `csrf-token` gọi quá ngưỡng thì trả 429 `TOO_MANY_ATTEMPTS`.

### M4 [Medium] Boot guard production chưa đủ; cờ `Secure` của cookie phụ thuộc hoàn toàn vào env — OWASP A05/A02 (S4, S22)
- **Vị trí:** `backend/app/Providers/AppServiceProvider.php:160-175`, `backend/config/session.php:185`, `backend/config/sanctum.php:21-26`.
- **Mô tả:**
  - `session.secure = env('SESSION_SECURE_COOKIE')`: quên đặt ở production thì giá trị là `null`, cookie phiên và admin **không có Secure**.
  - Mặc định của `SANCTUM_STATEFUL_DOMAINS` có `localhost`, `127.0.0.1`.
  - Boot guard chỉ kiểm thanh toán, theo kiểu blocklist: `str_contains('test-payment')`, so khớp `'fake'` phân biệt hoa/thường. Nó không kiểm `APP_DEBUG`, `CAPTCHA_DRIVER=fake`, `session.secure`, stateful có localhost, `TRUSTED_PROXIES='*'`.
- **Cách sửa:**
  - Trong `ConfigureHostContext`: `'session.secure' => app()->isProduction() || $request->isSecure()`.
  - Mở rộng guard khi `isProduction()`: ném exception nếu `config('app.debug')`, `! config('session.secure')`, stateful chứa `localhost`/`127.0.0.1`, `captcha.driver === 'fake'`, `in_array('fake', array_map('strtolower', …))`, `trusted_proxies` chứa `*`, hoặc host của `MOMO_ENDPOINT` không phải **đúng** `payment.momo.vn` qua `https` (dùng allowlist, không dùng blocklist).
- **Kiểm chứng:** test cho mỗi điều kiện: `app()->detectEnvironment(fn () => 'production')` + config sai, rồi boot provider thì phải ném `RuntimeException`.

### M5 [Medium] `AuditLog` chỉ "bất biến" ở 2 method của instance — OWASP A09 (S15)
- **Vị trí:** `backend/app/Models/AuditLog.php:144-152`.
- **Mô tả:** vẫn sửa/xoá được bằng `$log->action = 'x'; $log->save()`, `AuditLog::query()->update([...])`, `AuditLog::where(...)->delete()`, `truncate()`, `DB::table('audit_logs')`. Người trong nội bộ hoặc một lỗi code có thể xoá dấu vết thao tác nhạy cảm (khoá tài khoản, xuất PII).
- **Cách sửa:**
  - Model: đăng ký sự kiện `static::updating(fn () => throw new LogicException(...))` và `static::deleting(...)`.
  - Tầng DB (quan trọng hơn): user MySQL của ứng dụng chỉ có `INSERT, SELECT` trên `audit_logs` (migration chạy bằng user khác ở production), hoặc thêm trigger `BEFORE UPDATE/DELETE … SIGNAL SQLSTATE '45000'`. Chuyển DBA quyết định.
- **Kiểm chứng:** test `save()` sau khi đổi thuộc tính → exception; `AuditLog::query()->update()` → exception (nếu dùng trigger).

### M6 [Medium] docker-compose mở MySQL/Redis/Mailpit ra mọi interface, dùng mật khẩu mặc định `secret` — OWASP A05
- **Vị trí:** `infra/docker-compose.yml:53-56, 60-61, 72-76, 82-84`.
- **Mô tả:** `0.0.0.0:3306` (root/`secret`), `0.0.0.0:6379` (`secret`), `0.0.0.0:8025`. Giao diện Mailpit không có xác thực và sẽ chứa email OTP/PII khi test. Trên Docker Desktop/WSL, các cổng này có thể truy cập được từ mạng LAN (Wi-Fi công cộng, văn phòng).
- **Cách sửa:** bind `"127.0.0.1:3306:3306"`, `"127.0.0.1:6379:6379"`, `"127.0.0.1:8025:8025"`, `"127.0.0.1:1025:1025"` (và `"127.0.0.1:8000:80"` nếu frontend chạy trên host), hoặc bỏ hẳn `ports` của MySQL/Redis. Bỏ giá trị mặc định `:-secret`, bắt buộc đặt trong `infra/.env` (thêm vào `.gitignore`).
- **Kiểm chứng:** `docker compose ps` chỉ còn `127.0.0.1:…`.

### L1 [Low] Hai ngoại lệ của test "mọi route gọi được trên admin-api phải có `admin.origin`"
- **Vị trí:** `backend/tests/Feature/T02/RouteMiddlewareGroupsTest.php:78`, `backend/config/filesystems.php:36`.
- **`up`: chấp nhận được.** Route không đọc/ghi session hay cookie, không có tham số, không đổi trạng thái. Hai ghi chú:
  - Route này có trên **cả 2 host** và trả trang HTML tải script `cdn.jsdelivr.net` + font bên ngoài trên origin của API (không có SRI).
  - Đề xuất: ở Nginx production chỉ cho IP giám sát/LB gọi `location = /up` (`allow …; deny all;`), hoặc dùng `/api/v1/health` (JSON) cho healthcheck và đặt `health: null`.
- **`storage/{path}`: không nên miễn trừ. Nên tắt hẳn route.**
  - `'serve' => true` đăng ký **2 route**: `GET` (ServeFile) và **`PUT` (ReceiveFile, ghi file vào `storage/app/private`)**. Cả hai không có domain, không có middleware, không throttle.
  - Chữ ký là *relative*, không gắn host, nên URL ký ở host này dùng được ở host kia. Nginx chặn `/storage/` nhưng tới được qua `/index.php/storage/...` (M2).
  - Dự án không cần tính năng này: file xuất dùng controller + signed URL riêng theo S14; ảnh công khai nằm ở tên miền tĩnh riêng.
  - **Sửa:** `'serve' => false`, bỏ `storage/{path}` khỏi `$exemptUris`, thêm test `Route::has('storage.local') === false`.
- Test đang so khớp ngoại lệ theo chuỗi URI. Nên dùng tên route hoặc action (URI `up` do app tự định nghĩa sau này sẽ lọt).

### L2 [Low] Độ chặt của test kiến trúc
- `RouteMiddlewareGroupsTest.php:20` so khớp chính xác chuỗi `'auth:sanctum'`: viết `auth:sanctum,web` hoặc `Authenticate::using('sanctum')` sẽ lọt.
- `:39` miễn trừ bằng `str_contains('auth/password')`/`'auth/mfa'`, quá rộng. Chỉ nên so khớp tên route cụ thể.
- `:87` chỉ coi `domain === null` hoặc đúng host admin là "gọi được trên admin". Route có domain dạng tham số (`{sub}.…`) sẽ không bị xét.

### L3 [Low] Gia cố Nginx
- `server_tokens off;` (hiện lộ `nginx/1.27.5`).
- Thêm `server { listen 80 default_server; return 444; }`. Hiện host lạ rơi vào block `api.localhost` và phụ thuộc hoàn toàn vào TrustHosts của Laravel.
- Header bảo mật bị lặp (Nginx và `SecurityHeaders`). Chỉ giữ một nơi, hoặc dùng `fastcgi_hide_header` rồi để Nginx thêm.
- HSTS chỉ bật khi `isSecure()`: ổn, với điều kiện H1 cho phép tin `X-Forwarded-Proto`.

### L4 [Low] `AuditLogger` / `staff:*`
- `AuditLogger.php:176-183`:
  - Chỉ lọc theo tên khoá, bỏ sót `secret`, `key` (`access_key`, `secret_key`), `otp`, `address`, `date_of_birth`, `cccd`.
  - `str_contains('code')` lọc luôn `coupon_code`/`referral_code_used`, nên audit mã giảm giá mất dữ liệu cần thiết. Nên dùng allowlist theo từng action, hoặc blocklist khớp chính xác kèm tiền tố.
- `AuditLogger.php:188-201`: chạy từ CLI thì `actor_id`/`actor_role` là null. Nên ghi `actor_type=cli` + user hệ điều hành (`get_current_user()`) + hostname.
- `StaffCreateCommand.php:66`: audit ghi **ngoài** transaction. Đưa vào trong `DB::transaction` để không có tài khoản staff nào được tạo mà thiếu audit.
- `StaffLockCommand`/`StaffUnlockCommand`: `changes` rỗng, nên ghi `{status: {from, to}}`.
- Mật khẩu in ra stdout đúng thiết kế. Runbook cần ghi: không chạy qua CI/log tập trung, xoá scrollback. Về lâu dài (US-016): gửi link đặt mật khẩu thay vì in ra.

### L5 [Low] Log
- `.env.example:17,19` đặt `LOG_STACK=single`, `LOG_LEVEL=debug`: file `laravel.log` không xoay vòng, ghi mức debug.
- Production: `LOG_STACK=daily`, `LOG_LEVEL=info`/`warning`, `LOG_DAILY_DAYS` theo chính sách lưu trữ (S21; thời hạn lưu log có PII: **cần bộ phận pháp chế xác nhận**).

### L6 [Low] Sanctum token và các chi tiết khác
- `User.php:26` có `HasApiTokens` và migration `personal_access_tokens`, dù S24 không phát hành token. `auth:sanctum` vẫn nhận Bearer token và bỏ qua CSRF.
- Đề xuất: bỏ `HasApiTokens`, hoặc thêm test kiến trúc cấm `createToken(` trong `app/`.
- `ApiExceptionRenderer.php:68-71` bỏ header của `HttpException` (`Retry-After` của 429, `Allow` của 405). Là lỗi chức năng; nên `->withHeaders($e->getHeaders())`.

### Info
- `SESSION_ENCRYPT=false`: payload phiên trong Redis là plaintext (user id, CSRF token, sau này cờ MFA). Cân nhắc bật cho host admin.
- `ConfigureHostContext` ghi đè `config()` theo từng request: an toàn với PHP-FPM, **không** an toàn nếu sau này dùng Octane.
- Healthcheck MySQL đưa mật khẩu root lên dòng lệnh (`docker inspect` thấy được). Chỉ ảnh hưởng local.

---

## Đánh giá lại các mục S* liên quan T01/T02

| # | Trạng thái trước | Sau review code | Ghi chú |
|---|---|---|---|
| S1 | ✅ | ✅ | Laravel 13.33.0, PHP 8.3.35, MySQL 8.4.11; `composer audit` sạch |
| S4 | ✅ | ✅ (Low) | Có boot guard, nhưng là blocklist → M4 |
| **S6** | ✅ | **⚠️ Mở lại có điều kiện (H1)** | R1 đã sửa, đã xác minh (404 trên cả 2 host, route không còn đăng ký). R3, R5 đạt. Cookie host-only, tách tên, admin Strict, CORS 1 origin, `EnsureAdminOrigin` đều đạt khi kiểm HTTP thật. Còn H1 (lệch host khi có proxy), L1 (`storage/{path}`), M4 (`Secure`) |
| S10 | ✅ | ⚠️ | Không dùng `*`, XFF giả bị bỏ qua ở local. Nhưng tin `X-Forwarded-Host` và thứ tự middleware sai → H1 |
| S15 | ✅ | ⚠️ | Có `audit_logs`, `staff:create`; độ bất biến yếu → M5 |
| S16 | ✅ | ⚠️ | `no_store` + `Vary` đạt; endpoint công khai trả `Set-Cookie` kèm `public` → M3 |
| S17 | ✅ | ✅ | `$fillable`, có test, `forceCreate` chỉ trong command CLI |
| S19 | ✅ | ⚠️ | Test kiến trúc chưa bắt buộc `role`/`auth:sanctum` trên admin → M1 |
| S21 | ✅ | ✅ (Low) | `dontFlash` đủ khoá; cấu hình log mặc định → L5 |
| S22 | ✅ | ⚠️ | Nginx vượt chặn được → M2, L3; cổng docker → M6 |
| S24 | ✅ | ✅ (Low) | L6 |

## Việc chuyển `laravel-dev`
1. **H1:** giới hạn `headers` của `trustProxies`, cho `TrustProxies` chạy trước `TrustHosts`/`ConfigureHostContext`, host đã chọn truyền qua request attribute.
2. **M2:** sửa Nginx (chặn `/index.php/`, block webhook tự `fastcgi_pass`, chỉ `= /index.php`).
3. **L1:** `'serve' => false` + bỏ ngoại lệ `storage/{path}`.
4. **M1:** thêm `role`/`auth:sanctum` vào test kiến trúc và dựng khung nhóm route.
5. **M3:** bỏ stateful + thêm throttle cho endpoint công khai; limiter `csrf`.
6. **M4:** `session.secure` + mở rộng boot guard.
7. **M5:** chặn sửa/xoá audit log ở model + đề xuất quyền DB (cùng DBA).
8. **M6:** bind cổng vào `127.0.0.1`, bỏ mật khẩu mặc định.
9. **L2–L6:** làm khi thuận tiện, trước T28/T31.

## Test `laravel-qa` nên thêm
- XFH + trusted proxy: không đổi route/cookie/CORS (H1); thứ tự global middleware.
- `GET config/public` với Origin + cookie: không có `Set-Cookie`; `csrf-token` quá ngưỡng trả 429 (M3).
- `Route::has('storage.local') === false` và `storage.local.upload` không tồn tại (L1).
- Test kiến trúc phải FAIL với route admin giả thiếu `role` hoặc thiếu `auth:sanctum` (M1).
- Boot guard production: debug, secure cookie, captcha fake, stateful localhost, gateway `Fake` viết hoa, endpoint MoMo ngoài allowlist (M4).
- `AuditLog` `save()`/query `update()`/`delete()` phải ném exception (M5).
- Smoke test Nginx trong CI hoặc script: chunked 100 KB tới webhook trả 413; `/index.php/storage/x` và `/foo.php` trả 404 không có `X-Request-Id` (M2).

## Điểm cần pháp chế / PO quyết
- Thời hạn lưu log ứng dụng và audit log có IP/user agent (L5, S21): **cần bộ phận pháp chế xác nhận**.
- Hạ tầng production (LB/CDN nào, có chuyển `X-Forwarded-Host` không) ảnh hưởng trực tiếp H1. Cần PO/DevOps chốt ở T31.

---

## Xác minh lại sau khi sửa — 2026-09-28

**Cách làm:**
- Đọc `git diff` so với HEAD (chưa commit) và các file mới: `ProductionConfigGuard`, `ImmutableAuditLogBuilder`, `config/captcha.php`, `infra/.env.example`, 3 test mới.
- Kiểm HTTP thật qua Nginx cổng 8000 bằng `curl`.
- Mô phỏng trusted proxy trong bộ nhớ, giống lần trước: `TRUSTED_PROXIES=10.9.9.9`, session/cache/limiter dạng `array`, không ghi DB.

**Kết quả công cụ:**
- `composer ci`: Pint OK, PHPStan "No errors", Pest **102 passed (274 assertions)**.
- `composer audit`: sạch.
- `infra/.env` và `backend/.env` đều nằm trong `.gitignore`.

### Kết luận mới: **PASS có điều kiện**

Không còn Critical/High. H1 đã đóng và đã kiểm bằng hành vi thật. Điều kiện:
1. Sửa N1 (hồi quy ở `AuditLogger`) trước hoặc trong T03.
2. M5 phần DB (quyền MySQL hoặc trigger) có chủ (DBA) và hạn, trước staging.
3. Các mục hoãn sang T31 phải có trong checklist T31: `/up` giới hạn IP, log production (L5), header trên response do Nginx tự trả (N2).

### Trạng thái từng phát hiện

| # | Trạng thái | Bằng chứng |
|---|---|---|
| **H1** | ✅ Đóng | `trustProxies(headers: FOR\|PORT\|PROTO)`; thứ tự global đúng `AssignRequestId → TrustProxies → TrustHosts → ConfigureHostContext → SecurityHeaders` (có test thứ tự); host lưu vào `vv_host`. Mô phỏng proxy tin cậy: `Host: api` + `XFH: admin-api` → route **api** (Origin lạ trả 400 của `CsrfController`, không phải 403 của admin), cookie `vv_session` Lax, ACAO `localhost:3000`. `Host: admin-api` + `XFH: api` → vẫn là admin (`vv_admin_session` Strict). `XFH: evil.test` bị bỏ qua. `X-Forwarded-Proto: https` từ proxy tin cậy → cookie `Secure` + HSTS; từ IP lạ thì bị bỏ qua |
| **M1** | ✅ Đóng (host admin) | Mọi route gọi được trên admin-api bắt buộc có `admin.origin` + `auth:sanctum` (`str_starts_with`) + `role:`/`can:access-admin-area`. Allowlist theo **tên route**. Có 3 test âm (thiếu role / thiếu auth / thiếu origin → phát hiện được). Host api chưa bắt buộc `role:hoc_sinh` (xem "Trước T03") |
| **M2** | ✅ Đóng | Webhook 100 KB: có `Content-Length`, chunked, `//api/...`, `/api/v1/./webhooks`, `%2F` → **413 từ Nginx**. `/index.php/storage/x`, `/index.php%2Fstorage/x`, `//index.php/...`, `/index.php/api/v1/webhooks/...`, `/foo.php` → **404 từ Nginx** (không có `X-Request-Id`). `/api/v1/Webhooks` (viết hoa) vẫn tới Laravel với body 5 MB, nhưng route Laravel phân biệt hoa/thường nên không khớp webhook: chấp nhận |
| **M3** | ✅ Đóng | `config/public`, `health`: `withoutMiddleware(EnsureFrontendRequestsAreStateful)` + `throttle:catalog`. Gọi kèm Origin + Cookie → **không có `Set-Cookie`**, vẫn `public, max-age=60`. `csrf-token` có `throttle:csrf` (30/phút/IP) trên cả 2 host. Đã kiểm: request bị `admin.origin` từ chối (Origin stateful sai host) **vẫn bị đếm** throttle (`X-RateLimit-Remaining` giảm), nên việc tạo phiên Redis bị giới hạn |
| **M4** | ✅ Đóng | `ConfigureHostContext` ép `session.secure = isProduction() \|\| isSecure()`. `ProductionConfigGuard` kiểm debug, secure cookie, captcha `fake` (không phân biệt hoa/thường), stateful có `localhost`/`127.0.0.1`, `TRUSTED_PROXIES='*'`, gateway `fake` (không phân biệt hoa/thường), MoMo theo **allowlist** `https://payment.momo.vn`; test đủ các nhánh. Lưu ý: guard chạy cả ở CLI production (cấu hình sai thì `artisan` cũng dừng). Đây là hành vi fail-closed, chấp nhận |
| **M5** | ⚠️ **Một phần** | Đã chặn: `update()`/`delete()` instance, `save()` bản ghi đã có (`saving`), `deleting`, Builder `update()`/`delete()`. **Còn bypass được** (đã đối chiếu mã nguồn Laravel 13.33): `saveQuietly()` (tắt event), `$log->increment()/decrement()` (bắn `updating`, không bắn `saving`), `AuditLog::query()->increment()/incrementEach()/touch()/upsert()/forceDelete()` (Eloquent Builder gọi thẳng `toBase()`/`$this->query->delete()`), `truncate()`, `toBase()`, `DB::table()`. Đề xuất nhanh: thêm listener `updating` (chặn `increment`), override `forceDelete`, `increment`, `decrement`, `incrementEach`, `decrementEach`, `touch`, `upsert`, `truncate` trong `ImmutableAuditLogBuilder`, override `saveQuietly` trên model. **Biện pháp đáng tin là quyền DB/trigger**: TODO DBA, phải xong trước staging |
| **M6** | ✅ Đóng | MySQL, Redis, Mailpit chỉ `127.0.0.1`; `docker-compose.yml` bắt buộc `VV_*_PASSWORD` (`:?`), không còn mặc định `secret`; `infra/.env` bị ignore. Còn lại: `nginx` vẫn `0.0.0.0:8000` trong khi `APP_DEBUG=true` (N3) |
| **L1** | ✅ Đóng | `'serve' => false`; `route:list` không còn `storage/{path}` (GET/PUT); có test `Route::has('storage.local'/'storage.local.upload') === false`; bỏ khỏi `$exemptUris`. `up`: vẫn miễn trừ (chấp nhận); giới hạn IP hoãn sang T31 |
| **L2** | ⚠️ Một phần | Đã có allowlist theo tên route, `str_starts_with('auth:sanctum')`. Domain dạng tham số: hoãn (chấp nhận, hiện không có route loại này). `$exemptUris = ['up']` vẫn so theo URI (rủi ro thấp) |
| **L3** | ✅ Đóng | `server_tokens off` (`Server: nginx`); `default_server` trả **444** (Host lạ và request không có Host đều bị đóng kết nối); header chỉ còn 1 lần trên response Laravel. Hệ quả mới: xem N2 |
| **L4** | ⚠️ Một phần + **hồi quy (N1)** | Đã làm: `actor_role='cli'`, khoá cụ thể (`secret`, `otp`, `address`, `date_of_birth`, `cccd`, hậu tố `_key/_secret/_token`), không lọc `coupon_code` nữa. **Chưa làm:** `StaffCreateCommand` vẫn ghi audit ngoài transaction; `staff:lock/unlock` vẫn có `changes` rỗng (file không đổi) |
| **L5** | ⏸ Hoãn sang T31 | Chấp nhận, phải nằm trong checklist T31 |
| **L6** | ⚠️ Một phần | `ApiExceptionRenderer` đã giữ header `Retry-After`/`Allow`. `User` vẫn `HasApiTokens` (không đổi), rủi ro thấp khi chưa phát hành token |

### Phát hiện mới từ bản sửa

**N1 [Low, cần sửa trước/trong T03] Hồi quy ở `AuditLogger`: khoá mật khẩu/OTP dạng ghép bị lọt.**
- **Vị trí:** `backend/app/Services/Audit/AuditLogger.php` (`FORBIDDEN_EXACT_KEYS`/`isForbiddenKey`).
- **Mô tả:** chuyển từ `str_contains` sang khớp chính xác làm lọt `new_password`, `current_password`, `password_confirmation`, `password_hash`, `otp_code`, `verification_code`, `reset_code`, `client_secret_value`... T03/T04 (đăng ký, OTP, đổi mật khẩu) là nơi đầu tiên dễ gặp các khoá này.
- **Cách sửa:** đưa `password`, `secret`, `otp`, `token` vào nhóm **chứa chuỗi** (như `email`/`phone`); thêm hậu tố `_code` với allowlist ngoại lệ tường minh (`coupon_code`, `referral_code_used`).
- **Test:** 1 test dữ liệu cho mỗi khoá kể trên.

**N2 [Low, T31] Response do Nginx tự trả không còn header bảo mật.**
- **Mô tả:** 403 (`/.env`), 404 (`/foo.php`, `/index.php/...`), 413 (webhook) và file tĩnh (`robots.txt`) không có `X-Content-Type-Options`/`X-Frame-Options`. Nội dung đều là trang lỗi cố định, không có dữ liệu người dùng, nên rủi ro thực tế rất thấp.
- **Hướng sửa:** ở production (T31), giữ header ở một nơi duy nhất mà không mất độ phủ: Nginx `fastcgi_hide_header X-Content-Type-Options; fastcgi_hide_header X-Frame-Options; fastcgi_hide_header Referrer-Policy;` rồi `add_header … always` ở cấp server. HSTS đặt tại điểm kết thúc TLS (LB/Nginx) cho mọi response. Không bắt buộc cho local.

**N3 [Low] Cổng 8000 vẫn mở `0.0.0.0` trong khi `APP_DEBUG=true` ở local.**
- **Vị trí:** `infra/docker-compose.yml` (service `nginx`).
- **Mô tả:** máy trong cùng mạng LAN thấy được trang lỗi debug.
- **Cách sửa:** bind `127.0.0.1:8000:80`. Docker Desktop vẫn cho container khác gọi qua `host.docker.internal`; cần kiểm lại với container frontend.

**N4 [Info, cho T03/T10] Ngưỡng throttle theo IP.**
- `csrf` 30/phút/IP: một lớp học dùng chung NAT (≈40 máy mở trang cùng lúc) có thể chạm ngưỡng. Nên tăng (ví dụ 120/phút) hoặc đếm theo IP + cookie phiên sẵn có.
- `catalog` theo IP: nếu dùng cho SSR catalog (T10), mọi request từ máy chủ Next.js chung 1 IP. Cần limiter riêng hoặc miễn trừ IP nội bộ Next.js.

### Trạng thái S* sau khi xác minh lại

| # | Trạng thái | Ghi chú |
|---|---|---|
| **S6** | ✅ (⏳ tên miền staging) | R1, R3, R5, H1 đóng; cookie host-only và tách tên, admin Strict, `Secure` bị ép ở production, CORS 1 origin, `EnsureAdminOrigin`, `storage/{path}` đã gỡ |
| **S10** | ✅ | Không có `*` (có guard); chỉ tin XFF/Port/Proto; `TrustProxies` chạy trước; XFF/XFP từ IP lạ bị bỏ qua |
| **S15** | ⚠️ Một phần | Có `audit_logs`, `staff:*`, `cli` actor; M5 chặn tầng DB chưa có (TODO DBA) + các bypass Eloquent còn lại; N1; audit `staff:create` ngoài transaction |
| **S16** | ✅ | Endpoint công khai không tạo phiên/`Set-Cookie`; `no_store` + `Vary` cho route đã xác thực |
| **S19** | ✅ (host admin) / ⚠️ (host api) | Test kiến trúc admin đầy đủ; host api chưa bắt buộc `role:hoc_sinh` |
| **S22** | ✅ local (⏳ T31) | Nginx chặn đủ (M2/L1/L3), cổng loopback (trừ N3), guard production. T31: `/up` giới hạn IP, header trên response Nginx (N2), HSTS ở TLS, log production (L5) |

### Việc cần làm trước T03
1. **N1:** sửa `AuditLogger` (khoá chứa `password`/`secret`/`otp`/`token`, hậu tố `_code` có allowlist) + test. T03 là task đầu tiên ghi audit liên quan mật khẩu/OTP.
2. **S19 host api (nên làm cùng T03):** khi thêm route `auth:sanctum` đầu tiên, sửa `expect($checked)->toBe(0)` như TODO, và thêm `role:hoc_sinh` vào nhóm student + test kiến trúc tương ứng (phòng thủ nhiều lớp, api-contract §1.3).
3. Không chặn T03 nhưng phải có chủ và hạn:
   - M5 tầng DB (DBA) + các override Eloquent còn thiếu, trước staging;
   - L4 còn lại (transaction trong `staff:create`, `changes` của lock/unlock);
   - N3;
   - N4: chốt ngưỡng `csrf` với PO/`nextjs-dev`;
   - T31: N2, L5, `/up`.
