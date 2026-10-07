# Checklist production và staging (T31)

Dùng cho MỌI lần dựng môi trường mới (staging trước, production sau). Đánh dấu `[x]` kèm ngày và người kiểm. Mẫu cấu hình
nằm ở `infra/production/` (chỉ là mẫu, không dùng cho local). Tham chiếu: ADR-004 §6, ADR-002 §3a, `docs/security/backlog-v2.md`.

Nguyên tắc: staging dùng tên miền riêng, khoá/mật khẩu riêng, và PHẢI cấu hình giống production (khác nhau chỉ ở giá trị
bí mật, tên miền, và cổng thanh toán sandbox nếu V2 bật). `ProductionConfigGuard` chặn khởi động ở MỌI môi trường trừ
`local`/`testing` (từ T31; kể cả `Production`, `prod`, `stage`). Nên đặt `APP_ENV` đúng chữ thường `production` hoặc `staging`: riêng
allowlist MoMo chỉ ép ở đúng `production`, và `shouldBeStrict`/cookie Secure trong code so khớp chính xác chuỗi.

## 1. Biến môi trường

Mẫu đầy đủ: `infra/production/.env.production.example`. Worker video: `infra/production/.env.worker-video.example`.

### 1.1 Bắt buộc (thiếu hoặc sai thì app không khởi động hoặc chạy sai)

- [ ] `APP_ENV=production` (hoặc `staging`), đúng chữ thường, không khoảng trắng/chú thích: guard chặn mọi giá trị khác (`prod`, `Production`, `stage`, `uat`... — C4-M2). Không bao giờ `local`/`testing` trên server thật (`local` nới hạn mức OTP, bật `fake`, suy khoá VideoLab từ `APP_KEY`)
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` đặt riêng mỗi môi trường (và riêng cho worker-video); lưu secret manager
- [ ] `APP_API_HOST`, `APP_ADMIN_API_HOST`, `FRONTEND_URL`, `ADMIN_URL`, `STATIC_URL` đúng tên miền thật (ADR-004 §2.1), tất cả `https`. **US-020:** `STATIC_URL` phải là miền riêng, khác và không là miền cha/con của `APP_URL`/`FRONTEND_URL`/`ADMIN_URL`, không nằm dưới `SESSION_DOMAIN`; `ProductionConfigGuard` chặn khởi động nếu sai, kể cả ở worker-video (thêm `STATIC_URL` vào env của worker-video hiện có TRƯỚC khi triển khai T36)
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null` (host-only), `SESSION_DRIVER=redis`
- [ ] `SESSION_ENCRYPT=true` (C4-M1; guard chặn khi false ngoài local/testing). **Bật/đổi giá trị này làm mọi phiên đang mở không giải mã được: người dùng (học sinh và staff) bị đăng xuất một lần** (Laravel coi payload phiên hỏng như phiên mới, không lỗi 500). Làm ngoài giờ cao điểm; ghi vào thông báo bảo trì. Kiểm sau khi bật: đăng nhập thử, rồi `redis-cli -n 1 --scan | head -1` + `GET` một khoá phiên phải ra chuỗi mã hoá (JSON base64 có `iv`, `value`, `mac`), không thấy `login_web_` rõ
- [ ] Mật khẩu/secret sinh ngẫu nhiên (`openssl rand`), KHÔNG có khoảng trắng ở đầu/cuối và KHÔNG có dấu cách đứng trước `#` (guard chặn ` #` và khoảng trắng đầu/cuối); ký tự `#` được phép nếu dính liền chữ (`ab#cd`, `#abc`)
- [ ] File env/Supervisor/systemd KHÔNG có chú thích cuối dòng `KEY=value # ghi chú` (C4-M2): `docker --env-file` và systemd `EnvironmentFile` giữ nguyên phần ghi chú trong giá trị. Guard chặn giá trị env quan trọng chứa ` #` hoặc khoảng trắng đầu/cuối; kiểm tay: `docker run --rm --env-file <file> <image> php -r 'var_dump(getenv("APP_ENV"));'` phải ra đúng `production`/`staging`
- [ ] Trigger `audit_logs` (L2, migration `2026_10_16_100000`): `vv_migrate` phải có quyền `TRIGGER` (đã có trong `grants.sql`). MySQL bật binary log (mặc định của 8.4) mà user không có SUPER/SET_USER_ID thì cần `log_bin_trust_function_creators=1` (`SET GLOBAL` bằng tài khoản quản trị trước khi migrate); thiếu thì migrate lỗi 1419. **Production: `log_bin_trust_function_creators` chỉ `SET GLOBAL ... = 1` trong lúc chạy migrate (bằng tài khoản quản trị) rồi trả về `0`; KHÔNG ghi cố định vào `my.cnf`** (bản compose local có ghi cố định là chỉ cho dev). Trigger mang `DEFINER = vv_migrate`: KHÔNG xoá/đổi tên user này, nếu không mọi UPDATE/DELETE trên `audit_logs` (kể cả `audit:purge`) báo lỗi 1449 (fail-closed nhưng purge ngừng); nếu bắt buộc đổi thì tạo lại trigger với DEFINER mới. Kiểm sau migrate: `SHOW TRIGGERS LIKE 'audit_logs'` có `audit_logs_block_update` và `audit_logs_block_delete`; bằng `vv_app`, `UPDATE audit_logs SET action='x' LIMIT 1` phải lỗi. Trigger ghi cứng 24 tháng: đổi `OPS_AUDIT_RETENTION_MONTHS` xuống dưới 24 làm `audit:purge` từ chối chạy
- [ ] `SUPPORT_EMAIL` (địa chỉ hỗ trợ ghi trong thư báo đổi email tài khoản, H1) đã đặt và có người đọc
- [ ] Tên cookie có tiền tố `__Host-` (L3, chống cookie tossing từ subdomain cùng site): production `SESSION_COOKIE=__Host-vv_session` / `SESSION_ADMIN_COOKIE=__Host-vv_admin_session`; staging `__Host-vvstg_session` / `__Host-vvstg_admin_session`. Trình duyệt chỉ nhận tiền tố này khi Secure + `Path=/` + không có Domain (đã đúng nhờ `SESSION_SECURE_COOKIE=true`, `SESSION_PATH=/`, `SESSION_DOMAIN=null`). Local/test giữ `vv_session` (http). Sau khi bật: mọi phiên đang mở bị đăng xuất một lần (đổi tên cookie). Kiểm: `curl -sI https://api.<domain>/api/v1/csrf-token -H 'Origin: https://<domain>'` phải có `Set-Cookie: __Host-vv_session=...; secure; path=/` và KHÔNG có `domain=`; tương tự `__Host-vv_admin_session` ở admin-api. Nginx/CDN không được thêm `Domain=` vào Set-Cookie (`proxy_cookie_domain`)
- [ ] `SANCTUM_STATEFUL_DOMAINS` đúng bằng host của `FRONTEND_URL` và `ADMIN_URL`, không thừa không thiếu (guard so khớp chính xác; chặn `*`, `localhost`, `127.0.0.1`, `::1`, `0.0.0.0`, tên miền lạ). `APP_URL`/`FRONTEND_URL`/`ADMIN_URL` phải `https` (C4-L5). Env worker-video cũng phải có `FRONTEND_URL`/`ADMIN_URL` (xem mẫu)
- [ ] `TRUSTED_PROXIES` là danh sách IP cụ thể của load balancer và server Next.js, KHÔNG `*`, KHÔNG để rỗng (guard chặn khi rỗng ở tiến trình web; chỉ tiến trình console như `queue:work` của worker-video được để rỗng). Sai/rỗng thì token CDN ràng IP và `Location` của TUS sai scheme, và mọi học sinh dùng chung một IP (proxy) nên trần lần sai theo IP của mã giảm giá/đăng nhập khoá lẫn nhau
- [ ] `INTERNAL_API_TOKEN` là chuỗi hex >= 32 ký tự (`openssl rand -hex 32`; guard chặn giá trị không phải hex, C4-M2) (T26-1). `INTERNAL_API_REQUIRED=true` CHỈ bật khi bản frontend gửi header (FW2 trở đi) đã được triển khai (xem mục "FE" ngay dưới). Bật sớm thì app production không khởi động khi token rỗng. Để token rỗng thì catalog throttle tính chung một bucket theo IP của Next server (log warning khi boot)
- [ ] FE (ADR-004 §2.8): env của app web có `INTERNAL_API_TOKEN` (giống hệt backend) và `API_INTERNAL_URL=http://<IP_NOI_BO_NGINX>:8081` (IP trần). `fetch` của Node **không đặt được header `Host`** (hostname trong URL chính là Host). Vì vậy listener `:8081` phải **ép** `fastcgi_param HTTP_HOST api.<domain>` (mẫu `infra/production/nginx/conf.d/vitaminvui.conf`, từ Sửa lỗi nhỏ 4). KHÔNG dùng cách đặt `Host` bằng tay (chỉ curl làm được). KHÔNG khuyến nghị trỏ `api.<domain>` về IP nội bộ bằng `/etc/hosts`/`extra_hosts`/DNS nội bộ: cách này đổi đích mọi lời gọi tới tên miền công khai từ máy Next. Nếu hạ tầng buộc phải dùng DNS nội bộ thì `API_INTERNAL_URL=http://api.<domain>:8081`; cách này vẫn chạy vì Host bị ép ở Nginx. Kiểm từ máy Next, không đặt Host: `curl -s -o /dev/null -w '%{http_code}' http://<IP_NOI_BO_NGINX>:8081/api/v1/subjects` phải ra `200` (nếu ra `404` hoặc mã lỗi host thì Nginx chưa ép Host). Token chỉ ở server Next, không xuống trình duyệt
- [ ] Next.js (`next start`) chỉ nghe loopback/mạng nội bộ, KHÔNG mở cổng 3000/3001 ra Internet: Next lấy IP khách từ `X-Forwarded-For` do Nginx ghi đè, gọi thẳng Next thì khách giả được IP (né hạn mức tìm kiếm theo IP)
- [ ] `DB_*` dùng user `vv_app` (không root), `REDIS_PASSWORD` đã đặt (app dùng user Redis `default` trong `redis/users.acl`)
- [ ] PHP: `php -i | grep -E '^(display_errors|display_startup_errors|log_errors|expose_php)'` ra `Off`, `Off`, `On`, `Off` trên CẢ CLI và FPM (`php-fpm -i`) (C4-L3). Image build từ `infra/php/Dockerfile` (có `php.ini-production`); nếu dựng PHP kiểu khác thì tự làm tương đương. Lỗi trước khi Laravel boot (vendor hỏng khi đổi symlink release, lỗi cú pháp) phải chỉ vào log, không vào response
- [ ] `MAIL_*` là SMTP thật; gửi thử một OTP về hộp thư thật
- [ ] `CAPTCHA_DRIVER=turnstile`, `TURNSTILE_SITE_KEY` và `TURNSTILE_SECRET` là key thật của Cloudflare (không phải key test `1x0000...`). Guard chưa kiểm secret rỗng (L4): kiểm tay bằng đăng ký thử
- [ ] `AUTH_OTP_CHANNELS=email`
- [ ] `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`
- [ ] `VIDEO_PROVIDER=internal`, `VIDEO_ENABLED_PROVIDERS=internal`
- [ ] `VIDEOLAB_*`: xem mục 2

### 1.2 Cấm ở staging và production (guard ném lỗi khi khởi động, trừ khi ghi chú khác)

| Biến/giá trị | Lý do | Guard |
|---|---|---|
| `APP_DEBUG=true` | lộ stack trace, secret | có; guard ép `app.debug=false` trước khi ném lỗi nên route ngoài `api/*` cũng không render trang debug (C4-L4) |
| `SESSION_SECURE_COOKIE=false` | cookie phiên đi qua HTTP | có |
| `CAPTCHA_DRIVER=fake` (mọi kiểu viết hoa) | bỏ qua chống bot | có (T31: thêm staging) |
| `PAYMENT_GATEWAYS` chứa `fake` | cổng giả | có |
| `AUTH_OTP_CHANNELS` chứa `sms` | chưa có nhà cung cấp SMS (S9) | có |
| `TRUSTED_PROXIES=*` | giả mạo IP | có |
| `SANCTUM_STATEFUL_DOMAINS` khác tập {host `FRONTEND_URL`, host `ADMIN_URL`} (kể cả `*`, `localhost`, `127.0.0.1`, `::1`, tên miền lạ); `APP_URL`/`FRONTEND_URL`/`ADMIN_URL` tên miền thật mà `http` | mở CSRF cho host lạ/local | có (C4-L5) |
| `APP_ENV` ngoài `production`/`staging`/`local`/`testing` (`prod`, `Production`, `uat`, giá trị dính chú thích) | tắt các kiểm chỉ dành cho production | có (C4-M2) |
| Biến env quan trọng chứa ` #` hoặc khoảng trắng đầu/cuối (chú thích cuối dòng bị nạp vào giá trị) | `APP_ENV`/token sai mà không báo | có (C4-M2) |
| `INTERNAL_API_TOKEN` không phải hex | placeholder/chú thích lọt vào token | có (C4-M2) |
| `SESSION_ENCRYPT=false` | phiên đọc/giả mạo được khi Redis bị đọc | có (C4-M1) |
| `VIDEO_PROVIDER`/`VIDEO_ENABLED_PROVIDERS` chứa `fake` | video giả | có |
| `AUTH_OTP_E2E_RELAXED=true` | nới hạn mức OTP (MF1-1) | lớp thứ 2: `config/auth.php` đã ép tắt ngoài local/testing; guard không phủ trường hợp đặt nhầm `APP_ENV=local` (kiểm tay: dòng đầu mục 1.1) |
| `VIDEOLAB_API_KEY`/`TOKEN_KEY`/`WEBHOOK_SECRET` thiếu hoặc < 32 ký tự | khoá yếu/đoán được | có (T31, khi VideoLab bật) và `VideoLabServiceProvider` |
| `FEATURE_PAID_CHECKOUT=true` mà `PAYMENT_GATEWAYS` rỗng | checkout lỗi giữa chừng | có (T31) |
| `FEATURE_PAID_CHECKOUT=true` khi `payments.ipn_ready=false` (chưa có IPN T19/đối soát T20) | HS trả tiền nhưng không được ghi danh | có (cụm 3 L1; T19 đổi hằng `ipn_ready` trong `config/payments.php`) |
| `FEATURE_STAFF_MFA=false` | bỏ MFA quản trị | có (cụm 1 L1) |
| `TRUSTED_PROXIES` rỗng ở tiến trình web | mọi người dùng chung IP proxy | có (minor-fixes-3 R2) |
| (lưu ý R9) Guard miễn kiểm `TRUSTED_PROXIES` cho tiến trình console dựa vào `runningInConsole()` | nếu chuyển web sang Octane/Swoole/RoadRunner (chạy bằng CLI) thì guard bị vô hiệu cho cả web: phải xem lại điều kiện miễn (vd. chỉ miễn `queue:work`, `schedule:*`) trước khi đổi | không (kiểm tay khi đổi runtime) |
| `MOMO_ENDPOINT` khác `https://payment.momo.vn` | sandbox lọt production | chỉ production (staging được dùng sandbox) |
| `MOMO_PAY_URL_HOSTS` khác đúng `payment.momo.vn` | chuyển hướng sang host lạ (T17-2) | chỉ production (T31) |
| `AWS_*` thừa, `FAKE_*`, Telescope/Debugbar cài ở production | bề mặt thừa | không: kiểm tay (`composer install --no-dev`) |
| Dùng lại khoá/mật khẩu production ở staging (và ngược lại) | staging bị lộ kéo theo production | không: kiểm tay |

Guard CHƯA kiểm (kiểm tay, hoặc thêm khi có yêu cầu): `TURNSTILE_SECRET` rỗng, `MAIL_MAILER` là `log`/`array`, `REDIS_PASSWORD` rỗng,
`APP_URL` không https, user DB là root. Lý do chưa thêm: các test cũ của guard (T01, T04, T11) dựng baseline tối thiểu không có các giá trị này.

- [ ] Chạy `php artisan about --only=environment` rồi khởi động thử: app không ném `RuntimeException` từ `ProductionConfigGuard`

### 1.3 Cờ `FEATURE_*` và giá trị khuyến nghị cho MVP

| Cờ | Mặc định code | MVP production | Ghi chú |
|---|---|---|---|
| `FEATURE_PAID_CHECKOUT` | false | **false** | Thanh toán chuyển V2 (PO 2026-10-06). `POST /checkout` tổng > 0 trả 503 `PAYMENT_DISABLED`. Bật ở V2 chỉ sau khi: kiểm sandbox MoMo (T17-1), T20, `PAYMENT_GATEWAYS=momo`, endpoint MoMo thật |
| `FEATURE_REFERRAL_CODE` | true | true | |
| `FEATURE_QUIZ_TIME_LIMIT` | true | true | |
| `FEATURE_ZERO_TOTAL_CHECKOUT` | true | true | Đơn 0đ/khóa miễn phí không phụ thuộc cổng thanh toán |
| `FEATURE_ENROLLMENT_DECISION_MAIL` | false | true khi SMTP đã chạy | Email báo duyệt/từ chối đăng ký (US-012). Tắt nếu SMTP chưa sẵn sàng |
| `FEATURE_STAFF_MFA` | true | **true** | Không tắt ở production (guard chặn khi false ở mọi môi trường trừ local/testing) |
| `FEATURE_PARENT_CONSENT_ENFORCED` | false | false | Chờ T29/T34 (pháp lý, V2) |
| `FEATURE_EXTERNAL_VIDEO_PREVIEW_ONLY` | true | true | Link ngoài chỉ cho bài học thử |

- [ ] Từng cờ đã đối chiếu bảng trên; `GET /api/v1/config/public` trả `paid_checkout_enabled=false`

## 2. Secret và khoá VideoLab

- [ ] Secret (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `TURNSTILE_SECRET`, `INTERNAL_API_TOKEN`, `VIDEOLAB_*`, `MOMO_*`) nằm trong biến môi trường server hoặc secret manager; không có trong git, ảnh Docker, log
- [ ] File env trên server `chmod 600`, thuộc user chạy dịch vụ, nằm ngoài web root
- [ ] `VIDEOLAB_API_KEY`, `VIDEOLAB_TOKEN_KEY`, `VIDEOLAB_WEBHOOK_SECRET`: mỗi khoá >= 32 ký tự, sinh bằng `openssl rand -hex 32`, KHÁC nhau, KHÁC staging; ngoài local/testing không có suy khoá từ `APP_KEY`
- [ ] `VIDEOLAB_PUBLIC_URL` dùng `https`
- [ ] Khoá MoMo sandbox và production tách riêng (V2); có quy trình xoay khoá và người phụ trách
- [ ] Quy trình xoay `VIDEOLAB_TOKEN_KEY`: đổi khoá làm mọi link phát đang dùng hết hiệu lực (tối đa 15 phút theo `VIDEO_PLAYBACK_TTL_MINUTES`); thực hiện ngoài giờ cao điểm
- [ ] Phiên bản mã không chứa secret: `git log -p -S'VIDEOLAB_' -- .` không thấy giá trị thật

## 3. Nginx

Mẫu: `infra/production/nginx/`. Sáu server block: 4 host ứng dụng (web, admin, api, admin-api) + `video` + tên miền tĩnh; thêm khối mặc định đóng kết nối.

- [ ] TLS hợp lệ cho cả 6 host (+ chứng chỉ riêng cho tên miền tĩnh); tự gia hạn; `ssl_protocols TLSv1.2 TLSv1.3`
- [ ] Server mặc định (`server_name _`) trả 444 cho cả HTTP và HTTPS: truy cập bằng IP hoặc Host lạ bị đóng
- [ ] HTTP chuyển 301 sang HTTPS cho mọi host thật
- [ ] Web root API là `backend/public`; chặn `/.env`, `/.git`, `/storage/*`, mọi file ẩn (trừ `.well-known`)
- [ ] `/index.php/...` trả 404; chỉ đúng `/index.php` chạy PHP; mọi `.php` khác trả 404
- [ ] Disk `exports`, `videolab`, `uploads` nằm ngoài `storage/app/public`
- [ ] `client_max_body_size`: 16k cho `/api/v1/webhooks/*`; 5m mặc định (ảnh đại diện khoá học tối đa 2 MB theo `CourseRules::thumbnail`, `max:2048`; `upload_max_filesize`/`post_max_size` của PHP phải ≥ 3M); TUS 9m (chunk 8 MB + dư 1 MB); `/videolab/library/` 16k; `/videolab/cdn/` 1k
- [ ] Header API do Laravel đặt: `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()` (C4-L2). Kiểm bằng `curl -sI https://api.<domain>/api/v1/config/public` và `https://admin-api.<domain>/...`; mỗi header xuất hiện đúng 1 lần (Nginx không thêm)
- [ ] `/up` (C4-L2): đặt `<IP_MONITOR_LB>` trong `vv-api-common.conf`; `curl -sI https://api.<domain>/up` từ IP ngoài trả 403, từ IP giám sát trả 200 JSON `{"status":"ok"}`; cả host admin-api
- [ ] Web và admin (Next.js): CSP có nonce và header bảo mật do Next đặt; kiểm `curl -sI`
- [ ] Tên miền tĩnh (`STATIC_URL`) trả đủ 3 header: `Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`; `X-Robots-Tag: noindex, noimageindex` (US-020 M1); KHÔNG có `Set-Cookie`; chỉ GET/HEAD; không PHP; không liệt kê thư mục
- [ ] Tên miền tĩnh là tên miền đăng ký riêng (không dùng subdomain của `vitaminvui.vn`) để không nhận cookie (S2) — PO mua tên miền
- [ ] **US-020 (T36), trước khi deploy:** kiểm dữ liệu hồ sơ cũ bị backfill: `SELECT COUNT(*) FROM users WHERE role='giao_vien' AND (bio IS NOT NULL OR avatar_path IS NOT NULL);` phải bằng 0 (dự kiến, chưa từng có đường ghi). Nếu khác 0: rà tay `users.bio` (HTML, ký tự ẩn, `\r\n`) vì migration chép NGUYÊN VĂN (cắt 600 ký tự) mà không qua `PlainText`; bio "bẩn" sẽ công khai khi giáo viên tick đồng ý mà không sửa. Làm sạch bằng SQL hoặc cho giáo viên nhập lại trước khi đồng ý
- [ ] Header nội bộ `X-Internal-Token` và `X-Client-IP` bị xoá ở mọi host công khai (T26-3); kiểm bằng `curl -H 'X-Client-IP: 1.2.3.4' ...` không đổi IP trong log
- [ ] Server nội bộ `:8081` (mẫu có sẵn): chỉ nghe IP mạng nội bộ, `allow` đúng IP Next server + `deny all`, chỉ GET/HEAD `/api/v1/`, ép `HTTP_HOST` = host api (ADR-004 §2.8), có `access_log` riêng. Kiểm: từ máy khác Next server → 403. Từ Next server, kèm token đúng: có `X-Client-IP` thì request thứ 121/phút cùng IP → 429; KHÔNG có `X-Client-IP` thì 200 request/phút vẫn 200 (chỉ trần tổng `CATALOG_SSR_TOTAL_PER_MINUTE`)
- [ ] Firewall chặn cổng 8081 từ ngoài mạng nội bộ

### 3.1 VideoLab (T12-4, T12-5, review T12 R7)

- [ ] `/videolab/library/` chỉ cho IP app server CỤ THỂ (`allow <IP_APP_SERVER>; deny all;`), không dải private rộng như local
- [ ] `real_ip` đúng (`set_real_ip_from` = IP load balancer, `real_ip_header X-Forwarded-For`) để allow-list và token CDN ràng IP so IP khách thật; kiểm: gọi `/videolab/library/` từ máy ngoài app server trả 403
- [ ] `TRUSTED_PROXIES` khớp IP load balancer: kiểm `Location` trả về từ TUS (`POST /videolab/tus`) dùng `https://`
- [ ] `VIDEOLAB_ACCEL_REDIRECT=true` và có `location /_protected_hls/ { internal; alias .../videolab/hls/; }`; kiểm: gọi trực tiếp `/_protected_hls/<guid>/playlist.m3u8` từ ngoài trả 404
- [ ] `limit_req` cho `/videolab/cdn/` (mẫu: 30 r/s, burst 60) và `/videolab/tus`; đo lại với một buổi học thật ở staging rồi chỉnh để player không bị 429
- [ ] Host video: mọi đường dẫn khác (kể cả `/`, `/index.php`, `/videolab/tusXYZ`) trả 404 ngay tại Nginx; chỉ `/videolab/tus` (+ `/videolab/tus/{guid}`) và `/videolab/cdn/*` mở ra Internet
- [ ] CORS VideoLab: origin = `ADMIN_URL` (upload), `FRONTEND_URL` + `ADMIN_URL` (phát), không credentials

## 4. Redis

- [ ] KHUYẾN NGHỊ (R2): Redis RIÊNG (instance nhỏ) cho queue `video`; app và worker đặt `REDIS_VIDEO_HOST/PORT/USERNAME/PASSWORD` (connection `video`, mẫu ACL `redis/users.video-instance.acl`), worker đặt `CACHE_STORE=array` và không có thông tin Redis chính. Lý do: user worker có `+eval` nên worker bị chiếm có thể chạy script vòng lặp vô hạn làm Redis ngừng phục vụ; nếu dùng chung Redis thì rủi ro còn lại là mất sẵn sàng toàn site (không phải rò dữ liệu): giám sát độ trễ Redis (`redis-cli --latency`) và chấp nhận có ghi nhận. Không đặt `REDIS_VIDEO_*` thì queue `video` dùng Redis chính (local)
- [ ] Redis >= 7.0 dùng ACL file (`aclfile`), mẫu `infra/production/redis/users.acl` (C4-M1; file ACL KHÔNG được có comment `#`, giải thích ở `redis/README.md`): user `default` cho app, user `vv_worker_video` riêng cho worker-video (hash SHA-256, không ghi mật khẩu rõ; không dùng cùng `requirepass`). Thay `<PREFIX>`, `<CACHE_PREFIX>` (tên key thật = `<REDIS_PREFIX><CACHE_PREFIX>illuminate:...`, không có `:` ở giữa). Nạp lại: `ACL LOAD`
- [ ] Host web `vitaminvui.vn` có `limit_req` zone `vv_web` theo IP khách thật (ADR-004 §2.8; khởi điểm 10r/s, burst 200). `/_next/static/` không bị giới hạn. Trên staging, thử một lớp học chung NAT (hoặc k6 ~40 người dùng từ 1 IP): không bị 429. Theo dõi số 429 ở access log của host web và của listener `:8081`; 429 ở `:8081` khi không có `X-Client-IP` nghĩa là trần tổng SSR bị cạn (bot phân tán), cần báo Architect xem lại phương án (c) của §2.8
- [ ] Chạy `infra/production/redis/check-acl.sh <host> <port> <PREFIX> <CACHE_PREFIX> vv_worker_video "$MAT_KHAU_WORKER"` (`SKIP_SIGNALS=1` với instance riêng): phải in `ACL đạt.`. Nội dung kiểm: user worker bị `NOPERM`: `GET`/`SCAN` ở DB 1 (phiên), `LPUSH`/`RPUSH <PREFIX>queues:default x`, `EVAL "return redis.call('rpush','<PREFIX>queues:default','x')" 0`, `SET` hay `DEL` bất kỳ. Và chạy được: `queue:work redis_video` (pop, release, delete, đọc 3 key tín hiệu restart/pause). Sau mỗi lần nâng cấp Laravel (đổi Lua queue hoặc khoá tín hiệu) chạy lại kiểm này
- [ ] Redis có `requirepass` (mật khẩu mạnh) hoặc ACL ở trên, chỉ nghe mạng nội bộ (`bind` IP nội bộ, firewall chặn 6379 từ ngoài), `protected-mode yes`
- [ ] DB tách: session=1, cache=2, queue=3, limiter=4 (`REDIS_DB_SESSION`, `REDIS_CACHE_DB`, `REDIS_QUEUE_DB`, `REDIS_LIMITER_DB`); `cache:clear` không làm mất phiên (T05-2)
- [ ] Staging và production KHÔNG chung một instance Redis; nếu bắt buộc chung thì `REDIS_PREFIX` và số DB khác nhau
- [ ] `REDIS_PREFIX` của app và worker-video giống nhau trong cùng môi trường
- [ ] Persistence phù hợp: phiên học sinh nằm ở Redis; chấp nhận mất phiên khi Redis restart hoặc bật AOF (`appendonly yes`) nếu PO không muốn học sinh bị đăng xuất

## 5. MySQL

Mẫu: `infra/production/mysql/grants.sql`.

- [ ] MySQL 8.4; chỉ nghe mạng nội bộ; không dùng `root` cho app
- [ ] User `vv_app` (php-fpm, queue, scheduler): SELECT/INSERT/UPDATE/DELETE, không DDL
- [ ] User `vv_app` có DELETE trên `audit_logs` (cho `audit:purge`, T30); kiểm `php artisan audit:purge --dry-run` rồi chạy thật một lần ở staging
- [ ] User `vv_migrate` riêng cho `php artisan migrate --force` lúc deploy (DDL); không để app chạy bằng user này
- [ ] User `vv_worker_video` RIÊNG, chỉ quyền trên `vl_videos` (SELECT/INSERT/UPDATE) và INSERT `failed_jobs`; kiểm: đăng nhập bằng user này, `SELECT * FROM users` bị từ chối (T12-6, T12-10)
- [ ] Mật khẩu 3 user khác nhau; không dùng lại ở staging
- [ ] Charset/collation `utf8mb4`; `sql_mode` mặc định strict; múi giờ máy chủ UTC hoặc đúng `APP_TIMEZONE`
- [ ] Đo p95 `GET /me/courses` (log kênh `learning`, `slow`) trên staging với dữ liệu thật, mốc 300 ms (T23-3, DBA #10)

## 6. Queue, scheduler, Supervisor

Mẫu: `infra/production/supervisor/vitaminvui.conf`.

- [ ] Worker `queue:work redis --queue=default,exports` chạy bằng Supervisor, `autorestart=true`, user `www-data`, `numprocs>=2`
- [ ] Worker `worker-video` (`queue:work redis_video --queue=video --timeout=3600`) chạy tách máy/container với image build sẵn (mục 7); `retry_after` (3900) > `--timeout` (3600)
- [ ] Scheduler: một tiến trình `schedule:work` (hoặc cron `* * * * * php artisan schedule:run`) mỗi máy; các lệnh đã `onOneServer`, cần cache Redis dùng chung
- [ ] Deploy xong chạy `php artisan queue:restart`
- [ ] `php artisan schedule:list` có đủ: `counters:recount`, `videos:check-stuck`, `videos:prune-orphans`, `videolab:notify` (mỗi phút, C4-M1), `videolab:cleanup`, `quizzes:auto-submit-expired`, `otp:prune`, `audit:purge`, `users:purge-unverified`, `queue:prune-failed`, `queue:monitor`, `ops:health`
- [ ] `php artisan ops:health` báo worker và scheduler còn sống; cảnh báo của kênh log lỗi được nối vào kênh thông báo của hạ tầng (email/chat)
- [ ] `failed_jobs` trống hoặc dưới ngưỡng `OPS_FAILED_JOBS_MAX`; có người xem hằng ngày

## 7. worker-video (image build sẵn)

- [ ] Image build sẵn từ `infra/worker-video/Dockerfile` (hoặc tương đương), mã nguồn COPY vào image, KHÔNG mount thư mục mã nguồn như local
- [ ] Chạy user không phải root; rootfs chỉ đọc; `cap_drop: ALL`; `no-new-privileges`; `cpus: 2`, `mem_limit: 2g`, `pids_limit: 256`
- [ ] Không có `.env` của app trong image/container; biến môi trường nạp từ file riêng (`infra/production/.env.worker-video.example`)
- [ ] `APP_ENV`, `APP_KEY`, `REDIS_PREFIX` đặt riêng cho worker (prefix giống app, `APP_KEY` khác)
- [ ] Worker đặt `VIDEOLAB_ENABLED=false` nên KHÔNG có khoá `VIDEOLAB_*` của app; ProductionConfigGuard chạy cả trong `queue:work` nên env worker vẫn phải có `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` hợp lệ (đã có trong mẫu). Test `tests/Feature/T31/WorkerVideoEnvTest.php` bị Skipped trong container `php` (không mount `infra/`); trước khi lên staging kiểm tay: nạp env worker rồi chạy `php artisan about` trong container worker, không được ném lỗi `ProductionConfigGuard`
- [ ] DB user `vv_worker_video` (mục 5) và Redis user `vv_worker_video` (mục 4, `REDIS_USERNAME`/`REDIS_PASSWORD` trong env worker); mạng chỉ tới Redis/MySQL nội bộ, không ra Internet. Worker KHÔNG dispatch/gọi webhook: video xong/lỗi thì `videolab:notify` (scheduler của app, mỗi phút) dispatch `SendVideoLabWebhookJob` vào queue `default` và worker `queue` của app gửi (C4-M1). Env worker không có `REDIS_DB_SESSION`/`REDIS_LIMITER_DB`. Worker không ghi nhịp `ops:health` (listener `Looping` bỏ qua connection `redis_video`)
- [ ] Chỉ mount volume `videolab` (đọc/ghi)
- [ ] Gửi thử một video nhỏ: upload TUS, transcode xong, phát được, webhook về app trong ~1 phút (qua `videolab:notify`); `vl_videos.notified_at` được điền; `redis-cli` bằng user app: `LLEN <PREFIX>queues:default` không tăng do worker
- [ ] `videolab:cleanup` chạy (scheduler) và dung lượng đĩa `videolab` được giám sát
- [ ] (R8 review cụm 4) Nếu tách Redis riêng cho `video`: app và worker PHẢI cùng trỏ một Redis video (cùng `REDIS_VIDEO_HOST/PORT/DB`, cùng `REDIS_PREFIX`). Lệch cấu hình thì job kẹt âm thầm, không có lỗi. Sau khi gửi thử video: `redis-cli -h <redis-video> --user vv_worker_video ... LLEN <PREFIX>queues:video` phải về `0` và video có `status=4`; giám sát định kỳ `LLEN` queue `video` (cảnh báo nếu > 0 quá 15 phút)
- [ ] (R9 review cụm 4) Deploy worker-video: worker dùng `CACHE_STORE=array` nên `php artisan queue:restart`/`queue:pause` KHÔNG tới được worker. Quy trình: (1) dừng nhận job mới bằng cách dừng container/Supervisor program `worker-video` với tín hiệu `SIGTERM` (worker xử lý xong job hiện tại rồi thoát; đặt `stopwaitsecs`/`stop_grace_period` ≥ `encode_timeout` + 60 giây), (2) chạy migrate nếu có, (3) khởi động image mới. Job ffmpeg bị cắt ngang (quá hạn chờ) sẽ được thử lại 1 lần khi worker mới lên (`tries`), video không mất. Worker cũng tự thoát sau `--max-time=3600`

## 8. Chính sách lưu log

| Dữ liệu | Thời hạn | Cấu hình | Ghi chú |
|---|---|---|---|
| Log kênh `playback` (IP + UA, T13) | 90 ngày | `LOG_DAILY_DAYS=90` | Dữ liệu cá nhân: ghi vào chính sách lưu log có IP; chỉ nhóm vận hành truy cập |
| `payments`, `video` | 90 ngày | `LOG_DAILY_DAYS=90` | Không chứa PII/secret |
| Kênh `learning` (thời gian xử lý, để đo p95) | 14 ngày mặc định; theo `LOG_DAILY_DAYS` nếu biến này đặt | `max_files` của kênh | Đặt 90 hay 14 do PO quyết; không chứa IP |
| `audit_logs` (IP, user-agent) | 24 tháng | `OPS_AUDIT_RETENTION_MONTHS=24`, lệnh `audit:purge` | Pháp chế (V2) xác nhận 24 tháng |
| `otp_codes` | 7 ngày | `OPS_OTP_RETENTION_DAYS` | `otp:prune` |
| `failed_jobs` | 720 giờ | `OPS_FAILED_JOBS_RETENTION_HOURS` | `queue:prune-failed` |
| Tài khoản chưa xác thực | 7 ngày | `OPS_UNVERIFIED_ACCOUNT_DAYS` | `users:purge-unverified`; pháp chế xác nhận xoá cả `consents` |

- [ ] `LOG_STACK=daily` (không `single`), `LOG_LEVEL=warning` hoặc cao hơn ở production. Kênh `payments`, `playback`, `learning` cố định mức `info` (không bị `LOG_LEVEL` làm mất, C4-L1); kiểm: phát một bài rồi `storage/logs/playback-*.log` có dòng mới
- [ ] `logrotate`/`max_files` đã áp; thư mục `storage/logs` quyền 0750; log không gửi sang dịch vụ ngoài chưa được duyệt
- [ ] Không log body `/auth/*`, không log mật khẩu/OTP/token/chữ ký (kiểm lại bằng một lượt đăng ký + đăng nhập ở staging, đọc log)
- [ ] Tài liệu chính sách lưu log (có IP) đã công bố nội bộ; bản công khai thuộc T34 (V2)

## 9. DNS và chống subdomain takeover (S6)

- [ ] Liệt kê toàn bộ bản ghi DNS của `vitaminvui.vn`, `vitaminvui-staging.vn`, `vitaminvui-media.net`, `vitaminvui-staging-media.net` (xuất zone file, lưu lại)
- [ ] Mọi CNAME/A/AAAA trỏ về tài nguyên còn tồn tại và còn thuộc quyền kiểm soát (không CNAME tới dịch vụ đã xoá: Heroku, S3, Azure, GitHub Pages, Vercel...); kiểm từng bản ghi bằng `dig` và `curl -I`
- [ ] Không có wildcard DNS (`*.vitaminvui.vn`) trừ khi có lý do ghi rõ; xoá bản ghi thử nghiệm
- [ ] Chỉ tồn tại các host đã chốt: `@`, `admin`, `api`, `admin-api`, `video` (+ `www` chuyển về `@` nếu dùng); tên miền tĩnh chỉ `static`
- [ ] Bản ghi CAA giới hạn CA được cấp chứng chỉ; bật DNSSEC nếu nhà đăng ký hỗ trợ
- [ ] SPF, DKIM, DMARC cho `no-reply@vitaminvui.vn` (OTP không vào spam); kiểm bằng gửi thử tới Gmail
- [ ] Tên miền: bật khoá chuyển nhượng, tự gia hạn, 2FA tài khoản nhà đăng ký; người giữ tài khoản đã ghi nhận
- [ ] Lặp lại rà DNS mỗi quý và mỗi khi tắt một dịch vụ

## 10. So sánh `php artisan about` giữa staging và production

- [ ] Trên mỗi môi trường chạy `php artisan about --json > about-<env>.json` (sau khi `config:cache`)
- [ ] `diff` hai file: khác biệt chỉ được ở tên môi trường/URL/tên miền/cookie/driver cổng thanh toán sandbox; PHP, Laravel, extension, driver cache/queue/session/log/mail, `debug`, `maintenance`, các cờ cache (config/events/routes/views) phải giống nhau
- [ ] Cả hai: `Environment` đúng, `Debug Mode: OFF`, `Config`/`Routes`/`Events`/`Views` CACHED
- [ ] Khác biệt có chủ đích, không coi là lỗi: `Model::shouldBeStrict` (lazy loading ném lỗi) chỉ bật ở môi trường khác `production` (staging bắt N+1); `ConfigureHostContext` ép cookie Secure theo `isProduction()` hoặc request HTTPS (staging qua HTTPS vẫn Secure); allowlist MoMo chỉ production
- [ ] Ghi kết quả so sánh (ngày, người kiểm) vào biên bản go-live

## 11. Backup và rollback

- [ ] MySQL: sao lưu đầy đủ hằng ngày + binlog (khôi phục theo thời điểm); lưu ở nơi khác máy chủ DB; mã hoá; giữ tối thiểu 30 ngày
- [ ] Đã thử khôi phục một bản sao lưu lên máy trống và chạy được (ghi ngày thử); lặp lại mỗi quý
- [ ] Thư mục `uploads` (ảnh khoá học) sao lưu; `videolab` (hls, source) sao lưu hoặc có phương án transcode lại từ bản gốc (`VIDEOLAB_KEEP_SOURCE`)
- [ ] Sao lưu `.env`/secret ở nơi an toàn riêng (không chung kho mã), có quy trình khôi phục
- [ ] Triển khai theo release có symlink (`current` -> `releases/<id>`): giữ >= 3 bản release gần nhất
- [ ] Trước deploy: bản sao lưu DB mới; `php artisan down` nếu có migration phá cấu trúc
- [ ] Migration luôn có `down()`; migration phá dữ liệu (xoá cột/bảng) phải tách thành hai bước (deploy mã trước, xoá cột sau)
- [ ] Quy trình rollback đã diễn tập ở staging: đổi symlink về release trước -> `php artisan queue:restart` -> (nếu cần) `php artisan migrate:rollback --step=N` bằng user `vv_migrate` -> xoá cache cấu hình -> kiểm `ops:health`
- [ ] Rollback KHÔNG chạy `migrate:fresh`, `migrate:reset`, `db:wipe` trên môi trường thật
- [ ] Kiểm sau deploy (smoke test): đăng ký/đăng nhập, danh mục khóa học, vào học một bài, upload video nhỏ (admin), `ops:health` xanh

## 12. Kiểm tra cuối trước go-live

- [ ] `composer install --no-dev --optimize-autoloader`; `php artisan config:cache route:cache event:cache view:cache`
- [ ] `php artisan migrate --status` không còn migration chưa chạy
- [ ] Không có Telescope/Debugbar/Pulse bị lộ; nếu có Horizon thì gate chỉ cho admin
- [ ] Tài khoản admin đầu tiên tạo bằng `php artisan staff:create`, đổi mật khẩu lần đầu, bật MFA; không còn tài khoản mẫu/seed
- [ ] `security review` T31 (laravel-security) đã chạy trên staging
- [ ] Thử xoá cờ: tắt `FEATURE_PAID_CHECKOUT` và chắc chắn `/checkout` đơn có tiền trả 503, đơn 0đ vẫn hoạt động

## 13. Điểm chờ pháp chế (V2)

Không chặn go-live MVP nếu PO chấp nhận rủi ro; ghi lại ở đây để không bị bỏ sót.

- [ ] Thời hạn giữ `audit_logs` 24 tháng và log `playback` 90 ngày (có IP + UA): pháp chế xác nhận
- [ ] Xoá `consents` khi `users:purge-unverified` xoá tài khoản chưa xác thực: pháp chế xác nhận
- [ ] T29 (xác nhận phụ huynh, ngưỡng tuổi) và T34 (quyền dữ liệu cá nhân, chính sách quyền riêng tư công khai): V2; `FEATURE_PARENT_CONSENT_ENFORCED=false` cho tới khi có nội dung pháp lý
- [ ] `PRIVACY_POLICY_VERSION` khớp văn bản chính sách thực tế khi công bố
- [ ] Điều khoản thanh toán, hoàn tiền và nội dung pháp lý MoMo (V2): cần trước khi bật `FEATURE_PAID_CHECKOUT`
- [ ] Đăng ký thông báo xử lý dữ liệu cá nhân (nếu luật yêu cầu) và lưu trữ dữ liệu trong nước: pháp chế xác nhận

## 14. Cần PO hoặc hạ tầng cung cấp trước khi làm các mục trên

- Tên miền thật: `vitaminvui.vn` (và staging `vitaminvui-staging.vn`), tên miền tĩnh `vitaminvui-media.net` (và `vitaminvui-staging-media.net`), tài khoản DNS
- Cloudflare Turnstile: site key + secret cho staging và production (hai bộ riêng), hostname cho phép
- SMTP (host, port, user, mật khẩu, địa chỉ gửi; SPF/DKIM/DMARC)
- IP: load balancer, app server, Next.js server, IP văn phòng (nếu giới hạn admin)
- Chứng chỉ TLS (hoặc dùng ACME), nơi lưu secret (secret manager)
- Server MySQL, Redis (mật khẩu, mạng nội bộ), nơi lưu backup
- Kênh nhận cảnh báo (`ops:health`, `queue:monitor`)
- Quyết định PO: thời hạn log `learning`; giới hạn truy cập admin theo IP; `FEATURE_ENROLLMENT_DECISION_MAIL`
