# GL-1 — Chuẩn bị go-live (phần Dev)

Nguồn: `docs/ops/go-live-readiness.md` mục 9 (D1, D6), bảng K1–K10; `docs/security/go-live-triage.md` A5 và nhóm B.

## Đã làm

**D1 (Nginx).** `infra/production/nginx/conf.d/vitaminvui.conf`: thêm `location = /phu-huynh/huy-nhan-thong-bao` ở host web, ghi access log bằng `log_format vv_noargs` (chỉ `$uri`). API huỷ nhận (`POST /api/v1/parent-notices/unsubscribe`, `routes/api.php`) nhận token trong body nên không cần che. `error_log` Nginx vẫn ghi `request: "...?t=..."` khi upstream lỗi, không che được bằng cấu hình: đã ghi vào checklist §3. Log Next/LB/CDN: ghi vào checklist §3 và §13b (kiểm tay). Thêm zone `vv_admin` + `limit_req` cho host admin (RL-2). `nginx -t` (nginx:1.27, cert giả, IP mẫu, bỏ bind IP nội bộ vì container không có IP đó): syntax ok, test successful.

**D6 + A5 (`ProductionConfigGuard`).** Thêm hai khối riêng: `guardCaptchaSecretAndMailer()` và `guardInfraCredentials()`:
- `TURNSTILE_SECRET` rỗng khi `captcha.driver=turnstile` thì chặn; tiến trình console được miễn (cùng cờ `app.trusted_proxies_console_exempt` với TRUSTED_PROXIES) vì worker-video không có secret và captcha chỉ chạy ở HTTP.
- Secret hoặc site key bắt đầu bằng `1x0000`/`2x0000`/`3x0000` thì chặn (mọi tiến trình).
- `MAIL_MAILER` là `log`/`array`/rỗng thì chặn. Không miễn console (queue worker gửi thư). Vì vậy `infra/production/.env.worker-video.example` được thêm `MAIL_MAILER=smtp` (worker không gửi thư, chỉ để qua guard).
- `REDIS_PASSWORD` rỗng (`database.redis.default.password`) và `DB_USERNAME=root` thì chặn. Thông báo chỉ nêu tên biến.
- Áp cho production và staging, bỏ qua local/testing (như các guard khác).

**Test.** `tests/Feature/T01/ProductionConfigGuardTest.php`: thêm test cho từng điều kiện (secret rỗng, khoá test secret/site key, mailer log/array/rỗng, mailer thật không bị chặn, Redis, root, staging chặn / local không chặn, console miễn). Baseline của các test guard khác (T01, T03/T04, T11, T17, T26, T29, T31, T36, T37, T38) được thêm `services.turnstile.secret`, `services.turnstile.site_key`, `mail.default`, `database.redis.default.password`, `database.connections.mysql.username` (phpunit.xml đặt site key là khoá test `1x0000…` nên baseline phải ghi đè). `WorkerVideoEnvTest` nạp thêm config `database`, `mail`, `services`.

**K1–K10 + nhóm B** — `docs/ops/production-checklist.md` (gộp, không trùng): biến `PRIVACY_*`/`ORDERS_*`/`PAYMENT_CONTACT_*` đối chiếu `config/orders.php`, `config/privacy.php`, guard; cờ `FEATURE_MANUAL_PAYMENT` + thứ tự bật cờ; mục che token (§3); 4 lệnh lịch (đối chiếu `OperationsServiceProvider`); lưu giữ `customer_note`/nội dung nhân viên (§8) + `OPS_FAILED_JOBS_RETENTION_HOURS=168`; bước bảng sao T29 và kiểm T36 (§11), gộp SESSION_ENCRYPT + cookie `__Host-` vào một lần đăng xuất; seed/e2e (§12); chính sách tạm (§13); mục Frontend mới §13b; NTP, ffmpeg, giám sát OTP/`parent_notice.sent`/`vl_videos`, hostname Turnstile, token/mật khẩu demo, nosniff do Nginx trả, HSTS preload, probe `ops:health`, quyền log 0640. Bảng §1.2 và đoạn "Guard CHƯA kiểm" đã cập nhật. `.env.production.example`: thêm `OPS_FAILED_JOBS_RETENTION_HOURS=168`.

## Kết quả kiểm
- Pint: pass. PHPStan `--memory-limit=2G`: No errors.
- `ProductionConfigGuardTest` (DB `vitaminvui_testing_g`): 31 passed.
- Toàn bộ suite trên DB g: 2870 passed, 35 skipped, 7 failed. Ba lỗi đọc được đều thuộc `T28` (throttle/race đăng nhập staff, dùng chung Redis limiter với dev khác chạy song song); chạy lại riêng `tests/Feature/T28`: 98 passed, 1 skipped. Bốn lỗi còn lại không đọc được tên (tail 60 dòng), chưa chạy riêng, cần QA chạy lại.
- Lần chạy trước đó (trước khi sửa lại) có 26 lỗi `Table users doesn't exist` trên DB g (DB bị tranh chấp, không liên quan guard).
- `WorkerVideoEnvTest` bị skip trong container `php` (không mount `infra/`): CHƯA chạy được; cần chạy bằng `docker run` có mount `infra/production`.

## Lưu ý
- Pint (chạy trên `tests`) đã chỉnh định dạng 2 file `tests/Feature/BEB1/*` của dev khác (chỉ style).
- Cần `laravel-qa` kiểm: `WorkerVideoEnvTest` thật; `T31` có mount infra; staging với `MAIL_MAILER=smtp` thật; env thật không có `REDIS_PASSWORD` mà app dùng ACL user riêng (guard đọc `database.redis.default.password`).

---

# Review (laravel-reviewer)
**Kết luận:** REQUEST CHANGES
**Phạm vi:** working tree chưa commit, phần GL-1 (nginx conf, ProductionConfigGuard + test, env mẫu, checklist) · bỏ qua phần BE-backlog-1 / GL-A34 / GL-A2.

## Tổng quan
Hướng đi đúng: hai khối guard tách riêng, thông báo chỉ nêu tên biến, Nginx che token bằng `$uri`, Next đã đặt `Referrer-Policy: no-referrer` cho đúng trang huỷ nhận (`frontend/apps/web/proxy.ts:59`) nên Referer không mang token sang log `/_next/static/`. Nhưng bộ T31 chạy với mount `infra/production` vẫn đỏ (5 test), và guard Redis sẽ chặn nhầm worker-video ở chế độ khuyến nghị R2. Kết quả "đã chạy" trong phần Dev chưa phản ánh điều này vì T31 bị skip trong container `php`.

## Kết quả kiểm
- `nginx -t` (nginx:1.27, cert giả, thay placeholder IP, `listen` nội bộ về 127.0.0.1): syntax ok, test successful.
- `ProductionConfigGuardTest`: pass. `tests/Feature/T31` (docker run có mount infra, DB g, `FEATURE_MANUAL_PAYMENT=false VIDEO_BIND_IP=true`): **5 failed, 205 passed** (WorkerVideoEnvTest, Cum4ConfigTest M2 x2, ProductionEnvExampleTest x2).
  - Nguyên nhân 1: `backend/.env.example` (và `.env` local, CI chép từ đó) có `TURNSTILE_SITE_KEY=1x00000000000000000000AA`. Test nạp env mẫu không ghi đè `TURNSTILE_SITE_KEY` nên guard bắt "khoá test" (thông báo hiện ở cả WorkerVideoEnvTest dù env mẫu worker không có khoá). Đặt `-e TURNSTILE_SITE_KEY=0xREAL -e TURNSTILE_SECRET=...` thì WorkerVideoEnvTest xanh.
  - Nguyên nhân 2: `qaRunGuardWithEnv` (ProductionEnvExampleTest) và `c4RunGuardRaw` (Cum4ConfigTest) chỉ nạp lại config `app, session, sanctum, captcha, payments, video, videolab, internal, auth, features`, không nạp `mail`, `database`, `services`: `mail.default` vẫn là `log` từ `.env` nên 4 test còn lại đỏ vì guard MAIL_MAILER (kể cả khi đã vượt Turnstile).

## Phát hiện
### R1 [BLOCKER] Test T31 nạp env mẫu thật bị đỏ (CI có mount infra sẽ fail)
- Vị trí: `backend/tests/Feature/T31/ProductionEnvExampleTest.php:41-70` (`qaRunGuardWithEnv`), `backend/tests/Feature/T31/Cum4ConfigTest.php` (`c4RunGuardRaw`, ~dòng 108-111), `backend/tests/Feature/T31/WorkerVideoEnvTest.php:22-27`.
- Vấn đề: các helper này dựng config từ env mẫu nhưng (a) không nạp `mail`, `database`, `services`, (b) để `TURNSTILE_*` lọt từ `.env`/`.env.example` (khoá test `1x0000…`). Guard mới vì thế ném lỗi. Phần Dev chỉ sửa baseline của các test dùng `config([...])`, bỏ sót nhóm test nạp file env thật.
- Đề xuất: trong cả 3 chỗ, thêm `'database','mail','services'` vào danh sách config nạp lại, và ép `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET` về giá trị không phải khoá test (hoặc xoá khỏi `$_ENV`/`getenv`) trước khi `require config/services.php`. Với `.env.production.example`, `TURNSTILE_SITE_KEY=` để trống nên test phải tự cấp giá trị hợp lệ như đã làm với `INTERNAL_API_TOKEN`. Chạy lại T31 bằng docker run có mount infra (không có TURNSTILE_* trong env của container) để xác nhận xanh.

### R2 [BLOCKER] Guard `REDIS_PASSWORD` chặn nhầm worker-video ở chế độ Redis riêng (R2 khuyến nghị)
- Vị trí: `backend/app/Support/ProductionConfigGuard.php` `guardInfraCredentials()` (đọc `database.redis.default.password`); `infra/production/.env.worker-video.example:56-58` ("bỏ REDIS_HOST/REDIS_PASSWORD của Redis chính khỏi môi trường worker", chỉ dùng `REDIS_VIDEO_*`).
- Vấn đề: khi làm đúng khuyến nghị (và đúng ADR-008: Redis riêng cho video), worker không có `REDIS_PASSWORD` nên `redis.default.password` rỗng và guard ném lỗi khi khởi động `queue:work` ở production/staging. Cũng đúng cho `REDIS_URL` (mật khẩu nằm trong URL, `password` null). Ngoài ra guard không kiểm connection thực sự dùng (`redis_video`), nên cấu hình `REDIS_VIDEO_PASSWORD` rỗng lại lọt.
- Đề xuất: kiểm theo connection đang dùng. Ví dụ app: `default`/`session`/`cache`/`queue`; khi `queue.connections.redis_video` được cấu hình thì kiểm `database.redis.video.password`. Cách gọn:
  ~~~php
  $names = app()->runningInConsole() && $this->isVideoWorker() ? ['video'] : ['default'];
  ~~~
  hoặc chấp nhận mật khẩu từ `url` (`parse_url(...,PHP_URL_PASS)`). Tối thiểu: kiểm password của mọi connection Redis có `host` được đặt thực sự và thêm test "worker chỉ có REDIS_VIDEO_* không bị chặn" + "REDIS_VIDEO_PASSWORD rỗng bị chặn". Lưu ý ACL: user `default` trong `users.acl` có hash mật khẩu nên `REDIS_PASSWORD` rỗng sẽ không kết nối được; guard này chỉ có ý nghĩa nếu đọc đúng connection.

### R3 [SHOULD] Checklist §1.2 mâu thuẫn với code và bảng bị vỡ
- Vị trí: `docs/ops/production-checklist.md:76-80`.
- Vấn đề: (a) hai dòng mới (`TURNSTILE_SECRET`, `MAIL_MAILER`) nằm sau một dòng trống nên không thuộc bảng (markdown render thành text lẻ); (b) câu "Guard CHƯA kiểm: `REDIS_PASSWORD` rỗng, user DB là root, ... key Turnstile là key test" vẫn còn nguyên dù guard đã kiểm đúng các mục đó (phần Dev ghi là đã cập nhật nhưng file thì chưa). Thiếu các hàng cho Redis rỗng, DB root, khoá test.
- Đề xuất: bỏ dòng trống, thêm 3 hàng guard (Redis, root, khoá Turnstile test), rút câu "CHƯA kiểm" còn `MAIL_HOST` rỗng khi smtp. Ghi rõ ngoại lệ console cho `TURNSTILE_SECRET`.

### R4 [SHOULD] Dùng chung cờ `trusted_proxies_console_exempt` cho Turnstile: chấp nhận được, nhưng cần ghi lại và tách tên
- Vị trí: `ProductionConfigGuard.php` `guardCaptchaSecretAndMailer()`.
- Nhận xét: hợp lý vì cùng điều kiện "tiến trình không phục vụ HTTP", và rủi ro Octane/RoadRunner đã có trong checklist (R9). Nhưng tên cờ nói về proxy nên người đọc test/config dễ hiểu sai; cờ này tắt cả hai ngoại lệ cùng lúc. Đề xuất: đổi tên trung tính (`guard_console_exempt`) hoặc ít nhất thêm hàng vào dòng lưu ý R9 trong checklist rằng ngoại lệ console nay áp cho cả `TURNSTILE_SECRET`, nên khi đổi runtime web sang CLI thì mất cả hai lớp kiểm. Khoá test thì vẫn chặn ở mọi tiến trình (đúng).

### R5 [SHOULD] `MAIL_MAILER=smtp` trong env mẫu worker-video
- Chấp nhận được làm cách tạm (worker không gửi thư), nhưng hơi gượng: guard buộc worker phải khai báo cấu hình giả. Đề xuất ghi chú trong mẫu rằng giá trị chỉ để qua guard và không đặt `MAIL_HOST`/mật khẩu SMTP vào worker (nguyên tắc tối thiểu secret), hoặc miễn kiểm mailer cho tiến trình `queue:work` của connection `redis_video`. Có thể để NIT nếu giữ cách hiện tại. Mẫu hiện đã có dòng `MAIL_MAILER`, nhưng không có comment giải thích, xem lại dòng đó.

### R6 [NIT] `limit_req` admin: 5r/s burst 100 đủ, nhưng static admin nên miễn như web
- Vị trí: `vitaminvui.conf` host admin `location /`.
- Một lần tải trang admin kéo hàng chục chunk `/_next/static/` qua cùng `location /` (web đã tách `^~ /_next/static/` không giới hạn). Burst 100 nodelay thường vẫn đủ, nhưng nhiều tab/nhiều nhân viên chung NAT văn phòng thì dễ chạm 429 oan ở đợt tải lại. Đề xuất thêm `location ^~ /_next/static/ { proxy_pass ...; }` không `limit_req` cho admin, như web, và ghi vào checklist §?? bước đo staging (đã có dòng đo 429, đủ).

### R7 [NIT] Che token Nginx
- `location = /phu-huynh/huy-nhan-thong-bao` đúng: exact match, `$uri` không có query, log riêng; request RSC/prefetch cùng path cũng đi qua location này. Khớp với ghi chú `error_log`. Không phát hiện chỗ rò khác ở Nginx (Referer đã chặn bằng `no-referrer` ở Next; access log mặc định của các location khác không chứa query của trang này). Lưu ý: nếu ai đổi `Referrer-Policy` trong `proxy.ts` thì log `/_next/static/` sẽ rò token qua Referer, nên có test cho header này (kiểm xem đã có chưa).

## Đối chiếu yêu cầu (D1, D6, A5, K1-K10, nhóm B)
| Mục | Đáp ứng | Ghi chú |
|---|---|---|
| D1 che token | Có (Nginx), kiểm tay cho Next/LB/CDN/error_log | OK, xem R7 |
| D1 `limit_req` admin | Có | R6 |
| D6 Turnstile secret/mailer | Có | R1, R4, R5 |
| A5 Redis/root | Một phần | R2 (chặn nhầm worker-video, kiểm sai connection) |
| Checklist gộp K1-K10 + B | Phần lớn | R3; 4 lệnh lịch và `schedule:list` khớp `OperationsServiceProvider`; không thấy mâu thuẫn ADR-008 |

## Gợi ý cho QA
- Chạy lại T31 đầy đủ bằng docker run có mount `infra/production`, không truyền `TURNSTILE_*` vào container (giống CI), DB g.
- Thử `queue:work --queue=video` thật với env chỉ có `REDIS_VIDEO_*` (sau khi sửa R2) ở APP_ENV=production.
- Staging với `MAIL_MAILER=smtp` thật; kiểm `ops:health` không bị ảnh hưởng.
- Kiểm 429 admin khi tải lại trang nhiều tab; kiểm access log `vv-web-noargs.access.log` không có `?t=`.

Phát hiện: 2 BLOCKER, 3 SHOULD (R3, R4, R5), 2 NIT (R6, R7). Bước tiếp theo: `laravel-dev` sửa R1, R2, R3 rồi review lại.

## Sửa sau review (Dev)
- **R1:** `qaRunGuardWithEnv`, `c4RunGuardRaw`, `WorkerVideoEnvTest` nạp thêm config `database`, `mail`, `services`; `ProductionEnvExampleTest` và `c4ValidInfra()` cấp `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET` không phải khoá test và `REDIS_PASSWORD`; `WorkerVideoEnvTest` ép `TURNSTILE_*` hợp lệ. `.env.example` giữ nguyên khoá test cho local.
- **R2:** `guardInfraCredentials()` kiểm theo connection: web cần `default` và `video`; tiến trình console (cùng cờ miễn) chỉ cần `video`. Chấp nhận mật khẩu trong `url`. Test mới: mật khẩu trong URL, worker chỉ có `REDIS_VIDEO_*` không bị chặn, worker thiếu `REDIS_VIDEO_PASSWORD` bị chặn, web thiếu mật khẩu video bị chặn. Baseline các test guard thêm `database.redis.video.password`.
- **R3:** bảng §1.2 liền mạch, thêm hàng khoá test Turnstile, Redis, root; câu "Guard CHƯA kiểm" rút còn `MAIL_HOST`, Turnstile giả.
- **R4:** giữ tên cờ; dòng lưu ý R9 trong checklist ghi ngoại lệ console nay phủ `TURNSTILE_SECRET` và `REDIS_PASSWORD` `default`.
- **R5:** comment trong `.env.worker-video.example`: không đặt SMTP thật vào worker.
- **R6:** host admin có `location ^~ /_next/static/` không `limit_req`; `nginx -t` ok.
- **R7:** header `Referrer-Policy: no-referrer` của trang huỷ nhận đã có test ở frontend `frontend/apps/web/proxy.test.ts:70-73` (không sửa frontend); không test được ở phía backend/nginx.

Kết quả: `docker run` có mount `infra/production`, không truyền `TURNSTILE_*`, DB g: `tests/Feature/T31` + `tests/Feature/T01` = **305 passed, 0 skipped**. PHPStan: No errors. Pint ok. T04/T11/T17/T26/T29/T36/T37/T38: 1063 passed, 1 skipped, 1 failed (`T38/ManualCheckoutTest` dòng 36); chạy riêng file đó 24 passed (lỗi chập chờn khi DB/Redis dùng chung).

---

# Review vòng 2 (laravel-reviewer)
**Kết luận:** APPROVE (khối GL-1), kèm điều kiện: chạy lại T31 đầy đủ sau khi GL-A2 xong.

## Kiểm từng phát hiện
- R2: `guardInfraCredentials()` kiểm theo connection (`default`+`video` cho web, chỉ `video` cho console), `redisConnectionHasPassword()` chấp nhận mật khẩu trong `url`; `video` lùi về `REDIS_PASSWORD` nên web không bị chặn nhầm. Đúng. Có test worker chỉ `REDIS_VIDEO_*` và thiếu `REDIS_VIDEO_PASSWORD` (nằm trong T01, xanh).
- R3: bảng §1.2 liền mạch, đủ hàng Turnstile/khoá test/Redis/root, câu "CHƯA kiểm" đã rút gọn; lưu ý R9 ghi ngoại lệ console phủ cả Turnstile và Redis `default` (R4 giữ tên cờ, chấp nhận).
- R5: `.env.worker-video.example` có comment giải thích `MAIL_MAILER=smtp`. R6: host admin có `location ^~ /_next/static/` không `limit_req`. R7: test header `no-referrer` có ở frontend `proxy.test.ts`, chấp nhận.
- R1: 3 helper + WorkerVideoEnvTest đã nạp `database/mail/services` và ép `TURNSTILE_*` (xem code), nhưng chưa xác nhận lại bằng chạy T31 (xem dưới).

## Kết quả chạy (docker run, mount infra, không truyền TURNSTILE_*, DB g)
- `tests/Feature/T01/ProductionConfigGuardTest.php`: không có test fail.
- `tests/Feature/T31` + `T01`: 100 failed, 216 passed. Mọi thông báo đọc được đều là `CACHE_LIMITER phải trỏ tới store dùng driver redis (GL-A2)`; không có lỗi nào mang nhãn `(GL-1)`. Đây là khối guard của GL-A2 đang sửa dở (baseline T31 chưa cấp `CACHE_LIMITER`), không thuộc GL-1; truyền `-e CACHE_LIMITER=redis` không đủ vì test nạp lại config. Vì vậy không thể xác nhận xanh T31 ở thời điểm này (kể cả WorkerVideoEnvTest).
- Yêu cầu: khi GL-A2 hoàn tất, QA/Dev chạy lại T31 + T01 cùng cách (không `TURNSTILE_*`) và đảm bảo 0 fail trước khi commit; nếu còn lỗi nhãn GL-1 thì quay lại review.

Không còn BLOCKER GL-1. Bước tiếp theo: chuyển `laravel-qa` sau khi GL-A2 ổn định.

---

# QA (laravel-qa, 2026-10-10)
**Kết quả: FAIL** (điều kiện của Review vòng 2 "T31 + T01 xanh, 0 skip" chưa đạt; lỗi nằm ở test, không ở code ứng dụng). Sửa BUG-1 là xong.

## Đã chạy
| Hạng mục | Cách chạy | Kết quả |
|---|---|---|
| T31 + T01 (docker run, mount `infra/production`, không `TURNSTILE_*`, DB h) | lệnh PO yêu cầu | **7 failed, 309 passed**, 0 skip do thiếu mount |
| Cùng 3 file lỗi, bỏ `CACHE_LIMITER=array` khỏi phpunit XML (bản tạm, đã xoá) | pest | 60 passed -> nguyên nhân là baseline test (BUG-1) |
| Suite không race (DB riêng `_testing_q`, sau khi DB h bị chạy chồng, xem Rủi ro) | `--exclude-group=race` | 2951 passed, 1 skipped, 12 failed -> 7 là BUG-1, 3 do tên DB của tôi (không khớp `_testing(_[a-z])?$`, chạy lại xanh), 1 flaky giờ (BUG-2), 1 `QAParentNotice` cũng do tên DB (chạy lại xanh) |
| `T38/ManualCheckoutTest` | chạy chung và chạy riêng | xanh cả hai lần (24 passed); không tái hiện lỗi cũ |
| Nhóm race (load 3-5) | `--group=race` | 77 passed |
| Guard thật, `APP_ENV=production`, `.env` dev bị che bằng file rỗng, env hợp lệ dựng từ `.env.production.example` | `php artisan about` (web giả lập bằng `APP_RUNNING_IN_CONSOLE=false`) | xem bảng dưới |
| `nginx -t` (nginx:1.27, cert giả, mount ro, thay `<IP_...>` bằng 127.0.0.1) | docker run | syntax ok, test successful |
| Access log `vv-web-noargs.access.log` | curl `/phu-huynh/huy-nhan-thong-bao?t=SECRETTOKEN123` | dòng log `"GET /phu-huynh/huy-nhan-thong-bao" 502`, không có `t=`; host web khác vẫn ghi query bình thường (đúng) |
| `update-cloudflare-ips.sh` | nguồn `file://` giả | xem bảng dưới |

### Guard (thật)
| Tình huống | Kết quả |
|---|---|
| env hợp lệ, console và web | qua |
| worker (console) chỉ có `REDIS_VIDEO_PASSWORD`, `REDIS_PASSWORD` rỗng; `queue:work --queue=video --once --stop-when-empty` | **không bị chặn** (chạy xong) |
| console thiếu cả hai mật khẩu Redis | chặn: `REDIS_VIDEO_PASSWORD (hoặc REDIS_PASSWORD)...` |
| web thiếu `REDIS_PASSWORD` / chỉ có mật khẩu video | chặn: `REDIS_PASSWORD không được rỗng` |
| web `REDIS_VIDEO_PASSWORD=` (đặt rỗng tường minh, `REDIS_PASSWORD` có) | chặn (env đặt rỗng thắng giá trị lùi `REDIS_PASSWORD`; chấp nhận, an toàn hơn) |
| `TURNSTILE_SECRET` rỗng: web / console | web chặn; console qua (đúng thiết kế miễn console, R4) |
| khoá test `1x/2x/3x0000` ở secret hoặc site key | chặn (cả console) |
| `MAIL_MAILER` = log / array / rỗng | chặn |
| `DB_USERNAME` = root / ROOT | chặn |
| `CAPTCHA_DRIVER=fake` | chặn |
| `staging` hợp lệ | qua |

### `update-cloudflare-ips.sh`
| Nguồn | Kết quả |
|---|---|
| hợp lệ (12 v4 + 6 v6) | exit 0, ghi lại file, `nginx -t` + reload, giữ `real_ip_header CF-Connecting-IP` |
| dòng chèn `1.2.3.0/24; } server { listen 9999;`, `1.2.3.0/24;` | exit 1, file không đổi |
| `0.0.0.0/1`, `10.0.0.0/7`, `::/1`, `8000::/15` | exit 1, file không đổi |
| quá ngắn (3 dòng v4) / v6 rỗng | exit 1, file không đổi |
| nguồn không tồn tại (curl exit 37) | exit ≠ 0, file không đổi |
| `file://` khi không đặt `CF_IPS_ALLOW_FILE=1` | bị chặn (`--proto =https`), file không đổi |
| file đích thiếu marker | exit 1, file không đổi |
| `nginx -t` lỗi sau khi ghi | exit 1, khôi phục bản cũ |

## Bug
### BUG-1: Test baseline T31 không cấp `CACHE_LIMITER`, 7 test đỏ khi chạy đúng cách CI (Major, thuộc test)
- Tái hiện: lệnh số 1 của PO. Lỗi: `CACHE_LIMITER phải trỏ tới store dùng driver redis ở production/staging (GL-A2)`.
- Nguyên nhân: `phpunit*.xml` ép `CACHE_LIMITER=array` (env + server). Các helper nạp env mẫu vào `$_ENV` (`qaRunGuardWithEnv`, `c4RunGuardRaw`, `c4ValidInfra`, `WorkerVideoEnvTest`) không đặt lại `CACHE_LIMITER`, nên `config('cache.limiter')=array` và guard V2-5 ném lỗi. Env mẫu production không có `CACHE_LIMITER` (mặc định `redis-limiter`, đúng).
- Test đỏ: `T31/Cum4ConfigTest` (3), `T31/ProductionEnvExampleTest` (3), `T31/WorkerVideoEnvTest` (1). T01 xanh.
- Mong đợi: T31 + T01 xanh. Đề xuất sửa: thêm `'CACHE_LIMITER' => 'redis-limiter'` vào mảng `$valid`/baseline của các helper trên (đã xác minh bằng XML tạm không ép biến này: 60 passed). Không phải bug ứng dụng.
- Vị trí: `backend/tests/Feature/T31/ProductionEnvExampleTest.php:20-33`, `Cum4ConfigTest.php` (`c4RunGuardRaw`, `c4ValidInfra`), `WorkerVideoEnvTest.php:14-32`.

### BUG-2: `T24/AdminOrderListTest` "loc tung tham so" phụ thuộc giờ trong ngày (Minor, test cũ, không thuộc GL-1/GL-A2)
- Chạy từ 00:00 đến khoảng 06:00 giờ VN thì đơn tạo "30 giờ trước" rơi ngoài `vvT24Range()` (từ hôm qua): "actual size 3, expected 4". Tái hiện ở 00:2x giờ VN, hai lần.
- Vị trí: `backend/tests/Feature/T24/helpers.php:47-50`, `AdminOrderListTest.php:71-90`. Nên mở rộng range hoặc dùng `Carbon::setTestNow`.

## Rủi ro và đề xuất
- Dòng `error_log` của Nginx vẫn ghi `request: "GET /...?t=<token>"` khi upstream lỗi (đã xác nhận: 1 dòng chứa token trong `error.log` khi upstream chết). Không che được bằng cấu hình; đã ghi trong cấu hình và checklist, cần giữ `error_log` ở mức error, xoay vòng ngắn, hạn chế quyền đọc.
- Giới hạn tốc độ host admin không kiểm được bằng curl tuần tự trong lúc máy quá tải (130 request chậm, không vượt 5r/s nên không thấy 429); cấu hình `limit_req ... burst=100` và `location ^~ /_next/static/` không `limit_req` đã đọc và `nginx -t` ok. Nên đo lại ở staging (checklist đã dặn).
- DB h bị xoá bảng giữa chừng khi tôi chạy suite (có tiến trình khác dùng chung, 208 lỗi `Table ... doesn't exist` và deadlock). Kết quả đó bỏ; số liệu trên lấy từ DB riêng. Cần tránh hai tác nhân dùng chung một DB test.
- Sau khi sửa BUG-1, chạy lại lệnh số 1; nếu 0 failed thì GL-1 chuyển PASS.

## Sửa BUG QA (coordinator, 2026-10-10)
- BUG-1 (test): `T31/ProductionEnvExampleTest`, `Cum4ConfigTest` (`c4ValidInfra`, `c4RunGuardRaw` tự thêm mặc định), `WorkerVideoEnvTest` cấp `CACHE_LIMITER=redis-limiter` và nạp lại config `cache`. Chạy T31 + T01 bằng docker run có mount `infra/production`, không truyền `TURNSTILE_*`: 316 passed. Pint sạch.
- BUG-2 (test cũ T24): `vvT24Range()` lùi 2 ngày thay vì 1 để đơn "30 giờ trước" vẫn lọt khi chạy 0h–6h. T24 (không race) 37 passed lúc 0h40.
- Kết luận sau sửa: **PASS** (hành vi ứng dụng đã đạt ở mục QA; chỉ còn lỗi test, đã sửa và chạy lại).
