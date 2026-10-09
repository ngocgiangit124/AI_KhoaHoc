# SECURITY: Phân loại nợ bảo mật trước go-live V1 | 2026-10-09

**Kết luận:** **FAIL ở thời điểm hiện tại. Thành PASS có điều kiện khi xong nhóm A (5 việc, khoảng 16–19 giờ) và tích xong các mục B trong `docs/ops/production-checklist.md`.**
Code backend không có Critical/High. Mục High duy nhất là phụ thuộc frontend: `next@16.3.6` có advisory High (SSRF ở Image Optimization) và advisory cache poisoning cho bản tự host.

Phạm vi V1: thanh toán thủ công US-022 (`FEATURE_MANUAL_PAYMENT=true`), `FEATURE_PAID_CHECKOUT=false` (MoMo tắt), `VIDEO_PROVIDER=internal` (VideoLab).
Cách kiểm: đọc code ở HEAD `b500ba6` cho từng mục còn mở. Lúc viết báo cáo, working tree có thay đổi CHƯA COMMIT của một dev khác (gói BE-backlog-1/GL-1) chạm vào A5, T29-S4, T29-S6: xem mục "Đang sửa trong working tree" ở cuối. Phân loại dưới đây vẫn tính theo HEAD. Mục nào ghi "theo backlog" là mục Low/Info đã đối chiếu mô tả, không thấy commit nào sửa, nhưng chưa đọc từng dòng code. Đã chạy `composer audit` và `pnpm audit --prod`. Không chạy test, không đụng DB.

Tổng số mục còn mở: **A 5 việc** (gộp 7 mã) · **B 30 mục** · **C 52 mục** · **D 17 mục** · **thấy đã sửa mà backlog chưa ghi: 12 mục**.

---

## A — Bắt buộc sửa code trước go-live

| # | Mức | Mã | Mô tả | Vị trí | Cách sửa | Test cần có | Giờ |
|---|---|---|---|---|---|---|---|
| A1 | **High** | DEP-1 (mới) | `next@16.3.6` dính GHSA-cjq9-62q9-8jv4 (SSRF Image Optimization, High), GHSA-4jqv-mc3x-m676 và GHSA-mcj8-r9mp-w47p (cache poisoning SSG/ISR khi tự host), GHSA-f87g-xv8r-7p7x, GHSA-3w37-wq28-93x7; kéo theo `sharp@0.35.4` (librsvg, High) và `source-map-js@1.2.1` (DoS, High) | `frontend/apps/web/package.json:23`, `frontend/apps/admin/package.json:22`, `frontend/pnpm-lock.yaml` | Nâng `next` lên `>=16.3.8` ở cả 2 app. Kiểm `sharp >=0.35.5` và `source-map-js >=1.2.2` đã được kéo theo; nếu chưa thì thêm `pnpm.overrides`. Đây là nâng bản vá của package đã duyệt; nếu cổng G2 bắt buộc thì cần PO xác nhận | `pnpm audit --prod` không còn High/Moderate; `lint`, `typecheck`, `test`, `build` của web và admin; e2e smoke (đăng nhập, danh mục, giỏ, đơn thủ công, quản trị đơn); gọi `/_next/image?url=http://169.254.169.254/...` phải trả 400 | 2–3 |
| A2 | **Medium** | T03-M1 + T28-1 | Người ngoài khoá được đăng nhập của người khác: 10 lượt sai/giờ theo tài khoản là trả 429 cho cả người đúng mật khẩu, áp cho cả staff. Ở V1, QTV phải đăng nhập để duyệt đơn US-022, và email liên hệ của QTV/hỗ trợ là công khai. Kẻ xấu chỉ cần khoảng 10 request/giờ là chặn được việc duyệt đơn | `backend/app/Services/Auth/LoginService.php:46-55` (`reserveAttempts`), `backend/app/Services/Auth/Staff/StaffAuthService.php` (dùng chung `reserveAttempts`), FE: form đăng nhập web + admin | **Đề xuất (cần PO chọn):** khi bộ đếm theo tài khoản ≥ 10 thì trả 422 `CAPTCHA_REQUIRED` thay cho 429. Request có `captcha_token` hợp lệ (Turnstile, `CaptchaVerifier` đã có) thì vẫn so mật khẩu, với trần cứng 100 lượt/giờ/tài khoản. Giữ 429 theo IP (50/giờ). FE hiện widget Turnstile khi nhận `CAPTCHA_REQUIRED`. Cập nhật contract §1.6. **Phương án rẻ hơn (khoảng 3 giờ, yếu hơn):** khoá theo cặp tài khoản+IP (/64 với IPv6) cộng trần toàn cục 100/giờ/tài khoản | 10 lượt sai từ IP-1 thì IP-2 đăng nhập đúng không captcha → `CAPTCHA_REQUIRED`, có captcha → 200; sai liên tục có captcha → 429 ở lượt 101; race 30 request song song (nhóm `race`) không vượt trần; làm tương tự cho staff (T28); e2e FE hiện captcha | 8–10 (BE 4, FE 3, QA 2) |
| A3 | Low (PII) | T03-L2 (mở rộng) | `QueryException` được report kèm SQL có giá trị binding (email, SĐT, hash mật khẩu, liên hệ phụ huynh), đi thẳng vào `laravel.log`. Nhánh rethrow của đăng ký là một ví dụ, nhưng mọi lỗi DB có PII đều dính. Liên quan tới việc tối thiểu hoá dữ liệu trong log (LBVDLCN 2025) | `backend/bootstrap/app.php:148-175` (`withExceptions`), `backend/app/Services/Auth/RegistrationService.php:101` (`default => throw $e`) | Trong `withExceptions` thêm `$exceptions->report(function (QueryException $e) { Log::error('db.query_failed', ['sql' => $e->getSql() /* chỉ placeholder */, 'sqlstate' => $e->errorInfo[0] ?? null, 'driver_code' => $e->errorInfo[1] ?? null, 'connection' => $e->getConnectionName()]); return false; });`. Không ghi `getMessage()` và `getBindings()` | Ép lỗi unique với index lạ khi đăng ký (hoặc `DB::insert` sai kiểu có email): `Log::spy`/đọc file log không có `@`, không có số điện thoại, không có `$2y$`; response vẫn 500 kèm `request_id` | 2 |
| A4 | Low | T14-2 + RL-1 (mới) | Route học sinh đã đăng nhập mà chưa có throttle: `POST /courses/{course}/free-enrollments` (vòng xin → bị từ chối → xin lại tạo dòng lịch sử không giới hạn), `GET /learn/courses/{course}`, `GET /learn/lessons/{lesson}` (đọc nặng) | `backend/routes/api.php:191-193`, `:230-235`; `backend/app/Providers/AppServiceProvider.php:131` (`configureRateLimiters`) | Thêm limiter `free-enrollment` (10/phút + 30/ngày theo user) và `learn-read` (120/phút theo user), gắn vào 3 route trên | Request thứ 11/phút vào free-enrollments → 429; test kiến trúc mới: mọi route trên host api và admin-api có method ghi (POST/PUT/PATCH/DELETE) phải có middleware `throttle:*` (liệt kê ngoại lệ có lý do, ví dụ logout) | 1,5 |
| A5 | Low | Guard-1 (cụm 4 ghi chú, T03-L4 phần guard; **đang làm một phần ở working tree: GL-1**) | `ProductionConfigGuard` chưa chặn các cấu hình nguy hiểm sau: `MAIL_MAILER=log`/`array` (mã OTP và mã đặt lại mật khẩu bị ghi vào log, ai đọc được log là chiếm được tài khoản), `TURNSTILE_SECRET` rỗng hoặc là **khoá test của Cloudflare** (`1x0000…`/`2x0000…`/`3x0000…`: khoá `1x` luôn trả success nên mất hẳn lớp chống bot), `REDIS_PASSWORD` rỗng, `DB_USERNAME=root` | `backend/app/Support/ProductionConfigGuard.php:411` (`guardCaptcha`), `check()` dòng 95-117 | Working tree đã có `guardCaptchaSecretAndMailer()` (secret rỗng, mailer `log`/`array`/rỗng); **còn thiếu**: secret/site key không được bắt đầu bằng `1x0000`, `2x0000`, `3x0000` (khoá test). Thêm `guardInfraCredentials()`: chặn `REDIS_PASSWORD` rỗng và `DB_USERNAME` là `root`. Thông báo lỗi chỉ nêu tên biến. Cập nhật baseline của các test guard cũ (T01, T04, T11, T31) | Mỗi điều kiện có 1 test ném `RuntimeException` ở `production` và `staging`, và 1 test không ném ở `local`/`testing`; `WorkerVideoEnvTest` vẫn qua (worker có `MAIL_MAILER` không? nếu worker không gửi mail thì miễn bằng `runningInConsole` + biến riêng, ghi rõ) | 2 |

Tổng nhóm A: **khoảng 16–19 giờ** (hoặc 11–12 giờ nếu PO chọn phương án rẻ cho A2).
Giao `laravel-dev`: A2 (BE), A3, A4, A5. Giao `nextjs-dev`: A1, A2 (FE).

---

## B — Xử lý bằng cấu hình, hạ tầng, deploy

Tất cả đưa vào `docs/ops/production-checklist.md`. Cột "Checklist" ghi mục đã có sẵn, hoặc **(thêm)** nếu checklist còn thiếu.

| Mã | Mức | Mô tả | Checklist |
|---|---|---|---|
| CFG-1 (mới) | Medium | Mẫu env production đang đặt `FEATURE_MANUAL_PAYMENT=false` (`infra/production/.env.production.example:115`), trong khi bảng cờ ở §1.3 **không có dòng nào** cho cờ này, `PAYMENT_CONTACT_*` hay `ORDERS_MANUAL_NOTIFY_EMAILS`. V1 cần cờ này bật, kênh liên hệ thật, và hộp thư nhận "đơn mới" có người đọc | §1.3 **(thêm dòng)** `FEATURE_MANUAL_PAYMENT=true` + điều kiện (đã deploy T39 + FA8, guard `guardManualPayment`) |
| CFG-2 (mới) | Medium | Danh sách lệnh lịch ở §6 thiếu `orders:expire-manual` (đơn 72h không tự hết hạn, giữ chỗ mã mãi mãi), `orders:purge-customer-notes`, `orders:purge-staff-notes` (thời hạn lưu PII PO đã chốt), `images:prune-orphans` | §6 **(thêm 4 lệnh)**, lấy theo `backend/app/Providers/OperationsServiceProvider.php:36-60` |
| MF1-1 | Low | Đặt nhầm `APP_ENV=local` thì hạn mức OTP nới lên 1000/giờ; guard không phủ được trường hợp này | §1.1 dòng 1 (đã có), §10 so `about` |
| T26-1 | Low | `INTERNAL_API_REQUIRED=true` + token hex | §1.1 (đã có) |
| T26-3 | Low | Nginx xoá `X-Internal-Token`/`X-Client-IP` | §3 (đã có), kiểm lại trên staging |
| T26-2 | Low | Trần `ssr-total` là điểm DoS chung | §3 `vv_web` (đã có); WAF/CDN là việc của hạ tầng |
| T05-2 | Low | Tách DB Redis (code đã tách: session=1, cache=2, limiter=4) | §4 (đã có) |
| T08-2 | Low | Host tĩnh `uploads`: CSP sandbox, nosniff, miền riêng không cookie | §3 (đã có), §9 |
| T11-4, T22-4 | Info | Scheduler phải chạy (`videos:*`, `quizzes:auto-submit-expired`) | §6 (đã có) |
| T12-4 | Low | `limit_req` cho `/videolab/cdn/`, `/videolab/tus`, `X-Accel-Redirect` | §3.1 (đã có) |
| T12-5 | Low | Allow-list `/videolab/library/` theo IP app server cụ thể | §3.1 (đã có) |
| T12-6, T12-10 (phần DB/Redis) | Low | worker-video dùng image build sẵn, user DB `vv_worker_video` chỉ có quyền trên `vl_videos`, Redis ACL | §5, §7, §4 (đã có) |
| T12-8 | Info | Cập nhật ffmpeg định kỳ (CVE demuxer) | §7 **(thêm)** "rebuild image worker-video mỗi tháng/khi có CVE ffmpeg" |
| T13-6 | Info | Log `playback` có IP+UA, giữ 90 ngày | §8 (đã có) |
| T22-3 | Info | Đồng bộ NTP giữa các app server (ân hạn quiz) | §5/§6 **(thêm)** "chrony/NTP trên mọi máy" |
| T23-3 | Info | Đo p95 `/me/courses` trên staging | §5 (đã có) |
| T27-3, T04-5 | Info | Giám sát tỉ lệ lỗi gửi OTP, cảnh báo khi bị dò mã | §6 `ops:health` **(thêm)** "cảnh báo khi `Gửi OTP thất bại.` > N/giờ" |
| T29-S6 | Low | Giám sát số `parent_notice.sent`/giờ (thư gửi bên thứ ba) | §6 **(thêm)** |
| T29-S4 (phần vận hành) | Low | `failed_jobs.exception` có thể chứa email người nhận (lỗi SMTP). Mặc định code giữ 720 giờ (`config/ops.php:29`), backlog đề xuất 168 | §8 **(sửa)** `OPS_FAILED_JOBS_RETENTION_HOURS=168` |
| T03-L4 (phần Cloudflare) | Low | Turnstile không kiểm `hostname`/`action` ở server | §1.1 **(thêm)** "widget Turnstile chỉ cho hostname production; staging dùng widget riêng" |
| T17-2 | Low | Allowlist `MOMO_PAY_URL_HOSTS`: guard đã ép ở production (`ProductionConfigGuard.php:695`) | §1.2 (đã có), chỉ có tác dụng khi bật MoMo |
| Cụm 4 I1 | Info | File log tạo với quyền 0644 | §8 (đã có: thư mục 0750); có thể đặt `permission => 0640` trong `config/logging.php` |
| Cụm 4 I2 / N2 | Info | Response do Nginx tự trả (403, 404, 413, 429 của `limit_req`) không có `nosniff`/`X-Frame-Options` | §3 **(thêm)** `add_header ... always` ở cấp `server` cho các host API |
| Cụm 4 I3 | Info | `/api/v1/health` luôn trả `ok` kể cả khi DB sập | §6: probe dùng `ops:health`, không dùng `/api/v1/health` |
| Cụm 4 I4 | Info | HSTS: Next đã gửi `includeSubDomains; preload` (`frontend/apps/web/proxy.ts:64`), nhưng chưa đăng ký preload; `ssl_ciphers` để mặc định | §3 **(thêm)** "mọi subdomain đã HTTPS trước khi nộp hstspreload; bật OCSP stapling" |
| RL-2 (mới) | Low | Host `admin.vitaminvui.vn` (Next admin) không có `limit_req` như host web | §3 **(thêm)** zone `vv_admin` |
| SEC-1 (mới) | Info | Token `INTERNAL_API_TOKEN` của máy dev nằm trong `frontend/apps/web/playwright.fw8qa.config.ts:21`; mật khẩu tài khoản demo nằm ở `backend/config/auth.php:171` (giá trị mặc định), `docs/board.md:44`, `docs/qa/FW1-ADR006.md`, `docs/review/security-cum1-fix.md`, `frontend/apps/web/e2e/auth.spec.ts` | §2 **(thêm)** "production/staging không dùng lại token dev; không chạy `db:seed`; nếu staging chạy seeder thì đặt `DEMO_ACCOUNT_PASSWORD` riêng". Seeder đã chặn ngoài `local` (`backend/database/seeders/DatabaseSeeder.php:21`) |
| T37-S3 | Medium (cổng) | Cấu hình Token Auth của Bunny | §2.1, **chỉ khi chuyển sang `VIDEO_PROVIDER=bunny`**; V1 không áp dụng (xem D) |
| T12-9 (phần vận hành) | Low | Video kẹt ở status 1–3 khi job mất (flush Redis) | §7 **(thêm)** "giám sát `vl_videos` status 1–3 quá 2 giờ"; sửa code để V2 |

---

## C — Chấp nhận rủi ro cho V1 (cần PO xác nhận)

Lý do chung: mức Low/Info, cần có tài khoản hoặc phiên hợp lệ, hoặc tác động chỉ là lệch thông điệp, lệch số đếm nhỏ, hiệu năng. Mục có ghi "PO đã chấp nhận" thì chỉ cần PO xác nhận lại cho go-live.

### C1. Medium

| Mã | Mô tả | Lý do chấp nhận |
|---|---|---|
| T38-S2 | Nhiều tài khoản giữ hết lượt mã giảm giá trong 72h (đơn thủ công) | PO đã chấp nhận 2026-10-09; QTV huỷ đơn (T39) để nhả mã; mỗi tài khoản tối đa 5 đơn/ngày |

### C2. Low / Info — xác thực, phiên, OTP

| Mã | Mô tả | Lý do |
|---|---|---|
| T03-L1 | Khoá theo IP dùng nguyên địa chỉ IPv6 (nên gộp /64) | Trần theo tài khoản vẫn giữ; nếu chọn phương án rẻ của A2 thì gộp /64 luôn |
| T03-L5 | Test "11 IP" dùng `X-Forwarded-For` giả | Chỉ là chất lượng test, không ảnh hưởng production |
| T03-Info | Bcrypt chỉ dùng 72 byte đầu trong khi `max:128`; chưa có `uncompromised()` (đã có danh sách mật khẩu phổ biến cục bộ) | Ảnh hưởng nhỏ; theo backlog |
| MF2-R3 | `decrement` lúc khoá vừa hết hạn tặng tối đa 1 lượt | Đã giảm nhẹ; theo backlog |
| MF2-R4 | Oracle yếu khi kẻ tấn công biết cả email và SĐT | Cần biết trước cả 2 định danh |
| T04-2 | OTP lưu bcrypt, không gian 10^6 | Chỉ có ý nghĩa khi DB bị lộ, mã sống 10 phút |
| T04-3, T04-4 | Throttle OTP đếm cả request sai định dạng/422 | Bất tiện cho người dùng, không mở lỗ |
| T05-1 | Id session lạ được tái tạo (fixation nhẹ) | Login luôn `regenerate()` |
| T05-2 (phần còn lại) | Mất cache thì báo `UNAUTHENTICATED` thay `SESSION_REPLACED` | Chỉ sai thông điệp; Redis DB đã tách |
| T05-3 | Ép thông điệp `SESSION_EXPIRED` bằng UUID thiết bị | PO đã chấp nhận |
| T05-4 | Khoá học sinh phải gọi `revoke()` | Chưa có chức năng khoá học sinh; theo dõi |
| T28-2 | MFA dùng chung trần OTP 5/giờ, 10/ngày | Idle 120 phút, tuyệt đối 12 giờ nên QTV khó chạm trần |
| T28-3 | Session cũ của staff còn trong store tới hết TTL | `AuthenticateSession` huỷ khi dùng lại |
| T28-4 | Thiết bị mới của GV dựa vào `X-Device-Id` do client khai | Chỉ ảnh hưởng email cảnh báo; cần có mật khẩu + MFA |
| T28-5 | Staff được nhiều phiên song song | Thiết kế |
| T27-1 | Kẻ ngoài khoá được việc đặt lại mật khẩu (5/phút, 20/ngày) | DoS nhẹ, vẫn đăng nhập bình thường |
| T27-2 | Chưa gửi thư "mật khẩu vừa đổi" | Nên làm ở V2 |
| T27-4 | Reset xong không xoá `login-fail:*` | Bất tiện |
| T27-6 | `killSession` chạy trong transaction (`PasswordService.php:229` → `StudentSessionService::revoke`); commit lỗi thì người dùng bị văng phiên oan | Hiếm, fail-safe |
| T27-7 | Cooldown OTP dùng chung mọi purpose; mã reset chưa bị huỷ khi `change()` | Theo backlog |
| R4 (cụm 1 fix) | Kẻ cầm phiên đốt hạn mức `current-password-fail` | Đã ghi nhận |
| Cụm 1 I1 | Đăng nhập staff không đổi CSRF token (`StaffAuthService.php:102`, `:142` chỉ `regenerate()`) | Cần đặt token trước (login CSRF); SameSite=Strict ở admin. Sửa 1 dòng `regenerateToken()` nếu tiện |
| Cụm 1 I2 | `staff.login_failed` của tài khoản không tồn tại không lưu định danh đã thử | Điều tra khó hơn; V2 |
| Cụm 1 I5 | Origin admin nằm trong stateful của host api | CORS chỉ 1 origin cho mỗi host, vẫn cần CSRF |
| T33-1 | Mật khẩu khởi tạo staff trả trong JSON | PO đã quyết "Admin tự gửi" (FA10) |
| T33-2, T33-6 | Phiên bản huỷ phiên nằm trong cache: mất Redis thì phiên cũ sống lại tới hết idle | Khoá/mật khẩu vẫn chặn qua DB |
| T33-5 | Khoá/đổi vai trò không huỷ OTP MFA đang chờ | Mã vẫn cần phiên + mật khẩu mới |
| R6-T33 | `StaffAccountService` chưa có retry deadlock | Độ bền, không phải lỗ |

### C3. Low / Info — nội dung, học tập, video, giỏ hàng

| Mã | Mô tả | Lý do |
|---|---|---|
| T08-1 (phần còn lại) | Upload ảnh khoá học chưa có throttle/quota riêng (job dọn ảnh mồ côi đã có) | Chỉ staff/GV đã MFA |
| T08-3 | GV sửa mô tả khoá đang bán không qua duyệt lại | PO đã chấp nhận ở MVP |
| T09-1, T09-5, T21-1 | Route ghi chương/bài/quiz chưa có throttle riêng; chưa có trần tổng số chương/bài | Chỉ staff/GV; A4 có test kiến trúc sẽ bắt, có thể thêm `throttle:120,1` cho nhóm staff luôn |
| T09-3, T09-4 | Link ngoài không kiểm video có thật/riêng tư; Vimeo có hash bị từ chối | Đã chấp nhận (tránh SSRF) |
| T10-2 | Danh mục không có cache phía Laravel | Hiệu năng |
| T10-3, T23-2 | `bio`, `rejection_reason` là văn bản thuần, FE phải render text | FE đang render qua React (tự escape) |
| T11-2, T11-3, T11-7, T11-8 | Hạn mức video/ngày; limiter chuỗi `throttle:20,1`; dò allowlist provider | Chỉ staff |
| T11-5, T12-3, T12-7 | Chữ ký TUS 6h; khoá dòng khi PATCH; không có DRM | Đã chấp nhận ở ADR-002 |
| T12-9 (code) | Chưa có lệnh cứu video kẹt status 1–3 | Độ bền; B có giám sát |
| T12-10 (code) | Stdout của ffprobe chưa giới hạn kích thước; so `Content-Type` TUS bằng `!==` | Theo backlog |
| T13-1, T13-2 | Tua nhanh đạt 90% với tốc độ ~2,5×; cảnh báo bất thường chỉ ghi log | Hạn chế đã chấp nhận ở US-006 |
| T13-3, T13-4, T13-5 | Preview throttle 30/phút/IP; ràng IP; link HLS sống tới hết TTL | Đã chấp nhận |
| T14-1 | GV không phụ trách dò được id enrollment (403 so với 404) | Chỉ staff |
| T16-3, T16-4 | `GET /cart` có ghi DB; trần lần sai mã nằm ở Redis | Đã chấp nhận ở V1 |
| T18-5 | Tạo/huỷ đơn liên tục để giữ lượt mã | Trùng T38-S2; áp cả đơn thủ công |
| T21-5 | Chưa chuẩn hoá NFC/homoglyph trong văn bản quiz | Theo backlog |
| T22-1 | Lời giải hiện ngay sau lượt đầu | Đúng yêu cầu PO |
| T22-2, T22-5 | Chưa có trần số lượt quiz tạo mới/giờ, tổng lần autosave | Dung lượng có chặn |
| T23-4, T23-5, T23-6 | Tối ưu truy vấn tiến độ | Hiệu năng |
| Cụm 2 L5(b) | Webhook VideoLab không có timestamp/nonce | Chỉ kích hoạt pull-verify |
| Cụm 4 I5 | Miễn kiểm `TRUSTED_PROXIES` cho console bị vô hiệu nếu chuyển sang Octane | V1 dùng FPM |
| T33-3 | Thiếu chỉ mục `audit_logs` | Hiệu năng |
| T36-I1 | Ảnh GV chưa đồng ý vẫn nằm trên miền tĩnh (URL UUIDv4) | Đã chấp nhận |
| T36-I4 | Đồng ý lại để lại 2 dòng `consents` | Chờ pháp chế chốt cách ghi bằng chứng |
| T36-I6 | Admin "đồng ý thay" gián tiếp qua reset mật khẩu GV | Truy vết được qua audit |
| T36-R7-SQL, T36-L3-PSL | `OR` chéo bảng; heuristic registrable domain | `vitaminvui-media.net` đúng heuristic |

### C4. Low — US-022 (thanh toán thủ công), dữ liệu cá nhân

| Mã | Mô tả | Lý do |
|---|---|---|
| T38-S5 | Spam hộp thư QTV (5 thư/ngày/tài khoản) | PO đã chấp nhận |
| T39-S2 | Duyệt muộn có cửa sổ ngắn cấp quyền cho tài khoản vừa ẩn danh | Có `Log::warning` để vận hành xử lý |
| T39-S4 | `order_notes`, `order_status_logs` chỉ bất biến ở tầng Eloquent (`vv_app` có DELETE ở mọi bảng) | Có audit 24 tháng (bất biến bằng trigger); nếu muốn chặt hơn: trigger như `audit_logs`, khoảng 2 giờ |
| T24-V1-S4 | Mẫu che email lộ tên miền (ví dụ tên miền trường) | Chỉ staff thấy |
| T29-S4 (code) | Email phụ huynh có thể lọt vào `failed_jobs.exception`/log worker | **Đang sửa ở working tree** (`ParentNoticeMail::send()` ném lại exception đã che bằng `Mask::emailsInText`). Còn lại: `OtpMail`, `ManualOrder*Mail`, `NewManualOrderStaffMail`, `OrderPaidMail` chưa che, nhưng địa chỉ là của chính học sinh/staff; B rút thời hạn còn 168 giờ |
| T29-S6 (code) | Chưa có trần tổng thư phụ huynh/giờ | **Đang sửa ở working tree** (`parent-notice:global`, `PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP` mặc định 500). Lưu ý cho reviewer: bản đó dùng `RateLimiter::hit` (không nguyên tử, xem MF2-R5); với trần mềm 500/giờ thì chấp nhận được |

---

## D — V2-MoMo / chưa áp dụng ở V1

| Mã | Mức | Mô tả | Ghi chú |
|---|---|---|---|
| T17-1 | Medium | Trường ký phản hồi query MoMo chưa đối chiếu sandbox | Trước T20 |
| T17-3, T17-4 | Low/Info | `orderExpireTime`, mã resultCode, IP allowlist MoMo | V2 |
| T18-1 | Medium | Huỷ đơn pending không đối soát attempt MoMo cũ | Chỉ khi có cổng thật |
| T18-2, T18-4, T18-6 | Low | Attempt mồ côi, cổng `fake`, `create_response` lưu nguyên | V2 (`fake` bị guard chặn ở production) |
| Cụm 3 L4, I2, I4 | Low/Info | Đối chiếu amount/chữ ký, checklist T19/T20, gỡ `ipn_ready` | V2 |
| T37-S2a, S3, S5, S6, S10 | Medium/Low/Info | Bunny: dung lượng thật, Token Auth, chữ ký TUS, ràng IP dual-stack, DRM | **Bắt buộc làm (S3) trước khi đổi `VIDEO_PROVIDER=bunny`**; V1 dùng VideoLab |
| T37 pháp chế | — | DPA với Bunny (EU), chuyển dữ liệu xuyên biên giới | Chỉ khi bật Bunny; PO đã nói "không được chuyển dữ liệu ra nước ngoài" → cần pháp chế trước khi bật Bunny |
| T11-6, T12-1 (phần Bunny) | Low | Throttle webhook theo guid cho Bunny | Khi bật Bunny |
| R3 (cụm 1 fix) | Low | Đổi SĐT không gửi thư/huỷ phiên | Chỉ khi bật kênh `sms` (guard đang cấm `sms`) |
| T03-L3 | Low | Khai tuổi giả để bỏ qua phụ huynh | **Hết hiệu lực phần tuổi do ADR-006** (không còn đồng ý phụ huynh); phần "liên hệ phụ huynh trùng của chính học sinh" vô hại vì chỉ là thư thông báo |
| T24-V1 index | — | Chỉ mục `orders(payment_method, created_at)` | Hiệu năng, không phải bảo mật |

---

## Đã sửa nhưng backlog chưa cập nhật

Đề nghị cập nhật `docs/security/backlog-v2.md` theo bảng này.

| Mã | Bằng chứng |
|---|---|
| T28-6 | `backend/bootstrap/app.php:119` `redirectGuestsTo(fn () => null)`; test `tests/Feature/T28/QaGapsTest.php`, `tests/Feature/T01/ErrorEnvelopeTest.php` (Sửa lỗi nhỏ 1, `3366d60`) |
| R1 (cụm 1 fix, Medium) | `frontend/apps/web/components/account/ChangeContactForm.tsx` gửi `current_password`, có `ChangeContactForm.test.tsx` (`454dc23`, FW1 `5a6d024`) |
| T21-2 | `frontend/apps/web/lib/quiz/math.ts` `KATEX_OPTIONS` (`trust:false`, `maxExpand`, `maxSize`, giới hạn 2.000 ký tự), có `math.test.ts` (FW5 `10f47d2`); admin dùng cùng cấu hình |
| T21-3, T21-4 | `backend/app/Services/Quiz/QuizAttemptService.php:23-25` (`sharedLock` dòng quiz); test `tests/Feature/T22/AttemptFlowTest.php`, `EdgeQaTest.php` khẳng định không có `is_correct` (T22 `7919cab`) |
| T36-I5 | `backend/app/Services/Privacy/AccountAnonymizer.php:128` gọi `teacherProfiles->erase()` (T34 `4cc9672`) |
| T36-M1-FE | Ảnh GV không đi qua `/_next/image` (`unoptimized`): `frontend/apps/web/components/home/TeacherPhoto.tsx:27`, `catalog/TeacherList.tsx:13` (FW9 `0bb5497`) |
| T09-6 | `videos:prune-orphans` chạy mỗi giờ (`OperationsServiceProvider.php:38`) |
| T08-1 (phần job dọn ảnh mồ côi) | `images:prune-orphans` 04:10 (`OperationsServiceProvider.php:40`); còn throttle/quota (C) |
| T17-2 | Guard ép `MOMO_PAY_URL_HOSTS` ở production (`ProductionConfigGuard.php:695`) |
| T05-2 (phần chính) | Redis tách DB: `config/database.php:182` (session 1), `:191` (cache 2), `:224` (limiter 4) |
| T03-L4 (phần guard `fake` ở staging) | `guardCaptcha` áp cho mọi môi trường trừ local/testing (T31); secret rỗng thì `TurnstileVerifier.php:19` fail-closed (nhưng guard không báo → A5) |
| T24-V1-S6, T39-S3 | `51114b5` T38-2: `orders:purge-staff-notes` xoá `refund_note`, `payment_reference`, `cancel_reason_public`, `order_notes.body` sau 7 ngày, và xoá khi xoá tài khoản; còn audit `view_pii` 24 tháng (pháp chế) |
| T24-V1-S3 | PO quyết 2026-10-09: quản lý trang được hoàn tiền (ghi trong story US-022, commit `51114b5`) |

---

## Đang sửa trong working tree (chưa commit, tính tới 18:05 2026-10-09)

Không phải phần việc của báo cáo này. Ghi lại để orchestrator không giao trùng. Cần review/QA như bình thường rồi mới đánh dấu "đã sửa" trong backlog.

| Mã | File đang đổi | Ghi chú security |
|---|---|---|
| A5 (một phần, "GL-1") | `backend/app/Support/ProductionConfigGuard.php` (`guardCaptchaSecretAndMailer`) + test T01/T31/T38 | Còn thiếu khoá test Turnstile, `REDIS_PASSWORD` rỗng, `DB_USERNAME=root` |
| T29-S4 | `backend/app/Mail/ParentNoticeMail.php`, `backend/app/Support/Mask.php` (`emailsInText`) | Ném exception mới không có `previous`: log mất stack gốc (chấp nhận được). Cần test transport giả ném lỗi có email |
| T29-S6 | `backend/app/Services/Privacy/ParentNotifier.php`, `backend/config/privacy.php` | Xem ghi chú ở C4 |
| Khác (StaffAccountService, Coupon/Quiz resource, route admin, race worker T18) | nhiều file | Thuộc BE-backlog-1, chưa đánh giá |

## Rà nhanh ngoài backlog

| Hạng mục | Kết quả |
|---|---|
| `APP_DEBUG`/`APP_ENV` (`ProductionConfigGuard`) | Đạt: ép `app.debug=false` ngay đầu, `APP_ENV` chỉ nhận 4 giá trị, chặn chú thích cuối dòng, `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, captcha `fake`, OTP `sms`, `TRUSTED_PROXIES` `*`/rỗng, stateful domains, MFA staff, `AUTH_OTP_E2E_RELAXED`, khoá VideoLab/Bunny, `FEATURE_PAID_CHECKOUT` khi `ipn_ready=false`, cấu hình thanh toán thủ công. Còn thiếu: A5 |
| CORS / Sanctum | Đạt: mỗi host đúng 1 origin, đặt lúc chạy (`ConfigureHostContext`), `supports_credentials=true` nhưng không có wildcard; guard so khớp chính xác `SANCTUM_STATEFUL_DOMAINS` với host `FRONTEND_URL`/`ADMIN_URL`; không tin `X-Forwarded-Host` (`bootstrap/app.php:92-101`) |
| Cookie | Đạt: Secure (guard), `__Host-` trong mẫu env, `SESSION_DOMAIN=null`, HttpOnly mặc định, SameSite Lax (api) / Strict (admin) do `ConfigureHostContext` đặt, phiên mã hoá. Ghi chú: cụm 1 I1 (C) |
| Rate limit endpoint công khai | Đạt: `config/public`, `health`, danh mục (`catalog`), preview playback (30/phút/IP), `csrf-token` (120/phút/IP), huỷ nhận thư phụ huynh (30/giờ/IP), đăng ký (30/giờ/IP), đăng nhập (flood 120/phút + 10 sai/tài khoản + 50 sai/IP), quên mật khẩu (30/giờ/IP + theo tài khoản sau captcha), reset (`otp-verify`), webhook (120/phút/IP), VideoLab TUS/CDN. Thiếu ở route đã đăng nhập: A4 |
| Header bảo mật | API: `SecurityHeaders` (HSTS, nosniff, XFO DENY, CSP `default-src 'none'`, Permissions-Policy, Referrer-Policy). Web/admin: CSP nonce + `strict-dynamic`, HSTS preload (`frontend/apps/*/proxy.ts`). Miền tĩnh: CSP sandbox + nosniff + noindex. Còn thiếu: response do Nginx tự trả (B, cụm 4 I2) |
| Quyền DB production | Đạt (mẫu `infra/production/mysql/grants.sql`): `vv_app` chỉ DML, không có TRIGGER/DDL; `vv_migrate` riêng; `vv_worker_video` chỉ quyền trên `vl_videos` + INSERT `failed_jobs`. `audit_logs`: trigger chặn UPDATE, DELETE chỉ dòng quá 24 tháng (`2026_10_16_100000`); `vv_app` không có DROP nên không TRUNCATE được. Ghi chú T39-S4 (C) |
| Secret trong repo | Không có `.env` thật nào được track hay từng được commit (`git log --diff-filter=A`). Không thấy private key hay token theo mẫu (AWS, GitHub, Slack, Google, Stripe). Mẫu env production/worker để trống hoặc `<placeholder>`. Có giá trị dev (đều có dấu hiệu dev/local, không phải secret production): `backend/.env.example:76` (DB), `:83` (Redis), `:148` (site key test của Turnstile); `infra/.env.example:15`, `:18`, `:20`. Token/mật khẩu demo: xem SEC-1 (B). Không in giá trị nào ra báo cáo |
| Frontend | Không có secret trong `NEXT_PUBLIC_*` (chỉ URL, site key Turnstile, host video/MoMo). `dangerouslySetInnerHTML` chỉ ở `CourseDescription` (DOMPurify), `MathText` (KaTeX `trust:false`), `JsonLd` (escape `<`). `localStorage` chỉ lưu mốc idle của admin, không lưu token. `images.remotePatterns` chỉ có `STATIC_URL` |
| Thành phần | `composer audit`: không có advisory. Laravel 13.33.0, PHP 8.3.35 (còn hỗ trợ bảo mật). `pnpm audit --prod`: 8 (3 high, 4 moderate, 1 low), đều do `next@16.3.6` và phụ thuộc kéo theo → A1. Composer không có Telescope/Debugbar |

## Kết quả công cụ
- `composer audit` (container `php`): `No security vulnerability advisories found.`
- `pnpm audit --prod` (`frontend/scripts/pnpm.sh`): `next >=16.0.0 <16.3.8` (GHSA-cjq9-62q9-8jv4 high, GHSA-4jqv-mc3x-m676, GHSA-mcj8-r9mp-w47p, GHSA-f87g-xv8r-7p7x, GHSA-3w37-wq28-93x7 moderate, GHSA-39w2-rjm5-chcv low); `sharp <0.35.5` (GHSA-wq5f-xc86-pv6w high, qua next); `source-map-js <1.2.2` (GHSA-68fv-2mgg-jv7q high, qua next/postcss và isomorphic-dompurify/jsdom).
- Test: không chạy (nhóm này là đọc code; test dành cho lúc `laravel-dev` sửa nhóm A).

## Test `laravel-qa` nên thêm (sau khi dev sửa A)
1. A2: luồng 10 lượt sai rồi `CAPTCHA_REQUIRED`, có captcha thì 200, trần cứng 429, race nhiều tiến trình (nhóm `race`, chỉ chạy khi load máy < 20); làm cho cả web và admin; e2e widget Turnstile ở cả 2 app.
2. A3: log không có PII khi gặp `QueryException` (email, SĐT, `$2y$`).
3. A4: test kiến trúc "mọi route ghi đều có throttle", và request thứ 11/phút vào free-enrollments → 429.
4. A5: ma trận guard (`MAIL_MAILER`, khoá test Turnstile, Redis/DB) ở `production`/`staging`/`local`.
5. A1: smoke e2e đầy đủ sau khi nâng Next (web + admin), `/_next/image` với URL nội bộ → 400.
6. Staging (B): `curl -sI` header trên response 403/404/413 do Nginx trả; `php artisan schedule:list` có đủ 4 lệnh mới; luồng US-022 với `FEATURE_MANUAL_PAYMENT=true` và hộp thư thật.

## Điểm cần pháp chế / PO quyết
- **PO:** chọn phương án A2 (captcha hay khoá theo tài khoản+IP); xác nhận nhóm C cho go-live V1; duyệt nâng `next` 16.3.8 nếu cổng G2 áp cho việc nâng bản vá.
- **PO:** xác nhận lại T38-S2, T38-S5, T39-S2 (đã chấp nhận trước đó) cho production.
- **Pháp chế (cần bộ phận pháp chế xác nhận):** thời hạn giữ `audit_logs` 24 tháng gồm IP/UA của staff và audit `order.view_pii`; IP/UA trong `consents` sau khi rút đồng ý/xoá tài khoản (T03-Info, T36); việc gửi thư thông báo tới địa chỉ phụ huynh chưa xác minh (T29); log `playback` 90 ngày có IP. Phía kỹ thuật đã có: tự xoá `customer_note` sau 90 ngày, nội dung staff nhập sau 7 ngày, `audit:purge` 24 tháng, `users:purge-unverified` 7 ngày.
- **Pháp chế, trước khi bật Bunny (không thuộc V1):** DPA và chuyển dữ liệu xuyên biên giới (T37). PO đã nói "không được chuyển dữ liệu ra nước ngoài", vì vậy V1 giữ VideoLab.

## Quyết định PO 2026-10-09
- **A2:** chọn phương án đòi captcha (Turnstile, `CAPTCHA_REQUIRED`) khi chạm ngưỡng đăng nhập sai thay vì khoá, kèm trần cứng; áp cho cả học sinh và quản trị.
- **Nhóm C:** chấp nhận toàn bộ cho V1, xử lý dần sau go-live.
- **T29-S6:** giữ trần tổng 500 thư phụ huynh/giờ (`PRIVACY_PARENT_NOTICE_GLOBAL_HOURLY_CAP`).
- **A1 (nâng bản vá Next):** coordinator làm luôn (nâng bản vá package đã có, không thêm package mới).
