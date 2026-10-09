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
- [ ] Trigger `audit_logs` (L2, migration `2026_10_16_100000`): `vv_migrate` phải có quyền `TRIGGER` (đã có trong `grants-users.sql`/`grants-worker.sql`). MySQL bật binary log (mặc định của 8.4) mà user không có SUPER/SET_USER_ID thì cần `log_bin_trust_function_creators=1` (`SET GLOBAL` bằng tài khoản quản trị trước khi migrate); thiếu thì migrate lỗi 1419. **Production: `log_bin_trust_function_creators` chỉ `SET GLOBAL ... = 1` trong lúc chạy migrate (bằng tài khoản quản trị) rồi trả về `0`; KHÔNG ghi cố định vào `my.cnf`** (bản compose local có ghi cố định là chỉ cho dev). Trigger mang `DEFINER = vv_migrate`: KHÔNG xoá/đổi tên user này, nếu không mọi UPDATE/DELETE trên `audit_logs` (kể cả `audit:purge`) báo lỗi 1449 (fail-closed nhưng purge ngừng); nếu bắt buộc đổi thì tạo lại trigger với DEFINER mới. Kiểm sau migrate: `SHOW TRIGGERS LIKE 'audit_logs'` có `audit_logs_block_update` và `audit_logs_block_delete`; bằng `vv_app`, `UPDATE audit_logs SET action='x' LIMIT 1` phải lỗi. Trigger ghi cứng 24 tháng: đổi `OPS_AUDIT_RETENTION_MONTHS` xuống dưới 24 làm `audit:purge` từ chối chạy
- [ ] `SUPPORT_EMAIL` (địa chỉ hỗ trợ ghi trong thư báo đổi email tài khoản, H1) đã đặt và có người đọc
- [ ] Tên cookie có tiền tố `__Host-` (L3, chống cookie tossing từ subdomain cùng site): production `SESSION_COOKIE=__Host-vv_session` / `SESSION_ADMIN_COOKIE=__Host-vv_admin_session`; staging `__Host-vvstg_session` / `__Host-vvstg_admin_session`. Trình duyệt chỉ nhận tiền tố này khi Secure + `Path=/` + không có Domain (đã đúng nhờ `SESSION_SECURE_COOKIE=true`, `SESSION_PATH=/`, `SESSION_DOMAIN=null`). Local/test giữ `vv_session` (http). Sau khi bật: mọi phiên đang mở bị đăng xuất một lần (đổi tên cookie). Kiểm: `curl -sI https://api.<domain>/api/v1/csrf-token -H 'Origin: https://<domain>'` phải có `Set-Cookie: __Host-vv_session=...; secure; path=/` và KHÔNG có `domain=`; tương tự `__Host-vv_admin_session` ở admin-api. Nginx/CDN không được thêm `Domain=` vào Set-Cookie (`proxy_cookie_domain`)
- [ ] `SANCTUM_STATEFUL_DOMAINS` đúng bằng host của `FRONTEND_URL` và `ADMIN_URL`, không thừa không thiếu (guard so khớp chính xác; chặn `*`, `localhost`, `127.0.0.1`, `::1`, `0.0.0.0`, tên miền lạ). `APP_URL`/`FRONTEND_URL`/`ADMIN_URL` phải `https` (C4-L5). Env worker-video cũng phải có `FRONTEND_URL`/`ADMIN_URL` (xem mẫu)
- [ ] `TRUSTED_PROXIES` là danh sách IP cụ thể của load balancer và server Next.js, KHÔNG `*`, KHÔNG để rỗng (guard chặn khi rỗng ở tiến trình web; chỉ tiến trình console như `queue:work` của worker-video được để rỗng). Sai/rỗng thì token CDN ràng IP và `Location` của TUS sai scheme, và mọi học sinh dùng chung một IP (proxy) nên trần lần sai theo IP của mã giảm giá/đăng nhập khoá lẫn nhau
- [ ] `INTERNAL_API_TOKEN` là chuỗi hex >= 32 ký tự (`openssl rand -hex 32`; guard chặn giá trị không phải hex, C4-M2) (T26-1). `INTERNAL_API_REQUIRED=true` CHỈ bật khi bản frontend gửi header (FW2 trở đi) đã được triển khai (xem mục "FE" ngay dưới). Bật sớm thì app production không khởi động khi token rỗng. Để token rỗng thì catalog throttle tính chung một bucket theo IP của Next server (log warning khi boot)
- [ ] FE (ADR-004 §2.8): env của app web có `INTERNAL_API_TOKEN` (giống hệt backend) và `API_INTERNAL_URL=http://<IP_NOI_BO_NGINX>:8081` (IP trần). `fetch` của Node **không đặt được header `Host`** (hostname trong URL chính là Host). Vì vậy listener `:8081` phải **ép** `fastcgi_param HTTP_HOST api.<domain>` (mẫu `infra/production/nginx/conf.d/vitaminvui.conf`, từ Sửa lỗi nhỏ 4). KHÔNG dùng cách đặt `Host` bằng tay (chỉ curl làm được). KHÔNG khuyến nghị trỏ `api.<domain>` về IP nội bộ bằng `/etc/hosts`/`extra_hosts`/DNS nội bộ: cách này đổi đích mọi lời gọi tới tên miền công khai từ máy Next. Nếu hạ tầng buộc phải dùng DNS nội bộ thì `API_INTERNAL_URL=http://api.<domain>:8081`; cách này vẫn chạy vì Host bị ép ở Nginx. Kiểm từ máy Next, không đặt Host: `curl -s -o /dev/null -w '%{http_code}' http://<IP_NOI_BO_NGINX>:8081/api/v1/subjects` phải ra `200` (nếu ra `404` hoặc mã lỗi host thì Nginx chưa ép Host). Token chỉ ở server Next, không xuống trình duyệt
- [ ] Next.js (`next start`) chỉ nghe loopback/mạng nội bộ, KHÔNG mở cổng 3000/3001 ra Internet: Next lấy IP khách từ `X-Forwarded-For` do Nginx ghi đè, gọi thẳng Next thì khách giả được IP (né hạn mức tìm kiếm theo IP)
- [ ] `DB_*` dùng user `vv_app` (không root), `REDIS_PASSWORD` đã đặt (app dùng user Redis `default` trong `redis/users.acl`)
- [ ] PHP: `php -i | grep -E '^(display_errors|display_startup_errors|log_errors|expose_php)'` ra `Off`, `Off`, `On`, `Off` trên CẢ CLI và FPM (`php-fpm -i`) (C4-L3). Chạy bằng Docker (ADR-008): kiểm trong container, `docker compose -p vvstack exec php php -i` và `docker compose -p vvstack exec php php-fpm -i` (image `infra/php/Dockerfile.prod` kế thừa `php.ini-production`; `smoke/check-images.sh` kiểm sẵn). Lỗi trước khi Laravel boot (lỗi cú pháp, vendor hỏng) phải chỉ vào log, không vào response
- [ ] `MAIL_*` là SMTP thật, `MAIL_MAILER` KHÔNG là `log`/`array` (guard chặn, GL-1/D6); gửi thử một OTP về hộp thư thật
- [ ] `CAPTCHA_DRIVER=turnstile`, `TURNSTILE_SITE_KEY` và `TURNSTILE_SECRET` là key thật của Cloudflare (không phải key test `1x0000...`). Guard chặn `TURNSTILE_SECRET` rỗng hoặc chỉ khoảng trắng khi `CAPTCHA_DRIVER=turnstile` (GL-1/D6); guard không phân biệt được key test với key thật nên vẫn kiểm tay bằng đăng ký thử
- [ ] Widget Turnstile chỉ cho phép hostname production; staging dùng widget và key riêng (server không kiểm `hostname`/`action`, nên giới hạn ở phía Cloudflare). Guard chặn khoá test `1x0000…`/`2x0000…`/`3x0000…` (site key lẫn secret) và `TURNSTILE_SECRET` rỗng (GL-1/A5)
- [ ] `REDIS_PASSWORD` không rỗng và `DB_USERNAME` không phải `root` (guard chặn cả hai, GL-1/A5); worker-video dùng user Redis/DB riêng của nó
- [ ] Không dùng lại token/mật khẩu của máy dev ở staging/production: `INTERNAL_API_TOKEN` trong `frontend/apps/web/playwright.fw8qa.config.ts`, mật khẩu tài khoản demo (mặc định `DEMO_ACCOUNT_PASSWORD` trong `config/auth.php`, và các tài liệu board/QA). KHÔNG chạy `db:seed` (seeder chỉ chạy ở `local`); nếu staging buộc phải có tài khoản mẫu thì đặt `DEMO_ACCOUNT_PASSWORD` riêng
- [ ] `AUTH_OTP_CHANNELS=email`
- [ ] Thư thông báo phụ huynh (ADR-006/T29): `PRIVACY_NOTICE_TOKEN_KEY` đặt riêng >= 32 byte, không xoay (guard chặn khi thiếu/ngắn); `PRIVACY_PARENT_NOTICE_DAILY_CAP` trong 1..20 (guard); `PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP` (mẫu 500); `PRIVACY_POLICY_VERSION` không rỗng (guard); `PRIVACY_PARENT_CONTACT_SUGGEST_AGE`. Mẫu: `infra/production/.env.production.example`
- [ ] Đơn thủ công (US-022/T38), các biến `ORDERS_*` do guard kiểm khi khởi động: `ORDERS_CUSTOMER_NOTE_RETENTION_DAYS` (30..3650, mẫu 90) và `ORDERS_STAFF_TEXT_RETENTION_DAYS` (1..3650, mẫu 7) luôn được kiểm (độc lập cờ). Khi `FEATURE_MANUAL_PAYMENT=true` thì thêm: `ORDERS_MANUAL_PENDING_TTL_HOURS` (1..168), `ORDERS_MANUAL_APPROVAL_WINDOW_DAYS` (0..90), `ORDERS_MANUAL_PER_DAY` (1..50) phải là số nguyên không dấu (guard so chuỗi thô, `abc` bị chặn); `ORDERS_MANUAL_NOTIFY_EMAILS` (danh sách email hợp lệ, không rỗng; nếu bỏ trống code lùi về `SUPPORT_EMAIL`); ít nhất một kênh `PAYMENT_CONTACT_PHONE` / `PAYMENT_CONTACT_ZALO_URL` (dạng `https://zalo.me/<id>`) / `PAYMENT_CONTACT_EMAIL` (hợp lệ); `PAYMENT_CONTACT_HOURS` tối đa 100 ký tự, không HTML. Code lùi `PAYMENT_CONTACT_EMAIL` về `SUPPORT_EMAIL` nên kênh email gần như luôn có: xác nhận số điện thoại/Zalo thật trước khi bật. Mẫu env để `FEATURE_MANUAL_PAYMENT=false`
- [ ] `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`
- [ ] V1: `VIDEO_PROVIDER=bunny`, `VIDEO_ENABLED_PROVIDERS=bunny`, `VIDEOLAB_ENABLED=false` (Bunny; mục 2.1). `internal`/VideoLab chỉ khi PO bật lại
- [ ] `VIDEOLAB_*`: xem mục 2
- [ ] Dùng Bunny (US-021/T37): `VIDEO_PROVIDER=bunny`, `VIDEO_ENABLED_PROVIDERS=bunny,internal` (bỏ `internal` khi hết video VideoLab cũ), đủ `BUNNY_LIBRARY_ID`, `BUNNY_API_KEY`, `BUNNY_CDN_HOST`, `BUNNY_TOKEN_KEY`: xem mục 2.1

### 1.2 Cấm ở staging và production (guard ném lỗi khi khởi động, trừ khi ghi chú khác)

| Biến/giá trị | Lý do | Guard |
|---|---|---|
| `APP_DEBUG=true` | lộ stack trace, secret | có; guard ép `app.debug=false` trước khi ném lỗi nên route ngoài `api/*` cũng không render trang debug (C4-L4) |
| `SESSION_SECURE_COOKIE=false` | cookie phiên đi qua HTTP | có |
| `CAPTCHA_DRIVER=fake` (mọi kiểu viết hoa) | bỏ qua chống bot | có (T31: thêm staging) |
| `CACHE_LIMITER` trỏ store không phải `redis`; `AUTH_*LOGIN_*` ngoài khoảng cho phép (ngưỡng captcha 1..20, trần tài khoản >= 20 và > ngưỡng+10, trần IP >= 20, trần IP có captcha >= 100); `AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE[_IP]` < 10 | bộ đếm đăng nhập không nguyên tử / env rỗng = 0 làm sập hoặc khoá đăng nhập (GL-A2) | có (GL-A2) |
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
| Bunny đang bật mà thiếu `BUNNY_LIBRARY_ID`/`API_KEY`/`CDN_HOST`/`TOKEN_KEY`, hoặc `BUNNY_WEBHOOK_TOKEN` thiếu/ngắn hơn 32 ký tự; `BUNNY_CDN_HOST` http, IP, trùng host app/web/admin/api, hoặc nằm dưới `SESSION_DOMAIN`; `BUNNY_API_BASE`/`BUNNY_TUS_ENDPOINT` không https | không khởi động / cookie phiên gửi sang CDN | có (US-021 BR11); thông báo chỉ nêu tên biến |
| `VIDEOLAB_API_KEY`/`TOKEN_KEY`/`WEBHOOK_SECRET` thiếu hoặc < 32 ký tự | khoá yếu/đoán được | có (T31, khi VideoLab bật) và `VideoLabServiceProvider` |
| `FEATURE_PAID_CHECKOUT=true` mà `PAYMENT_GATEWAYS` rỗng | checkout lỗi giữa chừng | có (T31) |
| `FEATURE_PAID_CHECKOUT=true` khi `payments.ipn_ready=false` (chưa có IPN T19/đối soát T20) | HS trả tiền nhưng không được ghi danh | có (cụm 3 L1; T19 đổi hằng `ipn_ready` trong `config/payments.php`) |
| `FEATURE_STAFF_MFA=false` | bỏ MFA quản trị | có (cụm 1 L1) |
| `TRUSTED_PROXIES` rỗng ở tiến trình web | mọi người dùng chung IP proxy | có (minor-fixes-3 R2) |
| (lưu ý R9) Guard miễn kiểm `TRUSTED_PROXIES` rỗng, `TURNSTILE_SECRET` rỗng và `REDIS_PASSWORD` của connection `default` cho tiến trình console (chung cờ `app.trusted_proxies_console_exempt`) dựa vào `runningInConsole()` | nếu chuyển web sang Octane/Swoole/RoadRunner (chạy bằng CLI) thì guard bị vô hiệu cho cả web, mất CÙNG LÚC cả ba lớp kiểm: phải xem lại điều kiện miễn (vd. chỉ miễn `queue:work`, `schedule:*`) trước khi đổi | không (kiểm tay khi đổi runtime) |
| `MOMO_ENDPOINT` khác `https://payment.momo.vn` | sandbox lọt production | chỉ production (staging được dùng sandbox) |
| `MOMO_PAY_URL_HOSTS` khác đúng `payment.momo.vn` | chuyển hướng sang host lạ (T17-2) | chỉ production (T31) |
| `AWS_*` thừa, `FAKE_*`, Telescope/Debugbar cài ở production | bề mặt thừa | không: kiểm tay (`composer install --no-dev`) |
| Dùng lại khoá/mật khẩu production ở staging (và ngược lại) | staging bị lộ kéo theo production | không: kiểm tay |
| `TURNSTILE_SECRET` rỗng/khoảng trắng khi `CAPTCHA_DRIVER=turnstile` | captcha không xác minh được | có (GL-1/D6); tiến trình console được miễn (xem lưu ý R9) |
| `TURNSTILE_SECRET`/`TURNSTILE_SITE_KEY` bắt đầu bằng `1x0000`, `2x0000`, `3x0000` (khoá test Cloudflare) | khoá `1x` luôn thành công: mất lớp chống bot | có (GL-1/A5), mọi tiến trình |
| `MAIL_MAILER` là `log`/`array` (hoặc rỗng) | OTP, thư phụ huynh, thư đơn không tới người nhận | có (GL-1/D6) |
| Mật khẩu Redis rỗng: `default` (web) và `video` (web và worker-video; chấp nhận mật khẩu trong `REDIS_URL`/`REDIS_VIDEO_URL`) | Redis không xác thực | có (GL-1/A5); worker chỉ cần `REDIS_VIDEO_PASSWORD` |
| `DB_USERNAME=root` | ứng dụng chạy bằng quyền tối đa | có (GL-1/A5) |

Guard CHƯA kiểm (kiểm tay): `MAIL_HOST` rỗng khi `MAIL_MAILER=smtp`, khoá Turnstile thật hay giả (chỉ chặn khoá test của Cloudflare).

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
| `FEATURE_PARENT_NOTICES` | true | **true** | Thư THÔNG BÁO cho phụ huynh (ADR-006, T29): khi tạo tài khoản, thêm/đổi email phụ huynh, đơn có tiền đã thanh toán. **Mail thật (SMTP + queue worker) phải chạy trước go-live.** `false` = công tắc tắt khẩn (không gửi thư, request gốc vẫn thành công). Đã bỏ `FEATURE_PARENT_CONSENT_ENFORCED` |
| `FEATURE_EXTERNAL_VIDEO_PREVIEW_ONLY` | true | true | Link ngoài chỉ cho bài học thử |
| `FEATURE_MANUAL_PAYMENT` | false | **false** khi deploy, rồi **true** ở bước 2 (V1 cần cờ này bật) | US-022: thanh toán thủ công (học sinh đặt đơn, QTV duyệt tay). Mẫu production để `false`. Chỉ bật sau: T39 + FA8 đã deploy, `PAYMENT_CONTACT_*` + `ORDERS_MANUAL_NOTIFY_EMAILS` là thật và hộp thư có người trực, scheduler chạy `orders:expire-manual`, QTV được hướng dẫn quy trình duyệt/huỷ. Bật mà thiếu kênh liên hệ hoặc danh sách nhận thư: guard chặn khởi động. Tắt khẩn: đặt `false` rồi `config:cache` + `queue:restart` (đơn cũ vẫn duyệt/huỷ được; xác nhận trên staging) |

- [ ] Từng cờ đã đối chiếu bảng trên; `GET /api/v1/config/public` trả `paid_checkout_enabled=false`

**Thứ tự bật cờ (K3).** Deploy với `FEATURE_PAID_CHECKOUT=false`, `FEATURE_MANUAL_PAYMENT=false`, `FEATURE_PARENT_NOTICES=false`, `VIDEO_PROVIDER=bunny` (đủ `BUNNY_*`); migration xong và smoke test đăng nhập/học đạt; sau đó từng bước, mỗi bước có người ký:

- [ ] Bước 1 `FEATURE_PARENT_NOTICES=true`, chỉ khi: SMTP gửi thật OK + queue worker chạy, trang FW7 `/phu-huynh/huy-nhan-thong-bao` đã lên, `PRIVACY_NOTICE_TOKEN_KEY` đã đặt, Nginx đã che `?t=` (mục 3, GL-1/D1) và log Next/LB đã kiểm, pháp chế đã xem nội dung thư. Sau khi đổi: `config:cache` + `queue:restart`
- [ ] Bước 2 `FEATURE_MANUAL_PAYMENT=true`, điều kiện ở dòng cờ trên
- [ ] Bước 3 `VIDEO_PROVIDER=bunny` là mặc định V1 (Bunny C1–C9 đạt trên staging là điều kiện go-live, mục 2.1)
- [ ] `FEATURE_PAID_CHECKOUT` KHÔNG bật ở V1

## 2. Secret và khoá VideoLab

> **V1 dùng Bunny Stream (quyết định PO 2026-10-10)**: `VIDEO_PROVIDER=bunny`, `VIDEOLAB_ENABLED=false`, CDN `BUNNY_CDN_HOST=cdn.vitaminvui.asia`, production KHÔNG mở host `video.` và không chạy VideoLab/worker-video/redis-video (compose profile `videolab`, `VV_VIDEOLAB=0`). **Điều kiện go-live: Bunny C1–C9 (mục 2.1, `docs/qa/T37.md`) đạt trên staging.** Các mục `VIDEOLAB_*`, §3.1 và §7 chỉ áp dụng khi PO bật lại VideoLab.

- [ ] Secret (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `TURNSTILE_SECRET`, `INTERNAL_API_TOKEN`, `VIDEOLAB_*`, `MOMO_*`) nằm trong biến môi trường server hoặc secret manager; không có trong git, ảnh Docker, log
- [ ] File env trên server `chmod 600`, thuộc user chạy dịch vụ, nằm ngoài web root
- [ ] `VIDEOLAB_API_KEY`, `VIDEOLAB_TOKEN_KEY`, `VIDEOLAB_WEBHOOK_SECRET`: mỗi khoá >= 32 ký tự, sinh bằng `openssl rand -hex 32`, KHÁC nhau, KHÁC staging; ngoài local/testing không có suy khoá từ `APP_KEY`
- [ ] `VIDEOLAB_PUBLIC_URL` dùng `https`
- [ ] Khoá MoMo sandbox và production tách riêng (V2); có quy trình xoay khoá và người phụ trách
- [ ] Quy trình xoay `VIDEOLAB_TOKEN_KEY`: đổi khoá làm mọi link phát đang dùng hết hiệu lực (tối đa 15 phút theo `VIDEO_PLAYBACK_TTL_MINUTES`); thực hiện ngoài giờ cao điểm
- [ ] Phiên bản mã không chứa secret: `git log -p -S'VIDEOLAB_' -- .` không thấy giá trị thật

### 2.1 Bunny Stream (US-021/T37)

Làm một lần trên trang Bunny cho mỗi thư viện (production và staging riêng), rồi ghi người làm + ngày vào báo cáo QA. Công thức chữ ký/token trong `BunnySigner` PHẢI được đối chiếu tài liệu Bunny hiện hành và thử URL thật trước khi mở bán (`docs/tech/US-021.md`). Guid video không phải bí mật (nằm trong mọi URL phát, bài preview còn công khai) và `LibraryId` cũng không: toàn bộ kiểm soát truy cập dựa vào cấu hình dưới đây, sót một công tắc là video trả phí xem được vĩnh viễn (review security T37, S3).
- [ ] Tạo Video Library riêng cho production và staging; ghi `BUNNY_LIBRARY_ID`, `BUNNY_API_KEY` (API key của thư viện), `BUNNY_CDN_HOST` (hostname, https), `BUNNY_TOKEN_KEY` (Token Authentication key) vào secret manager; KHÁC nhau giữa hai môi trường. Không đổi `BUNNY_LIBRARY_ID` khi còn video trong thư viện cũ (pruner không xoá dòng asset thuộc thư viện khác, nhưng video sẽ nằm lại ở Bunny)
- [ ] Library > Security: bật **CDN Token Authentication** VÀ **Embed View Token Authentication** (hai cơ chế độc lập: một cho `https://{cdn}/{guid}/...`, một cho `iframe.mediadelivery.net/embed/{LIB}/{GUID}`); chặn truy cập trực tiếp (Block Direct URL File Access); TẮT MP4 Fallback; TẮT Direct Play/embed công khai; TẮT "Keep original files" hoặc xác nhận tệp gốc không truy cập được qua CDN
- [ ] Allowed Referrers gồm host của `FRONTEND_URL` VÀ `ADMIN_URL` (admin cần xem thử bài ở `/admin/.../playback`); đừng bỏ hẳn Referrer
- [ ] Webhook (Library > Webhooks): `https://api.<tên-miền-thật>/api/v1/webhooks/video/bunny?k=<BUNNY_WEBHOOK_TOKEN>`. Bunny không ký webhook nên bí mật nằm trên URL (>= 32 ký tự, `openssl rand -hex 32`, khác giữa các môi trường); app so `hash_equals`, sai/thiếu thì 404. Mẫu Nginx ghi log webhook này theo `$uri` (không có query) vào `/var/log/nginx/vv-webhook.access.log` (`log_format vv_noargs`); kiểm staging: `grep 'k=' /var/log/nginx/*.log` không thấy token. `error_log` Nginx vẫn có thể chép dòng request đầy đủ khi upstream lỗi: log quyền 0640, xoay token (đổi `.env` và URL ở Bunny cùng lúc) khi nghi lộ. Webhook sát nhau bị gom 10 giây sẽ được đồng bộ lại bằng job trễ 15 giây
- [ ] Cảnh báo băng thông/chi phí hằng tháng trên Bunny (ngưỡng do PO chốt, câu hỏi A4) và cảnh báo dung lượng lưu (kích thước thật tệp tải lên không bị ép, chỉ dựa trên khai báo, S2a)
- [ ] CDN host không cùng host với app/web/admin/api và không nằm dưới `SESSION_DOMAIN` (guard chặn) để cookie phiên không gửi sang CDN
- [ ] CSP frontend: admin `NEXT_PUBLIC_VIDEO_UPLOAD_URL=https://video.bunnycdn.com` (`connect-src`); web `NEXT_PUBLIC_VIDEO_HOSTS` thêm CDN hostname (`connect-src`/`media-src`); `chunkSize` TUS <= 8 MB
- [ ] `VIDEO_PLAYBACK_TTL_MINUTES` mặc định 15; config tự kẹp 1-60 phút
- [ ] Kiểm tay phủ định (AC9/AC16, BẮT BUỘC trước go-live; chỉ kiểm "URL hợp lệ phát được" thì PASS cả khi Token Authentication chưa bật). Lấy một `<GUID>` của video `ready`, KHÔNG token, mỗi URL phải trả 403: `https://<cdn>/<GUID>/playlist.m3u8`, một segment `.ts`/`.m4s`, `https://<cdn>/<GUID>/thumbnail.jpg`, `https://<cdn>/<GUID>/play_720p.mp4`, `https://iframe.mediadelivery.net/embed/<LIB>/<GUID>`. Thêm: token hết hạn 403; token đúng nhưng từ IP khác (khi `VIDEO_BIND_IP`) 403; từ tên miền lạ (Referrer) 403; URL hợp lệ ở trình duyệt học sinh phát được, kể cả máy dual-stack IPv4/IPv6
- [ ] Xoay `BUNNY_TOKEN_KEY`: đổi khoá ở Bunny và `.env`, link phát cũ hết hiệu lực (tối đa 15 phút), video không hỏng. Xoay `BUNNY_API_KEY`: đổi ở Bunny và `.env` cùng lúc; chữ ký upload đã cấp (tối đa 6 giờ) sẽ vô hiệu (đây là cách duy nhất thu hồi chữ ký TUS đã cấp, S5)
- [ ] **Chuyển video VideoLab cũ sang Bunny (T37-1, `videos:migrate-provider`)**, theo đúng thứ tự, chạy ở server có đĩa VideoLab (container `php`/`worker-video` mount `VIDEOLAB_PATH`), KHÔNG chạy từ máy khác:
  1. Cấu hình Bunny đủ (các mục trên) và đặt `VIDEO_ENABLED_PROVIDERS=internal,bunny` (giữ `VIDEO_PROVIDER=internal`: video mới vẫn lên VideoLab, học sinh vẫn xem bình thường). Lệnh cần `bunny` trong allowlist; `--delete-source` cần thêm `internal`.
  2. Kiểm tệp gốc còn không: VideoLab chỉ giữ `source/{guid}.bin` 7 ngày (trừ `VIDEOLAB_KEEP_SOURCE=true`). Asset thiếu tệp gốc bị bỏ qua và báo rõ (phải tải lại video).
  3. `php artisan migrate --force` (bảng `video_provider_migrations`), rồi dry-run: `php artisan videos:migrate-provider --from=internal --to=bunny --dry-run` (không gọi mạng, không ghi DB; liệt kê asset sẽ chuyển/bỏ qua).
  4. Chạy thử MỘT video: `php artisan videos:migrate-provider --from=internal --to=bunny --limit=1` (hoặc `--asset=ID`). Chạy lại được nhiều lần: xong rồi thì bỏ qua, dở thì tiếp tục (video Bunny đã nhận tệp không bị PUT lại, không tạo trùng). `--wait=N` (số nguyên >= 0) giây chờ Bunny mã hoá mỗi video, mặc định 300. **Mã thoát: 0 xong hết, 1 có lỗi, 2 không lỗi nhưng còn asset chờ Bunny mã hoá (cron/CI chạy lại).** Khoá chống chạy song song (cache lock) tự gia hạn trước mỗi asset; nếu tiến trình bị kill và khoá kẹt: kiểm chắc không còn tiến trình nào chạy (`ps`), rồi `php artisan videos:migrate-provider --unlock` (hỏi xác nhận).
  5. Kiểm phát NGAY sau video thử: mở bài đã chuyển bằng tài khoản học sinh có quyền, URL phát phải là CDN Bunny (kèm các kiểm 403 ở mục cuối 2.1). Đạt thì chuyển theo LÔ NHỎ (`--limit=5..10`) và kiểm phát một bài của mỗi lô trước khi chạy lô tiếp, không đợi chuyển hết mới kiểm. `SELECT provider, COUNT(*) FROM video_assets GROUP BY provider` để theo dõi.
  6. Chỉ khi mọi video cần giữ đã chuyển: đặt `VIDEO_PROVIDER=bunny` (video mới lên Bunny); `VIDEO_ENABLED_PROVIDERS=bunny,internal` cho tới khi dọn nguồn xong, sau đó chỉ `bunny` và tắt `VIDEOLAB_ENABLED`.
  7. Dọn nguồn (không bắt buộc, không thể hoàn tác): `php artisan videos:migrate-provider --delete-source` (hỏi xác nhận; `--force` bỏ hỏi). Chỉ xoá video VideoLab của asset đã chuyển xong khi Bunny vẫn `ready`. Lưu ý: lệnh này vẫn chạy bước chuyển trước (asset nào chưa chuyển sẽ được chuyển luôn) rồi mới xoá nguồn; kết hợp `--asset=N` thì chuyển + xoá đúng asset N. Xoá nguồn làm vỡ phiên đang xem video đó: chạy ngoài giờ học. Sổ nằm ở `video_provider_migrations` (`from_video_id`, `source_deleted_at`).
  - **Rollback** (chỉ khi CHƯA `--delete-source`): `php artisan videos:migrate-provider --rollback --asset=ID` trả asset về VideoLab theo `from_*` trong sổ `video_provider_migrations` (kiểm VideoLab còn `ready`; sổ thành `rolled_back`; video Bunny được GIỮ, dọn tay ở trang Bunny, guid ghi trong sổ `to_video_id`/audit `video.provider_rolled_back`). Sau `--delete-source` KHÔNG hoàn tác được (lệnh từ chối): phải tải lại video. Mã thoát: 0 chỉ khi đã hoàn tác; mọi trường hợp từ chối trả 1. Vì vậy chỉ `--delete-source` sau khi đã kiểm phát và theo dõi vài ngày.
  - Lỗi Bunny giữa chừng không làm gián đoạn học sinh: asset giữ nguyên VideoLab, lý do ở `video_provider_migrations.last_error`, chạy lại là tiếp tục. Không áp hạn mức tải ngày. Mỗi asset chuyển xong có audit `video.provider_migrated` (không chứa khoá).
- [ ] Log không chứa khoá: `grep` log app sau khi thử, không thấy giá trị `BUNNY_API_KEY`/`BUNNY_TOKEN_KEY`/`BUNNY_WEBHOOK_TOKEN`. Log chỉ có dòng "Bunny từ chối khoá API" khi khoá sai
- [ ] `php artisan config:show video` và `tinker` in khoá dạng rõ: chỉ cấp quyền SSH production cho người cần thiết

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
- [ ] **Che token huỷ nhận thư phụ huynh (GL-1/D1, K4):** link trong thư là `https://<web>/phu-huynh/huy-nhan-thong-bao?t=<token HMAC>`. Mẫu Nginx có `location = /phu-huynh/huy-nhan-thong-bao` ghi `access_log` bằng `log_format vv_noargs` (chỉ `$uri`, không query, không Referer). API nhận token (`POST /api/v1/parent-notices/unsubscribe`) nhận token trong body, không trong URL, nên access log API không chứa token. Kiểm tay TRƯỚC khi bật `FEATURE_PARENT_NOTICES`: (a) bấm thử một link rồi `grep -rE '[?&]t=' /var/log/nginx/` (cả access lẫn error) không ra token; (b) log của Next.js (`next start`, stdout, Docker, journal systemd) không ghi URL đầy đủ; (c) log của load balancer/CDN/WAF phía trước Nginx (nếu có) đã tắt ghi query hoặc che tham số `t`; (d) `error_log` Nginx vẫn có thể ghi `request: "GET /...?t=..."` khi upstream lỗi và KHÔNG che được bằng cấu hình: giữ mức `error`, hạn chế người đọc, xoay vòng ngắn; (e) mẫu đã qua `nginx -t` (nginx:1.27) với IP thật
- [ ] Host admin `admin.vitaminvui.vn` có `limit_req` zone `vv_admin` (mẫu 5r/s, burst 100; RL-2); theo dõi 429 ở access log và chỉnh sau khi đo staging
- [ ] Phản hồi do chính Nginx trả (403, 404, 413, 429) không có `nosniff`/`X-Frame-Options` của Laravel: thêm `add_header X-Content-Type-Options nosniff always;` (và `X-Frame-Options DENY always`) ở cấp `server` cho các host API; kiểm các header API vẫn xuất hiện đúng 1 lần
- [ ] HSTS: Next gửi `includeSubDomains; preload`; chỉ nộp hstspreload sau khi MỌI subdomain đã HTTPS. Bật OCSP stapling; `ssl_ciphers` đang để mặc định, xem lại cùng lúc
- [ ] Header nội bộ `X-Internal-Token` và `X-Client-IP` bị xoá ở mọi host công khai (T26-3); kiểm bằng `curl -H 'X-Client-IP: 1.2.3.4' ...` không đổi IP trong log
- [ ] Server nội bộ `:8081` (mẫu có sẵn): chỉ nghe IP mạng nội bộ, `allow` đúng IP Next server + `deny all`, chỉ GET/HEAD `/api/v1/`, ép `HTTP_HOST` = host api (ADR-004 §2.8), có `access_log` riêng. Kiểm: từ máy khác Next server → 403. Từ Next server, kèm token đúng: có `X-Client-IP` thì request thứ 121/phút cùng IP → 429; KHÔNG có `X-Client-IP` thì 200 request/phút vẫn 200 (chỉ trần tổng `CATALOG_SSR_TOTAL_PER_MINUTE`)
- [ ] Firewall chặn cổng 8081 từ ngoài mạng nội bộ
- [ ] Host web `vitaminvui.vn` có `limit_req` zone `vv_web` theo IP khách thật (ADR-004 §2.8; khởi điểm 10r/s, burst 200). `/_next/static/` không bị giới hạn. Trên staging, thử một lớp học chung NAT (hoặc k6 ~40 người dùng từ 1 IP): không bị 429. Theo dõi số 429 ở access log của host web và của listener `:8081`; 429 ở `:8081` khi không có `X-Client-IP` nghĩa là trần tổng SSR bị cạn (bot phân tán), cần báo Architect xem lại phương án (c) của §2.8

### 3.0 Chung cho mọi host (V1)

- [ ] IP khách thật (ADR-008 "TẠM BỎ Cloudflare proxy", PO 2026-10-10: DNS-only, Nginx là lớp ngoài cùng): `infra/production/nginx/snippets/vv-real-ip.conf` KHÔNG có `set_real_ip_from` và không tin `CF-Connecting-IP`/`X-Forwarded-For` (IP thật = `$remote_addr`); `update-cloudflare-ips.sh` không có trong cron. Kiểm: đăng nhập sai từ 2 máy khác mạng thì access log/audit thấy 2 IP khác nhau (đúng IP máy khách); gửi thử `curl -H 'CF-Connecting-IP: 1.2.3.4'` rồi xem log: IP ghi vẫn là IP thật; 200 lượt sai/giờ từ máy A không làm máy B bị 429. Bật proxy sau này: README mục 12 "Bật Cloudflare proxy".
- [ ] **GL-A2: BE chỉ deploy cùng FE** (form đăng nhập web và admin đã có widget Turnstile, gửi `captcha_token` khi `captcha_required`/`CAPTCHA_*`). BE lên trước thì người ngoài chỉ cần 5 lượt sai/giờ để chặn người thật đăng nhập từ UI. Giá trị `AUTH_LOGIN_*`/`AUTH_STAFF_LOGIN_*` (ngưỡng 5, trần tài khoản 100, trần IP 200 — chờ PO xác nhận) nằm trong khoảng guard cho phép
- [ ] `TRUSTED_PROXIES` chỉ chứa dải mạng Docker nội bộ (`10.231.10.0/24`), KHÔNG `*` và không IP khách; (chỉ khi bật VideoLab) `Location` trả về từ TUS (`POST /videolab/tus`) dùng `https://`
- [ ] (V3-1) `api.`/`admin-api.`: `limit_req` (`vv_api` 50r/s burst 200; `vv_api_auth` 10r/s burst 80 cho `/auth/*`), `limit_conn vv_conn 300`, timeout header 10s/body 15s; ngưỡng đã tính cho lớp ~40 học sinh chung một IP NAT (không 429 oan). Đo lại với một buổi học thật ở staging, chỉnh nếu thấy 429 trong log; hỏi nhà cung cấp về chống DDoS tầng mạng. Gọi thẳng `/index.php` từ ngoài trả 404
- [ ] (V3-3) Mọi khối proxy tới Next ghi đè `X-Forwarded-Host $host`, `X-Real-IP $remote_addr`, `Forwarded ""`

### 3.1 VideoLab (T12-4, T12-5, review T12 R7): CHỈ khi bật VideoLab (V1 không mở host `video.`; file `nginx/optional/videolab.conf` không nạp mặc định)

- [ ] `/videolab/library/` chỉ cho IP app server CỤ THỂ (`allow <IP_APP_SERVER>; deny all;`), không dải private rộng như local
- [ ] `VIDEOLAB_ACCEL_REDIRECT=true` và có `location /_protected_hls/ { internal; alias .../videolab/hls/; }`; kiểm: gọi trực tiếp `/_protected_hls/<guid>/playlist.m3u8` từ ngoài trả 404
- [ ] `limit_req` cho `/videolab/cdn/` (mẫu: 30 r/s, burst 60) và `/videolab/tus`; đo lại với một buổi học thật ở staging rồi chỉnh để player không bị 429
- [ ] Host video: mọi đường dẫn khác (kể cả `/`, `/index.php`, `/videolab/tusXYZ`) trả 404 ngay tại Nginx; chỉ `/videolab/tus` (+ `/videolab/tus/{guid}`) và `/videolab/cdn/*` mở ra Internet
- [ ] CORS VideoLab: origin = `ADMIN_URL` (upload), `FRONTEND_URL` + `ADMIN_URL` (phát), không credentials

## 4. Redis

- [ ] KHUYẾN NGHỊ (R2): Redis RIÊNG (instance nhỏ) cho queue `video`; app và worker đặt `REDIS_VIDEO_HOST/PORT/USERNAME/PASSWORD` (connection `video`, mẫu ACL `redis/users.video-instance.acl`), worker đặt `CACHE_STORE=array` và không có thông tin Redis chính. Lý do: user worker có `+eval` nên worker bị chiếm có thể chạy script vòng lặp vô hạn làm Redis ngừng phục vụ; nếu dùng chung Redis thì rủi ro còn lại là mất sẵn sàng toàn site (không phải rò dữ liệu): giám sát độ trễ Redis (`redis-cli --latency`) và chấp nhận có ghi nhận. Không đặt `REDIS_VIDEO_*` thì queue `video` dùng Redis chính (local)
- [ ] Redis >= 7.0 dùng ACL file (`aclfile`), mẫu `infra/production/redis/users.acl` (C4-M1; file ACL KHÔNG được có comment `#`, giải thích ở `redis/README.md`): user `default` cho app, user `vv_worker_video` riêng cho worker-video (hash SHA-256, không ghi mật khẩu rõ; không dùng cùng `requirepass`). Thay `<PREFIX>`, `<CACHE_PREFIX>` (tên key thật = `<REDIS_PREFIX><CACHE_PREFIX>illuminate:...`, không có `:` ở giữa). Nạp lại: `ACL LOAD`
- [ ] Chạy `infra/production/redis/check-acl.sh <host> <port> <PREFIX> <CACHE_PREFIX> vv_worker_video "$MAT_KHAU_WORKER"` (`SKIP_SIGNALS=1` với instance riêng): phải in `ACL đạt.`. Nội dung kiểm: user worker bị `NOPERM`: `GET`/`SCAN` ở DB 1 (phiên), `LPUSH`/`RPUSH <PREFIX>queues:default x`, `EVAL "return redis.call('rpush','<PREFIX>queues:default','x')" 0`, `SET` hay `DEL` bất kỳ. Và chạy được: `queue:work redis_video` (pop, release, delete, đọc 3 key tín hiệu restart/pause). Sau mỗi lần nâng cấp Laravel (đổi Lua queue hoặc khoá tín hiệu) chạy lại kiểm này
- [ ] Redis có `requirepass` (mật khẩu mạnh) hoặc ACL ở trên, chỉ nghe mạng nội bộ (`bind` IP nội bộ, firewall chặn 6379 từ ngoài), `protected-mode yes`
- [ ] DB tách: session=1, cache=2, queue=3, limiter=4 (`REDIS_DB_SESSION`, `REDIS_CACHE_DB`, `REDIS_QUEUE_DB`, `REDIS_LIMITER_DB`); `cache:clear` không làm mất phiên (T05-2)
- [ ] (GL-A2/V2-5) `CACHE_LIMITER=redis-limiter` (guard ép driver redis): bộ đếm đăng nhập sai dùng script Lua nhiều khoá (`AtomicCounter::hitAll`) chỉ nguyên tử trên Redis. Hiện Redis 1 node. NẾU chuyển Redis Cluster: các khoá của 1 lần gọi (IP + tài khoản) khác slot sẽ báo `CROSSSLOT` (đăng nhập lỗi 500) -> phải đặt tên khoá có hash tag chung hoặc tách script trước khi chuyển
- [ ] Staging và production KHÔNG chung một instance Redis; nếu bắt buộc chung thì `REDIS_PREFIX` và số DB khác nhau
- [ ] `REDIS_PREFIX` của app và worker-video giống nhau trong cùng môi trường
- [ ] Persistence phù hợp: phiên học sinh nằm ở Redis; chấp nhận mất phiên khi Redis restart hoặc bật AOF (`appendonly yes`) nếu PO không muốn học sinh bị đăng xuất

## 5. MySQL

Mẫu: `infra/production/mysql/grants-users.sql` (user, một lần) và `grants-worker.sql` (quyền bảng worker, `deploy.sh` chạy sau mỗi migrate).

- [ ] MySQL 8.4; chỉ nghe mạng nội bộ; không dùng `root` cho app
- [ ] User `vv_app` (php-fpm, queue, scheduler): SELECT/INSERT/UPDATE/DELETE, không DDL
- [ ] User `vv_app` có DELETE trên `audit_logs` (cho `audit:purge`, T30); kiểm `php artisan audit:purge --dry-run` rồi chạy thật một lần ở staging
- [ ] User `vv_migrate` riêng cho `php artisan migrate --force` lúc deploy (DDL); không để app chạy bằng user này
- [ ] User `vv_worker_video` RIÊNG, chỉ quyền trên `vl_videos` (SELECT/INSERT/UPDATE) và INSERT `failed_jobs`; kiểm: đăng nhập bằng user này, `SELECT * FROM users` bị từ chối (T12-6, T12-10)
- [ ] Mật khẩu 3 user khác nhau; không dùng lại ở staging
- [ ] Charset/collation `utf8mb4`; `sql_mode` mặc định strict; múi giờ máy chủ UTC hoặc đúng `APP_TIMEZONE`
- [ ] Đo p95 `GET /me/courses` (log kênh `learning`, `slow`) trên staging với dữ liệu thật, mốc 300 ms (T23-3, DBA #10)

## 6. Queue, scheduler (Docker Compose)

Chạy bằng service compose (ADR-008): `queue` (2 bản, `deploy.replicas`), `scheduler`, `worker-video`; Supervisor (`infra/production/supervisor/vitaminvui.conf`) chỉ còn là tham chiếu. Ánh xạ: `numprocs` -> `deploy.replicas`, `stopwaitsecs` -> `stop_grace_period`, `autorestart` -> `restart: unless-stopped`.

- [ ] Service `queue` chạy 2 bản (`docker compose -p vvstack ps queue`), `restart: unless-stopped`, user 10001 (không root)
- [ ] Worker `worker-video` (`queue:work redis_video --queue=video --timeout=3600`) chạy tách máy/container với image build sẵn (mục 7); `retry_after` (3900) > `--timeout` (3600)
- [ ] Scheduler: một tiến trình `schedule:work` (hoặc cron `* * * * * php artisan schedule:run`) mỗi máy; các lệnh đã `onOneServer`, cần cache Redis dùng chung
- [ ] Deploy xong KHÔNG cần `queue:restart`: `deploy.sh` tạo lại container `queue`/`scheduler` với image mới (worker cũ nhận SIGTERM, xử lý xong job hiện tại, `stop_grace_period` 70 s)
- [ ] `php artisan schedule:list` có đủ: `counters:recount`, `videos:check-stuck`, `videos:prune-orphans`, `videolab:notify` (mỗi phút, C4-M1), `images:prune-orphans` (04:10, US-020), `videolab:cleanup`, `quizzes:auto-submit-expired`, `orders:expire-manual` (mỗi 15 phút, US-022), `otp:prune`, `audit:purge`, `orders:purge-customer-notes` (03:45), `orders:purge-staff-notes` (03:46), `users:purge-unverified`, `queue:prune-failed`, `queue:monitor`, `ops:health` (đối chiếu `OperationsServiceProvider`)
- [ ] `php artisan ops:health` báo worker và scheduler còn sống; cảnh báo của kênh log lỗi được nối vào kênh thông báo của hạ tầng (email/chat)
- [ ] Probe sống/sẵn sàng của LB và giám sát dùng `ops:health`, KHÔNG dùng `/api/v1/health` (luôn trả `ok` kể cả khi DB sập)
- [ ] NTP/chrony chạy trên mọi máy (ân hạn nộp bài quiz so giờ giữa các app server)
- [ ] Cảnh báo khi log `Gửi OTP thất bại.` vượt N lần/giờ (SMTP hỏng hoặc bị dò mã) và khi số log `parent_notice.sent` vượt ngưỡng/giờ (thư gửi cho bên thứ ba)
- [ ] `failed_jobs` trống hoặc dưới ngưỡng `OPS_FAILED_JOBS_MAX`; có người xem hằng ngày

## 7. worker-video (CHỈ khi bật VideoLab: `VV_VIDEOLAB=1`, profile compose `videolab`; V1/Bunny bỏ qua mục này)

Ghi chú ADR-008: image `vitaminvui-worker-video:<sha>` build từ `infra/worker-video/Dockerfile` với `BASE_IMAGE` = image backend cùng SHA (bake). Service đã đặt sẵn `read_only`, `cap_drop: ALL`, `no-new-privileges`, `cpus: 2`, `mem_limit: 2g`, `pids_limit: 256`, mạng `video` (`internal`), chỉ mount `videolab`, Redis riêng `redis-video`; `smoke.sh` kiểm các điều này. Chỉ cần xác nhận lại trên server thật.

- [ ] Image build sẵn từ `infra/worker-video/Dockerfile` (hoặc tương đương), mã nguồn COPY vào image, KHÔNG mount thư mục mã nguồn như local
- [ ] Chạy user không phải root; rootfs chỉ đọc; `cap_drop: ALL`; `no-new-privileges`; `cpus: 2`, `mem_limit: 2g`, `pids_limit: 256`
- [ ] Không có `.env` của app trong image/container; biến môi trường nạp từ file riêng (`infra/production/.env.worker-video.example`)
- [ ] `APP_ENV`, `APP_KEY`, `REDIS_PREFIX` đặt riêng cho worker (prefix giống app, `APP_KEY` khác)
- [ ] Worker đặt `VIDEOLAB_ENABLED=false` nên KHÔNG có khoá `VIDEOLAB_*` của app; ProductionConfigGuard chạy cả trong `queue:work` nên env worker vẫn phải có `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` hợp lệ (đã có trong mẫu). Test `tests/Feature/T31/WorkerVideoEnvTest.php` bị Skipped trong container `php` (không mount `infra/`); trước khi lên staging kiểm tay: nạp env worker rồi chạy `php artisan about` trong container worker, không được ném lỗi `ProductionConfigGuard`
- [ ] DB user `vv_worker_video` (mục 5) và Redis user `vv_worker_video` (mục 4, `REDIS_USERNAME`/`REDIS_PASSWORD` trong env worker); mạng chỉ tới Redis/MySQL nội bộ, không ra Internet. Worker KHÔNG dispatch/gọi webhook: video xong/lỗi thì `videolab:notify` (scheduler của app, mỗi phút) dispatch `SendVideoLabWebhookJob` vào queue `default` và worker `queue` của app gửi (C4-M1). Env worker không có `REDIS_DB_SESSION`/`REDIS_LIMITER_DB`. Worker không ghi nhịp `ops:health` (listener `Looping` bỏ qua connection `redis_video`)
- [ ] Chỉ mount volume `videolab` (đọc/ghi)
- [ ] Rebuild image worker-video mỗi tháng và khi có CVE ffmpeg (demuxer); ghi ngày build gần nhất
- [ ] Giám sát `vl_videos` status 1–3 kẹt quá 2 giờ (job mất khi Redis bị flush); xử lý tay, V2 sẽ sửa bằng code
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
| `failed_jobs` | **168 giờ** (mặc định code 720; đặt `OPS_FAILED_JOBS_RETENTION_HOURS=168` vì `exception` có thể chứa email người nhận khi SMTP lỗi, T29-S4) | `OPS_FAILED_JOBS_RETENTION_HOURS` | `queue:prune-failed` |
| `orders.customer_note` (lời nhắn học sinh) | 90 ngày kể từ khi đơn kết thúc | `ORDERS_CUSTOMER_NOTE_RETENTION_DAYS` (30..3650) | `orders:purge-customer-notes` (có `--dry-run`); đơn pending không bị đụng. **Chờ PO chốt thời hạn** |
| Nội dung nhân viên nhập: `orders.refund_note`, `payment_reference`, `cancel_reason_public`, `order_notes.body` | 7 ngày kể từ khi đơn kết thúc | `ORDERS_STAFF_TEXT_RETENTION_DAYS` (1..3650) | `orders:purge-staff-notes` (có `--dry-run`); `order_notes.body` thay bằng chuỗi cố định, đơn/tiền/trạng thái/audit giữ nguyên. **Chờ PO chốt thời hạn** |
| Tài khoản chưa xác thực | 7 ngày | `OPS_UNVERIFIED_ACCOUNT_DAYS` | `users:purge-unverified`; pháp chế xác nhận xoá cả `consents` |

- [ ] `LOG_STACK=daily` (không `single`), `LOG_LEVEL=warning` hoặc cao hơn ở production. Kênh `payments`, `playback`, `learning` cố định mức `info` (không bị `LOG_LEVEL` làm mất, C4-L1); kiểm: phát một bài rồi `storage/logs/playback-*.log` có dòng mới
- [ ] File log tạo quyền 0640 (đặt `permission => 0640` trong `config/logging.php` nếu cần; mặc định 0644) và thư mục 0750
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

- [ ] **Backup = snapshot ổ đĩa tự động hằng ngày của nhà cung cấp (PO 2026-10-10): KHUYẾN NGHỊ, không còn là điều kiện go-live.** Nên bật cho mọi ổ chứa dữ liệu, giữ >= 7 ngày; rủi ro khi dùng/không có: khôi phục cả máy, mất tối đa ~1 ngày, không theo thời điểm, snapshot cùng nhà cung cấp. (V3-5) MFA cho mọi tài khoản nhà cung cấp, user/API key quyền tối thiểu, hạn chế quyền xoá snapshot và tạo server từ snapshot (snapshot chứa `env/*.env`), khoá chống xoá nếu có, xác nhận mã hoá lúc lưu và vị trí Việt Nam. Binlog MySQL giữ 7 ngày trên đĩa, không sao lưu ngoài.
- [ ] Khuyến nghị diễn tập khôi phục snapshot MỘT lần trên staging (ghi ngày, người làm, thời gian khôi phục): sau khi khôi phục `deploy.sh status` thoát 0, `SHOW TRIGGERS LIKE 'audit_logs'` đủ 2, đăng nhập thử; chạy lại các lệnh purge (dữ liệu đã xoá có thể sống lại)

- [ ] Thư mục `uploads` (ảnh khoá học) nằm trong snapshot ổ đĩa (cùng ổ `/var/www/uploads`); (chỉ khi bật VideoLab) `videolab` (hls, source) cùng ổ hoặc có phương án transcode lại (`VIDEOLAB_KEEP_SOURCE`)
- [ ] Sao lưu `.env`/secret ở nơi an toàn riêng (không chung kho mã), có quy trình khôi phục
- [ ] Triển khai bằng image theo SHA (`deploy.sh deploy <sha>`): giữ >= 3 tag image gần nhất trên server (`deploy.sh` tự dọn phần còn lại); `releases.log` ghi mỗi lần deploy/rollback
- [ ] Trước deploy có migration: KHUYẾN NGHỊ chụp snapshot nhà cung cấp (deploy.sh chỉ in nhắc, không đòi cờ; migration `VV-IRREVERSIBLE` cần `--ack-irreversible`); migration phá cấu trúc/khoá bảng thì thêm `--maintenance` (`php artisan down` dùng chung qua driver cache) và chạy giờ thấp điểm. (R8) `vvdeploy` chạy được `deploy.sh status`/deploy với quyền thư mục theo README (`/opt/vitaminvui` thuộc `vvdeploy`)
- [ ] (K7, T29) Chỉ khi bảng `users` đã có dữ liệu: TRƯỚC migration backfill T29 tạo bảng sao `users_parent_consent_bak_t29` (id, parent_consent_status) theo script ở `docs/review/T29.md` mục DBA; giữ khoảng 30 ngày. SAU deploy: `SELECT COUNT(*) FROM users WHERE parent_consent_status <> 'not_required';` phải bằng 0 (còn thì chạy lại UPDATE theo lô). Lần go-live đầu (DB trống) bỏ qua bước sao
- [ ] (K7, T36) Trước deploy: `SELECT COUNT(*) FROM users WHERE role='giao_vien' AND (bio IS NOT NULL OR avatar_path IS NOT NULL);` (xem mục 3)
- [ ] Bật `SESSION_ENCRYPT` và đổi tên cookie `__Host-` (mục 1.1) trong CÙNG một lần deploy để học sinh/staff chỉ bị đăng xuất một lần; thông báo bảo trì trước
- [ ] Migration luôn có `down()`; migration phá dữ liệu (xoá cột/bảng) phải tách thành hai bước (deploy mã trước, xoá cột sau)
- [ ] Quy trình rollback đã diễn tập ở staging: `deploy.sh rollback` (về `PREVIOUS_TAG`, KHÔNG migrate; dừng nếu DB có migration image cũ không biết, chỉ `--force-schema-ahead` khi chắc tương thích ngược) -> xem `failed_jobs` -> kiểm `ops:health`. Migration có dòng `// VV-IRREVERSIBLE` (backfill T29): rollback = khôi phục snapshot; `deploy.sh` đòi `--ack-irreversible` và `--ack-snapshot` trước khi chạy nó. Không `migrate:rollback` tự động trên server
- [ ] Rollback KHÔNG chạy `migrate:fresh`, `migrate:reset`, `db:wipe` trên môi trường thật
- [ ] Kiểm sau deploy (smoke test): đăng ký/đăng nhập, danh mục khóa học, vào học một bài, upload video nhỏ (admin), `ops:health` xanh

## 12. Kiểm tra cuối trước go-live

- [ ] `composer install --no-dev --optimize-autoloader`; `php artisan config:cache route:cache event:cache view:cache`
- [ ] `php artisan migrate --status` không còn migration chưa chạy
- [ ] Không có Telescope/Debugbar/Pulse bị lộ; nếu có Horizon thì gate chỉ cho admin
- [ ] Tài khoản admin đầu tiên tạo bằng `php artisan staff:create`, đổi mật khẩu lần đầu, bật MFA; không còn tài khoản mẫu/seed. KHÔNG chạy `db:seed`, seed demo hay dữ liệu e2e ở production/staging (tài khoản demo có mật khẩu công khai trong board)
- [ ] `security review` T31 (laravel-security) đã chạy trên staging
- [ ] Thử xoá cờ: tắt `FEATURE_PAID_CHECKOUT` và chắc chắn `/checkout` đơn có tiền trả 503, đơn 0đ vẫn hoạt động

## 13. Điểm chờ pháp chế (V2)

Không chặn go-live MVP nếu PO chấp nhận rủi ro; ghi lại ở đây để không bị bỏ sót.

- [ ] Thời hạn giữ `audit_logs` 24 tháng và log `playback` 90 ngày (có IP + UA): pháp chế xác nhận
- [ ] Xoá `consents` khi `users:purge-unverified` xoá tài khoản chưa xác thực: pháp chế xác nhận
- [ ] T29 (ADR-006): không còn luồng phụ huynh đồng ý; chỉ gửi thư thông báo (`FEATURE_PARENT_NOTICES`). Pháp chế xác nhận lại việc không cần phụ huynh đồng ý và nội dung thư. **Chỉ bật `FEATURE_PARENT_NOTICES=true` khi trang FW7 `/phu-huynh/huy-nhan-thong-bao` đã lên** (link huỷ nhận trong thư trỏ tới trang này; thiếu trang → 404, vi phạm one-click unsubscribe). T34 (quyền dữ liệu cá nhân) và FW7 đã commit; `PRIVACY_POLICY_VERSION` mẫu (`2026-10-tam`) và chính sách công khai hiện là BẢN TẠM: pháp chế phải thay bằng chính sách thật (và đổi `PRIVACY_POLICY_VERSION`) trước khi mở đăng ký rộng
- [ ] `PRIVACY_NOTICE_TOKEN_KEY` đặt riêng >= 32 byte (guard chặn khởi động nếu thiếu), lưu cùng secrets, **không bao giờ xoay** (xoay = danh sách huỷ nhận `parent_notice_suppressions` và token đã gửi mất hiệu lực); `PRIVACY_PARENT_NOTICE_DAILY_CAP` trong 1..20
- [ ] Chính sách thật thay bản tạm trước go-live (xem dòng T29 ở trên)
- [ ] Mẫu `.env` production không còn `FEATURE_PARENT_CONSENT_ENFORCED`/`PRIVACY_PARENT_CONSENT_AGE` (đã đổi thành `PRIVACY_PARENT_CONTACT_SUGGEST_AGE`)
- [ ] `PRIVACY_POLICY_VERSION` khớp văn bản chính sách thực tế khi công bố
- [ ] Điều khoản thanh toán, hoàn tiền và nội dung pháp lý MoMo (V2): cần trước khi bật `FEATURE_PAID_CHECKOUT`
- [ ] Đăng ký thông báo xử lý dữ liệu cá nhân (nếu luật yêu cầu) và lưu trữ dữ liệu trong nước: pháp chế xác nhận

## 13b. Frontend production (K9)

Đóng gói theo ADR-008 §8.9 (T35-2): image `vitaminvui-web|admin:<env>-<sha>` build từ `frontend/Dockerfile` bằng `frontend/docker-bake.hcl`, chạy `output: "standalone"` bằng user `node`, một image cho mỗi môi trường. Mẫu env: `frontend/apps/web/.env.production.example` và `frontend/apps/admin/.env.production.example` (phần BUILD = `vars` của GitHub Environment, phần RUNTIME = `/opt/vitaminvui/env/{web,admin}.env`).

- [ ] `apps/web` và `apps/admin` build với `NODE_ENV=production`; `NEXT_PUBLIC_*` được nhúng LÚC BUILD (đổi giá trị = build lại). `apps/web`: `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_STATIC_URL` (bắt buộc, URL hợp lệ, https), `NEXT_PUBLIC_VIDEO_HOSTS`, `NEXT_PUBLIC_TURNSTILE_SITE_KEY` (key thật), `NEXT_PUBLIC_MOMO_HOSTS` (mặc định trong code đã là RỖNG, D5; V1 để trống, KHÔNG đặt `test-payment.momo.vn` ở staging/production). `apps/admin`: `NEXT_PUBLIC_ADMIN_API_URL`, `NEXT_PUBLIC_ADMIN_URL`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_UPLOAD_URL`
- [ ] GL-A2: `apps/admin` build với `NEXT_PUBLIC_TURNSTILE_SITE_KEY` = site key Turnstile THẬT, trùng `TURNSTILE_SITE_KEY` của backend (nhúng lúc build; thiếu thì người bị đòi captcha sau khi sai mật khẩu nhiều lần không đăng nhập được từ giao diện). Release CÙNG backend GL-A2 (web đã lấy khoá từ `/config/public`)
- [ ] `V2_PREVIEW` để TRỐNG ở production (bản xem trước design v2 `/v2/...` chỉ mở khi `V2_PREVIEW=1`, dành cho staging); biến chỉ ở server, không dùng tiền tố `NEXT_PUBLIC_`
- [ ] Biến chỉ-server của web: `INTERNAL_API_TOKEN` (giống backend) và `API_INTERNAL_URL` (mục 1.1); không biến bí mật nào mang tiền tố `NEXT_PUBLIC_`
- [ ] Container web/admin chạy `node apps/<app>/server.js` bằng user `node` (không root), `restart: unless-stopped`, publish chỉ `127.0.0.1:3000`/`127.0.0.1:3001` (mục 1.1); healthcheck web `/robots.txt`, admin `/dang-nhap` (admin không có `/robots.txt`, trả 404)
- [ ] Kiểm image trước khi dùng: `docker run --rm --entrypoint sh <image> -c 'find /app -name ".env*"'` rỗng; `grep -rE "test-payment\.momo\.vn|api\.localhost|localhost:8000|video\.localhost|1x00000000000000000000AA" /app` rỗng; `docker history --no-trunc <image>` không có `INTERNAL_API_TOKEN`; `curl -sI` trang chủ có CSP với `nonce-`, không có `momo`/`localhost`; label `vv.env` đúng môi trường
- [ ] Sau khi khởi động web, gọi thử `/khoa-hoc` (SSR tới `API_INTERNAL_URL`): 200, nếu API chưa sẵn sàng sẽ là trang "Hệ thống đang bận" có `noindex`, không được 500
- [ ] Log của Next.js không ghi URL đầy đủ của `/phu-huynh/huy-nhan-thong-bao` (mục 3)

## 14. Cần PO hoặc hạ tầng cung cấp trước khi làm các mục trên

- Tên miền thật: `vitaminvui.vn` (và staging `vitaminvui-staging.vn`), tên miền tĩnh `vitaminvui-media.net` (và `vitaminvui-staging-media.net`), tài khoản DNS
- Cloudflare Turnstile: site key + secret cho staging và production (hai bộ riêng), hostname cho phép
- SMTP (host, port, user, mật khẩu, địa chỉ gửi; SPF/DKIM/DMARC)
- IP: load balancer, app server, Next.js server, IP văn phòng (nếu giới hạn admin)
- Chứng chỉ TLS (hoặc dùng ACME), nơi lưu secret (secret manager)
- Server MySQL, Redis (mật khẩu, mạng nội bộ), nơi lưu backup
- Kênh nhận cảnh báo (`ops:health`, `queue:monitor`)
- Quyết định PO: thời hạn log `learning`; giới hạn truy cập admin theo IP; `FEATURE_ENROLLMENT_DECISION_MAIL`
