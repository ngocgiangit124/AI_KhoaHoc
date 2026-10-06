# SECURITY: Cụm 4 "Cấu hình tổng thể, hạ tầng mẫu, vận hành" | 2026-10-06
**Kết luận:** PASS có điều kiện

Không có Critical hay High. Có 2 Medium, 6 Low và một số Info. Lên staging được. Trước production phải xong:
- M1: tách quyền Redis của worker-video.
- M2: bỏ chú thích cuối dòng trong env mẫu, hoặc guard tự phát hiện giá trị dính chú thích.
- Các mục go-live đã có trong backlog.

**Trạng thái đã audit:** commit `2cf9de0` trên `main`, cộng working tree chưa commit. Hai dev khác sửa song song trong lúc audit. Đã ghi nhận các thay đổi sau:
- `ProductionConfigGuard`: đã có kiểm `TRUSTED_PROXIES` rỗng (miễn cho console) ngay từ đầu audit. `guardStaffMfa()` được thêm giữa chừng.
- `.env.production.example`: cookie đổi sang `__Host-`, thêm `SUPPORT_EMAIL`.
- `grants.sql`: `vv_migrate` có thêm quyền `TRIGGER`. `docker-compose.yml` thêm `log-bin-trust-function-creators`, phục vụ trigger `audit_logs`.

Hash diff của `ProductionConfigGuard.php`, `bootstrap/app.php` và `config/` lúc bắt đầu là `e68de37`, lúc kết thúc là `70d507d`. Các phát hiện bên dưới đều đã đối chiếu lại với bản cuối.

**Đã đọc:** `CLAUDE.md`, `docs/board.md`, `review-T01-T02.md`, `review-T03.md` (mẫu), `review-cum1-auth.md`, `review-cum2-content-video.md`, `backlog-v2.md`, `docs/review/T31.md`, `docs/qa/T31.md`, `docs/ops/production-checklist.md`, ADR-004 §2, §5, §6. Mã: `bootstrap/app.php`, `ProductionConfigGuard`, `ConfigureHostContext`, `SecurityHeaders`, `TrustHosts`, `ApiExceptionRenderer`, `config/{session,cors,sanctum,cache,queue,database,logging,app}.php`, `OperationsServiceProvider`, các Job/Mailable có `ShouldQueue`, `infra/production/**`, `infra/php/**`, `infra/worker-video/**`.

**Không trùng backlog:** các mục cụm 1 L1 (staff_mfa), L2 (audit_logs), L3 (`__Host-`) đang được sửa. Các mục T12-4/5/6/10, T26-3, MF1-1, L4 (Turnstile) đã có trong backlog. Các mục QA T31 đã ghi (guard chưa kiểm `TURNSTILE_SECRET` rỗng, mailer `log`, `APP_URL` https) cũng không báo lại.

## Các điểm đạt (đã kiểm, phần lớn bằng request thật)
Container tạm `docker run --rm` dùng image `vitaminvui-php:8.3`, mount `backend/` chỉ đọc, `storage` và `bootstrap/cache` là tmpfs. Server là `php -S` (SAPI `cli-server`, không phải console). Env lấy từ `.env.production.example`, thay placeholder bằng giá trị giả hợp lệ. Redis DB 11–14 dùng prefix `seccum4-` riêng. MySQL dev chỉ đọc.
- **APP_ENV=production, APP_DEBUG=false:**
  - 404 trên `api/*` trả JSON envelope `NOT_FOUND`. Ngoài `api/*` trả trang HTML 404 chuẩn của Laravel, không có chi tiết.
  - 405 trả `METHOD_NOT_ALLOWED` kèm `Allow`. Host lạ trả 400 với thông điệp chung.
  - Cố ý làm sai mật khẩu DB để có 500: response chỉ có `INTERNAL_ERROR` và `request_id`, không lộ SQL, đường dẫn hay class. Chi tiết chỉ nằm trong log.
- **Guard chặn đúng qua HTTP:** `TRUSTED_PROXIES=` rỗng hoặc `APP_DEBUG=true` đều làm request `api/*` trả 500 với thông điệp chung, lý do ghi vào log. Bộ env hợp lệ thì PASS.
- **Header:** mọi response Laravel trên api, admin-api và video đều có `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`. HSTS `max-age=31536000; includeSubDomains` có khi request là HTTPS (qua `X-Forwarded-Proto` từ proxy tin cậy). Đúng ADR-004 §6.
- **Cookie (admin-api):** `vv_admin_session; path=/; secure; httponly; samesite=strict`, không có `Domain`. `XSRF-TOKEN` cũng secure và strict.
- **CORS:** preflight từ `https://evil.example` vẫn trả `Access-Control-Allow-Origin: https://vitaminvui.vn` (một origin cố định, trình duyệt sẽ chặn). Không có wildcard. `allowed_headers` là danh sách đóng.
- **TrustProxies:** chỉ tin `X-Forwarded-For`, `Port`, `Proto`, không tin `X-Forwarded-Host`, chạy trước TrustHosts. TrustHosts luôn bật, kể cả local.
- **Sanctum:** `routes=false`, không dùng `HasApiTokens`, guard `web`. `dontFlash` có password, code, captcha_token.
- **Redis tách DB:** session 1, cache 2, queue 3, limiter 4. Store `redis-limiter` riêng. `cache.serializable_classes=false`.
- **Scheduler:** mọi lệnh `withoutOverlapping()->onOneServer()`. `queue:prune-failed` 720 giờ. Lỗi `ops:health --log='1'` (cụm 1 I4) đã sửa trong working tree.
- **Job:** chỉ nhận id hoặc guid, không nhận dữ liệu thô từ request. `OtpMail` và `StaffNewDeviceMail` có `ShouldBeEncrypted`.
- **Route (110 route):**
  - Không có route debug hay test, không có Telescope, Horizon, Debugbar. `laravel/pail` chỉ nằm trong `require-dev`.
  - Mọi route admin đều có `admin.origin`, `auth:sanctum`, `role`, `account.active`, `staff.idle`, `staff.mfa_passed`, `staff.password_fresh`.
  - Mọi route công khai `api/v1/*` đều có throttle. Chỉ `/up` không có (xem L2).
  - `videolab/library/*` có `AuthenticateAccessKey` cộng allow-list IP ở Nginx.
- **Secret trong repo:** `git log -p --all` không có khoá thật. Chỉ có placeholder, giá trị local cũ `secret` (đã thay, M6 cũ), và hằng giả trong test (`*-not-real`, khoá mẫu công khai trong tài liệu MoMo dùng cho vector chữ ký). `.gitignore` chặn `.env*` trừ các file mẫu.
- **`zend.exception_ignore_args=On`, `expose_php=Off`:** stack trace trong log không mang đối số.
- **Nginx mẫu:** QA T31 đã chạy thật. Đọc lại không thấy hồi quy: 444, xoá header nội bộ, `/_protected_hls/` internal, giới hạn body, `limit_req`.

## Phát hiện

### M1 [Medium] Worker-video giữ toàn quyền Redis của app: chiếm được worker là chiếm được app (giả mạo phiên admin, chèn job vào queue `default`) — OWASP A08/A05
- **Vị trí:**
  - `infra/production/.env.worker-video.example:22,34-41`: cùng `REDIS_PASSWORD`, cùng `REDIS_PREFIX`, có `REDIS_DB_SESSION=1`, `REDIS_QUEUE_DB=3`.
  - `app/VideoLab/Services/TranscodeService.php:68,87`: worker `SendVideoLabWebhookJob::dispatch()` vào queue `default` của app.
  - `vendor/.../Queue/CallQueuedHandler.php:115-116`: payload bắt đầu bằng `O:` được `unserialize()` không qua giải mã.
- **Mô tả:** ADR-002 §3a coi worker-video (chạy ffmpeg trên file người dùng tải lên) là thành phần không tin cậy. T12-6/T12-10 đã tách DB user `vv_worker_video`, nhưng Redis thì chưa tách. Có mật khẩu Redis là đọc và ghi được mọi DB. Hai đường leo thang:
  1. **Chèn job:** ghi một payload `O:...` (gadget chain PHP) vào `queues:default` ở DB 3. Worker `vitaminvui-queue` của app sẽ `unserialize` payload đó, và có `APP_KEY`, quyền DML toàn DB, khoá MoMo, SMTP. Worker-video vốn phải ghi vào queue này để gửi webhook, nên không chặn được bằng ACL chỉ đọc.
  2. **Giả mạo phiên:** `SESSION_ENCRYPT=false` nên payload phiên ở DB 1 là JSON rõ. Kẻ tấn công lấy CSRF token của chính mình qua `GET /csrf-token`, tìm đúng khoá phiên chứa token đó, rồi ghi `login_web_*` = id admin và các cờ MFA, đồng thời bỏ `password_hash_web`. Khi khoá này vắng, `AuthenticateSession` tự lưu lại thay vì đăng xuất. Kết quả là thành admin mà không cần `APP_KEY` của app.
- **Điều kiện:** phải chiếm được tiến trình worker-video trước, ví dụ qua một CVE demuxer ffmpeg với video do giáo viên hoặc staff tải lên. Mức Medium vì cần bước đầu này. Tác động nếu xảy ra là Critical.
- **Cách tái hiện (local, không cần exploit):**
  1. `docker compose exec worker-video php -r 'echo getenv("REDIS_PASSWORD") ? "co" : "khong";'` (trên máy dev biến được nạp từ `backend/.env`).
  2. `redis-cli -n 1 --scan --pattern '*session*' | head` bằng mật khẩu đó: thấy khoá phiên.
  3. `redis-cli -n 3 LLEN vitaminvui-database-queues:default`: ghi được.
- **Cách sửa (chọn một):**
  - (a) **Khuyến nghị:** dùng Redis riêng (instance hoặc container) cho queue `video`. App chỉ dispatch `TranscodeVideoJob` vào đó. Worker chỉ cập nhật `vl_videos`. Thay vì dispatch webhook job vào queue của app, cho app tự phát hiện video đã xong: scheduler mỗi phút quét `vl_videos.status` đổi (mở rộng `videos:check-stuck`), hoặc worker đẩy JSON thuần `{guid}` vào một list và app đọc bằng lệnh riêng, không `unserialize`.
  - (b) Nếu buộc dùng chung instance thì dùng Redis ACL:
    ~~~
    ACL SETUSER vv_worker_video on >*** ~vitaminvui-database-queues:video* ~vitaminvui-database-queues:video:* &* -@all +@list +@sortedset +@string +eval +evalsha +select +ping +multi +exec
    ~~~
    Cộng với (a) để bỏ dispatch vào `queues:default`. Bật thêm `SESSION_ENCRYPT=true` (ít nhất trên host admin) để giảm tác động khi Redis bị đọc.
  - Cập nhật `production-checklist.md` §4 và §7 và `.env.worker-video.example` (không còn `REDIS_DB_SESSION`).
- **Kiểm chứng sau khi sửa:** đăng nhập Redis bằng user của worker thì `GET`/`SCAN` trên DB 1 và `LPUSH ...queues:default` bị `NOPERM`. Gửi thử một video: transcode xong, app nhận trạng thái mà worker không ghi vào queue `default`.

### M2 [Medium] Env mẫu có chú thích cuối dòng: nạp bằng systemd `EnvironmentFile` hoặc `docker --env-file` thì giá trị dính chú thích, guard vẫn PASS dù `APP_ENV` không còn là `production` và token nội bộ là chuỗi công khai — OWASP A05
- **Vị trí:** `infra/production/.env.production.example`. Khoảng 25 dòng có dạng `KEY=value   # ...`, ví dụ dòng 6 `APP_ENV`, `INTERNAL_API_TOKEN`, `PAYMENT_GATEWAYS`, `CAPTCHA_DRIVER`, `AUTH_OTP_CHANNELS`, `MOMO_ENDPOINT`, `TURNSTILE_SITE_KEY`, `SESSION_COOKIE` (mới thêm `__Host-`). Dòng 3 của chính file này khuyên "Nạp qua biến môi trường systemd/Supervisor". `supervisor/vitaminvui.conf:45` gợi ý `env $(cat ... | xargs)`.
- **Mô tả:** chỉ phpdotenv bỏ chú thích cuối dòng. Docker `--env-file` và systemd `EnvironmentFile` giữ nguyên phần chú thích trong giá trị. Biến môi trường thật lại được ưu tiên hơn file `.env`. Đã chạy thật: dùng file mẫu nguyên trạng, chỉ điền `APP_KEY` và hạ tầng (DB, Redis, TRUSTED_PROXIES, khoá VideoLab) rồi nạp bằng `--env-file`. Kết quả:
  - `app()->environment()` = `"production                 # production | staging. TUYỆT ĐỐI ..."`, `isProduction()=false`. Guard vẫn chạy (vì không phải local/testing) nhưng các kiểm chỉ dành cho production bị tắt: allowlist `MOMO_ENDPOINT` và `pay_url_hosts`, ép `Secure` cookie trong `ConfigureHostContext`, cảnh báo token SSR. Đồng thời `shouldBeStrict` bật.
  - `INTERNAL_API_REQUIRED=true` nhưng `internal.ssr_token` = `"                # openssl rand -hex 32 (≥ 32 ký tự)"` (56 ký tự). Guard được viết để fail-closed khi quên token, nhưng ở đây **PASS**, và token là chuỗi công khai trong repo. Token chỉ có tác dụng trên listener nội bộ `:8081`, nên tác động trực tiếp hạn chế.
  - `payment.enabled_gateways` = `['# để trống khi MVP; V2: momo. KHÔNG fake.']`, `captcha.driver` = `'turnstile           # fake ...'`, `session.cookie` có khoảng trắng và `#`. Guard **PASS**. Ứng dụng sẽ lỗi 500 ở luồng có phiên hoặc captcha (fail-closed), nhưng không có thông báo rõ nguyên nhân.
- **Cách tái hiện:**
  ~~~sh
  docker run --rm --env-file infra/production/.env.production.example vitaminvui-php:8.3 \
    php -r 'var_dump(getenv("APP_ENV"), getenv("INTERNAL_API_TOKEN"));'
  ~~~
  Kết quả là giá trị kèm chú thích. Muốn chạy guard: thêm `APP_KEY`, `TRUSTED_PROXIES` và 3 khoá VideoLab, rồi gọi `(new ProductionConfigGuard)->check()`, sẽ PASS.
- **Cách sửa:**
  1. Đưa mọi chú thích trong `.env.production.example` và `.env.worker-video.example` lên dòng riêng phía trên. Bỏ gợi ý `env $(cat ...|xargs)`: Supervisor không chạy shell, và cách này làm hỏng giá trị có dấu cách. Thay bằng `environment=` của Supervisor, hoặc `EnvironmentFile=` của systemd với file không có chú thích cuối dòng.
  2. Guard thêm lớp an toàn: báo lỗi nếu bất kỳ biến env nào (đọc `getenv()` cho danh sách khoá guard quan tâm, cộng `APP_ENV`) chứa `' #'` hoặc khoảng trắng đầu/cuối. Ngoài local/testing thì `APP_ENV` phải đúng `production` hoặc `staging`, đóng nốt phần còn lại của R2 review T31:
     ~~~php
     throw_unless(in_array(app()->environment(), ['production', 'staging'], true), RuntimeException::class,
         'APP_ENV phải đúng "production" hoặc "staging" (không khoảng trắng/chú thích).');
     ~~~
  3. Token SSR: chỉ chấp nhận `^[A-Fa-f0-9]{64}$`, hoặc tối thiểu không có khoảng trắng và `#`.
- **Kiểm chứng:** test T31 nạp file mẫu bằng parser "thô", tách theo dấu `=` đầu tiên và giữ phần đuôi, giống `--env-file`. Guard phải ném lỗi khi còn chú thích cuối dòng và PASS khi file sạch. Test `APP_ENV="production # x"` và `APP_ENV=prod` thì guard chặn.

### L1 [Low] `LOG_LEVEL=warning` làm mất log `playback` và `payments` mức info, trong khi checklist §8 hứa giữ 90 ngày — OWASP A09
- **Vị trí:** `.env.production.example:18`. `config/logging.php` cho `payments`, `video`, `playback` dùng `env('LOG_LEVEL')`. `PlaybackAuditor.php:18` ghi mức `info`, `MoMoGateway.php:389` ghi mức `info`.
- **Mô tả:** với cấu hình mẫu, mọi bản ghi phát video (user, bài, IP, UA) và nhật ký giao dịch MoMo mức info bị loại bỏ. Chỉ còn cảnh báo bất thường. Kênh `learning` đã được ghim `info` (có chú thích), nhưng hai kênh này thì chưa. Checklist §8 và T13-6 coi log `playback` là dữ liệu điều tra chia sẻ tài khoản.
- **Cách sửa:** ghim `'level' => env('LOG_PLAYBACK_LEVEL', 'info')` cho `playback` và `payments`, giống `learning`. Hoặc PO quyết định không lưu log từng lượt phát (ít PII hơn) và sửa checklist §8 cho khớp.
- **Kiểm chứng:** test cấu hình: với `LOG_LEVEL=warning` thì `config('logging.channels.playback.level') === 'info'`. Ở staging, phát một bài rồi kiểm `storage/logs/playback-*.log` có dòng mới.

### L2 [Low] `/up` công khai trên host api và admin-api, trang HTML nạp script bên thứ ba ngay trong origin API; API không có CSP — OWASP A05/A08
- **Vị trí:** `bootstrap/app.php:31` (`health: '/up'`). `vv-api-common.conf:8` cho mọi đường dẫn vào `index.php`. `SecurityHeaders.php` không có CSP.
- **Mô tả:** `GET https://api.vitaminvui.vn/up` trả trang HTML mặc định của Laravel (title `VitaminVui`) có `<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4">` và font từ `fonts.bunny.net`. Đã thấy thật. Nếu CDN bị chiếm, script chạy trong origin `api.vitaminvui.vn`. Origin này đọc được cookie `XSRF-TOKEN` (không httponly) và gửi được request có cookie phiên học sinh. Muốn khai thác, kẻ tấn công chỉ cần dụ người dùng mở `/up`. Review T01/T02 (S22) đã hẹn "T31: `/up` giới hạn IP", nhưng mẫu Nginx T31 chưa làm. Ngoài ra `/up` không có throttle.
- **Cách sửa:**
  - Nginx thêm `location = /up { allow <IP_GIAM_SAT>; deny all; include fastcgi_params; ... }` ở `vv-api-common.conf`, hoặc tắt `health:` và dùng `/api/v1/health` (JSON, có throttle).
  - Thêm cho mọi response API: `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'` và `Permissions-Policy: camera=(), microphone=(), geolocation=()`. JSON không cần tài nguyên nào, nên CSP này cũng chặn mọi trang HTML lạc vào origin API (trang lỗi, `/up`).
- **Kiểm chứng:** `curl -sI https://api.../up` từ IP ngoài trả 403. Mọi response API có CSP `default-src 'none'`. Thêm assertion header vào `SecurityHeadersTest`.

### L3 [Low] Image PHP không dùng `php.ini-production`: `display_errors=STDOUT`, `display_startup_errors=On`, `log_errors=Off` — OWASP A05
- **Vị trí:** `infra/php/Dockerfile` (không `cp php.ini-production php.ini`). `infra/php/conf.d/zz-vitaminvui.ini` có ghi "Production image dùng cùng file này".
- **Mô tả:** Laravel chỉ tắt `display_errors` sau `HandleExceptions::bootstrap`. Lỗi trước bước này in thẳng ra response kèm đường dẫn tuyệt đối. Ví dụ: thiếu hoặc hỏng `vendor/` trong lúc đổi symlink release, lỗi cú pháp ở `bootstrap/app.php` hay config, hết bộ nhớ khi autoload. Đã thấy thật khi chạy sai thư mục: response 200 chứa `Fatal error ... /var/www/backend/vendor/laravel/framework/...`. `log_errors=Off` khiến lỗi này không nằm trong log FPM.
- **Cách sửa:** trong Dockerfile production: `RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"`. Thêm `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On` vào ini production. Checklist §1 thêm dòng `php -i | grep display_errors` phải là `Off` (cả FPM: `php-fpm -i`).
- **Kiểm chứng:** trong image production, `php -r 'echo ini_get("display_errors");'` in rỗng hoặc `0`.

### L4 [Low] Khi `APP_DEBUG=true`, guard ném lỗi nhưng route ngoài `api/*` vẫn render trang debug đầy đủ — OWASP A05
- **Vị trí:** `ProductionConfigGuard.php:31-35`. `ApiExceptionRenderer::shouldHandle` chỉ xử lý `api/*` hoặc `expectsJson()`.
- **Mô tả:** đã chạy thật với env production hợp lệ cộng `APP_DEBUG=true`. `GET /api/v1/health` trả JSON 500 chung (đạt). `GET /up` không có `Accept: application/json` trả trang debug Laravel khoảng 846 KB, có stack trace, tên class, `ProductionConfigGuard`, thông tin kết nối `mysql`. Guard được viết để chặn đúng trường hợp đặt nhầm này, nhưng chính lúc chặn thì lại lộ chi tiết.
- **Cách sửa:** trước khi ném lỗi trong guard, đặt `config(['app.debug' => false]);`. Hoặc trong `check()`, nếu đang debug thì ép tắt debug trước rồi mới kiểm tiếp.
- **Kiểm chứng:** test: `APP_ENV=production`, `app.debug=true`, request `GET /khong-ton-tai` (HTML) trả 500 và body không chứa `ProductionConfigGuard` hay `vendor/`.

### L5 [Low] `guardStatefulDomains` là blocklist, trái với docblock "ALLOWLIST" của chính class — OWASP A05
- **Vị trí:** `ProductionConfigGuard.php` `guardStatefulDomains()`. Chỉ chặn chuỗi chứa `localhost` hoặc `127.0.0.1`.
- **Mô tả:** các giá trị `*`, `::1`, `0.0.0.0`, tên miền staging, hay `vitaminvui.vn.evil.com` đều qua. `SANCTUM_STATEFUL_DOMAINS=*` làm mọi `Referer` được coi là stateful. CSRF, CORS một origin và SameSite vẫn chặn phần lớn, nên chỉ là Low, nhưng guard không đúng thiết kế.
- **Cách sửa:** so khớp chính xác: `sort(stateful) === sort([host(FRONTEND_URL), host(ADMIN_URL)])`. Đồng thời ép `FRONTEND_URL`, `ADMIN_URL`, `APP_URL` dùng `https://`.
- **Kiểm chứng:** thêm ca `*`, `::1`, `evil.com` vào `GuardCoverageTest`, đều phải bị chặn.

### L6 [Low] `EnrollmentDecisionMail` chưa có `ShouldBeEncrypted`: email, tên học sinh và lý do từ chối nằm rõ trong Redis queue và `failed_jobs` (30 ngày) — OWASP A09 / dữ liệu cá nhân (phần còn lại của S21)
- **Vị trí:** `app/Mail/EnrollmentDecisionMail.php:16`, `EnrollmentService.php:175`.
- **Mô tả:** `OtpMail` và `StaffNewDeviceMail` đã mã hoá payload, mail này thì chưa. Khi SMTP lỗi 3 lần, payload gồm `to` (email), `studentName` và `reason` (giáo viên nhập tự do) nằm trong `failed_jobs` 720 giờ. Mục M1 làm rủi ro đọc Redis cao hơn.
- **Cách sửa:** `implements ShouldBeEncrypted`. Thêm test kiến trúc: mọi class `ShouldQueue` trong `app/Mail` phải `ShouldBeEncrypted`.
- **Kiểm chứng:** test kiến trúc ở trên. Dispatch thử rồi kiểm payload queue không chứa email dạng rõ.

### Info
- **I1:** file log được tạo với quyền `0644` (Monolog mặc định, `permission` chưa đặt). Checklist §8 đã yêu cầu thư mục `storage/logs` quyền 0750, nên đủ nếu làm đúng. Có thể đặt thêm `'permission' => 0640` cho các kênh `daily`.
- **I2:** N2 (review T01/T02) vẫn mở: response do Nginx tự trả (403 `/.env`, 404 `\.php$`, 413) không có `nosniff` hay `X-Frame-Options`. Nội dung cố định nên rủi ro thấp.
- **I3:** `/api/v1/health` luôn trả `ok` kể cả khi DB sập (đã thấy thật). Đây là điểm vận hành, không phải bảo mật. Cần có `ops:health` hoặc probe riêng kiểm DB và Redis.
- **I4:** HSTS chưa có `preload`. `vv-tls.conf` không đặt `ssl_ciphers` (mặc định của Nginx chấp nhận được với TLS 1.2+). Cân nhắc OCSP stapling.
- **I5:** ngoại lệ console của `TRUSTED_PROXIES` dựa vào `runningInConsole()`. Nếu sau này dùng Octane, worker HTTP cũng là console nên được miễn sai. Cùng nhóm với ghi chú Octane của `ConfigureHostContext` ở T01/T02.
- **I6:** trong lúc audit đã thấy dev thêm `guardStaffMfa()` (cụm 1 L1) và `__Host-` cookie (cụm 1 L3). Với `__Host-`, `ConfigureHostContext` vẫn đặt `domain=null`, `path=/`, `secure` theo `isProduction() || isSecure()`, nên đúng điều kiện. Staging qua `:8081` (HTTP) sẽ không nhận cookie, nhưng SSR không cần cookie.
- **I7:** khi thử thật, test đã để lại vài khoá Redis prefix `seccum4-` ở DB 11–14 của Redis dev (phiên csrf, limiter). Các khoá này tự hết hạn và không ảnh hưởng DB 1–4.

## Kết quả công cụ
- `composer audit`: không có advisory. Không có package bị bỏ hoang (đã kiểm `abandoned` trong `composer.lock`).
- Phiên bản: Laravel 13.33.0, PHP 8.3.35 (còn hỗ trợ bảo mật).
- `php artisan route:list --json -v`: 110 route, lưu ở scratchpad `sec-cum4/routes.json`. Không có route thiếu auth ngoài các route công khai có chủ đích. Mọi route công khai có throttle, trừ `/up` (L2).
- Thử thật bằng container `vitaminvui-php:8.3`: 404, 405, 400 (host lạ), 500 (DB lỗi), guard (TRUSTED_PROXIES rỗng, APP_DEBUG), header, cookie, CORS preflight, env có chú thích (M2), debug page (L4). Không dùng `docker compose run/up`. Load máy 3–6 trong lúc chạy.
- `git log -p --all` grep khoá, mật khẩu, secret: sạch (xem phần Các điểm đạt).
- Không chạy lại Nginx thật (QA T31 đã chạy 2 lượt, không có thay đổi Nginx sau đó).

## Việc chuyển `laravel-dev`
1. **M1 (trước production):** tách Redis cho queue `video` (hoặc Redis ACL), bỏ dispatch webhook job từ worker vào `queues:default`. Bật `SESSION_ENCRYPT=true` ít nhất cho admin. Cập nhật env mẫu worker và checklist §4, §7.
2. **M2 (trước staging nếu nạp env bằng systemd hoặc docker):** chuyển chú thích lên dòng riêng trong cả 2 file env mẫu, sửa gợi ý Supervisor. Guard ép `APP_ENV ∈ {production, staging}` và chặn giá trị có `' #'` hoặc khoảng trắng thừa. Token SSR phải là hex.
3. L1: ghim mức `info` cho kênh `playback` và `payments` (hoặc PO quyết định bỏ log và sửa checklist).
4. L2: giới hạn IP cho `/up` ở Nginx. Thêm CSP `default-src 'none'; frame-ancestors 'none'` và `Permissions-Policy` vào `SecurityHeaders`.
5. L3: `php.ini-production` cho image production. Thêm dòng kiểm vào checklist.
6. L4: guard tắt `app.debug` trước khi ném lỗi.
7. L5: guard so khớp chính xác stateful domains, ép `https://` cho các URL.
8. L6: `ShouldBeEncrypted` cho `EnrollmentDecisionMail`, cộng test kiến trúc.
9. Đưa L1–L6 và I1–I5 vào `backlog-v2.md` (mục "Cụm 4") nếu PO hoãn.

## Test `laravel-qa` nên thêm
- M2: nạp `.env.production.example` bằng parser giữ chú thích, guard phải ném lỗi. Ca `APP_ENV="production # x"`, `APP_ENV=prod`, `INTERNAL_API_TOKEN="  # ..."` đều bị chặn.
- M1 (staging): đăng nhập Redis bằng user worker, `SCAN` ở DB 1 và `LPUSH queues:default` phải `NOPERM`. Video vẫn transcode xong và app nhận trạng thái.
- L1: `LOG_LEVEL=warning` thì `logging.channels.playback.level` vẫn là `info`.
- L2: mọi response API (kể cả 404 HTML, `/up`) có `Content-Security-Policy: default-src 'none'`. `/up` từ IP ngoài trả 403 (staging, curl).
- L4: production cộng `app.debug=true`, request HTML trả 500 mà body không có `vendor/` hay tên class.
- L5: `GuardCoverageTest` thêm `*`, `::1`, `evil.com` cho `SANCTUM_STATEFUL_DOMAINS`.
- L6: test kiến trúc Mailable `ShouldQueue` phải `ShouldBeEncrypted`.
- Staging: `php -i | grep display_errors` ra `Off` trên cả CLI và FPM.

## Điểm cần pháp chế / PO quyết
- Log `playback` (IP, UA, user, bài) giữ 90 ngày, hay không ghi từng lượt phát (L1). Thời hạn lưu log có IP và nội dung chính sách lưu log: cần bộ phận pháp chế xác nhận.
- `failed_jobs` giữ 30 ngày có thể chứa email, tên và lý do từ chối (L6, trước khi sửa). Thời hạn này cần bộ phận pháp chế xác nhận.
- M1 phương án (a) đổi cách worker báo "xong" cho app, ảnh hưởng ADR-002 §3a. Cần PO/Architect chấp thuận cập nhật ADR.
