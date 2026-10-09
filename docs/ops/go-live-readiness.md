# Báo cáo sẵn sàng go-live production V1

Lập 2026-10-09 (chỉ đọc tài liệu, git log, config; không chạy test/migrate/deploy). Nguồn: `docs/board.md`, `docs/bao-cao-task.md`, `docs/architecture/tasks.md`, `docs/ops/production-checklist.md`, `docs/adr/`, `docs/review|qa|security/*`, `backend/.env.example`, `infra/production/`.

**Kết luận: CHƯA SẴN SÀNG. Code V1 đã đủ và qua review + QA; go-live bị chặn bởi hạ tầng (chưa có staging, chưa có đóng gói frontend production) và các đầu vào của PO/pháp chế.** Không còn task backend/frontend tính năng nào chặn; còn một nhóm việc code nhỏ về cấu hình/triển khai (mục 9).

Phạm vi V1: học sinh (đăng ký/OTP/học video/quiz/khóa của tôi/quyền dữ liệu cá nhân), thanh toán thủ công US-022, quản trị đầy đủ + nhật ký thao tác. `FEATURE_PAID_CHECKOUT=false`, `VIDEO_PROVIDER=internal` (VideoLab).

## 1. Tính năng V1

Cổng: Review / QA / Security lấy từ các file `docs/review|qa|security`. QA của nhiều task nằm ngay trong file review (mục "QA"), không có file riêng trong `docs/qa/`.

| Task | Commit | Review | QA | Security | Ghi chú |
|---|---|---|---|---|---|
| FW1 (+ADR-006), FW2, FW4, FW5, FW6, FW8/9, FA1–FA7, FA10, FA11 | đã commit | APPROVE | PASS | không áp dụng riêng | Nền MVP |
| T29 (bỏ đồng ý phụ huynh, chỉ thông báo) | `b24db58` | APPROVE | PASS | PASS có điều kiện, S1–S3 đã sửa; S4/S6 backlog | Cần pháp chế xác nhận |
| T34 (xuất/xoá dữ liệu, chấp nhận chính sách) | `4cc9672` | APPROVE | PASS | PASS có điều kiện, S1/S3/S4 đã sửa; S2 theo mặc định tạm; S5 chưa làm | |
| FW7 (quyền dữ liệu, huỷ nhận thư, `/dieu-khoan`) | `0487dc0` | APPROVE | PASS | không có file | Nội dung chính sách là bản TẠM |
| SLN8 | `9f0da6d` | APPROVE | PASS | - | |
| T38, T38-1, T38-2 (đặt đơn thủ công, xoá ghi chú 90 ngày / 7 ngày) | `7ddf063`, `bb5c394`, `51114b5` | APPROVE | PASS | T38 PASS có điều kiện: S1 đã xử lý (`AccountDeletionFinalizer`), S2 thoả nhờ T39 | |
| T24-V1 (API admin đơn hàng) | `ff3a8c7` | APPROVE | PASS | PASS có điều kiện; S1, S2 đã sửa; S3 chờ PO | |
| T39 (duyệt/duyệt muộn/huỷ/ghi chú) | `fd7f31b` | APPROVE | PASS | PASS có điều kiện, chỉ Low/Info (S1–S4) | |
| FW3, FA8 | `333e910`, `37a0a65`, `c4ce8cc` | APPROVE | PASS | không có file | |
| T16-1, T33-1, FW3-1, FA12 | `a43b07c`, `66ff847`, `35d62f9`, `b500ba6` | APPROVE | PASS | không có file | **4 commit này chưa push** (`git log origin/main..HEAD`) |

Nợ còn lại ảnh hưởng go-live:

| Nợ | Mức | Chặn? |
|---|---|---|
| `docs/security/backlog-v2.md` còn 34 dòng "Hoãn v2"; board ghi "bắt buộc review lại trước go-live". Chưa có bản rà cuối | Cao | Có (cần một vòng laravel-security rà backlog + diff V1) |
| laravel-security chưa chạy cho FW7, FW3, FA8, T38-1/2, T16-1, FW3-1, FA12, T33-1 (đều thay đổi nhỏ hoặc frontend) | Thấp | Không, nên gộp vào vòng rà cuối |
| Nội dung `/dieu-khoan`, `/chinh-sach-du-lieu` là bản tạm ("chờ pháp chế"), `PRIVACY_POLICY_VERSION=2026-10-tam` | Cao | Có (PO/pháp chế, mục 7) |
| T29-S4/S6, T34-S5 (payload thư trong `failed_jobs`; đã có `queue:prune-failed` 720 giờ), T39-S1..S4, T24-V1-S3 | Thấp | Không |
| Poster người sáng lập, ảnh/câu hỏi thật dùng nội dung tạm | Trung bình | PO quyết trước khi mở |
| Race test T18 làm rò dữ liệu giáo viên vào DB test (đỏ `T36/AdminTeacherProfilesTest` khi chạy sau T18) | Thấp | Không, nợ test |
| Chưa chạy lại `composer ci` / vitest / e2e trên HEAD trong báo cáo này | - | Cần chạy CI sạch ở commit phát hành |

## 2. Migration (30 file, chạy theo thứ tự tên file bằng `php artisan migrate --force` với user `vv_migrate`)

Nếu đây là lần đầu lên production (DB trống) thì mọi rủi ro khoá bảng/backfill dưới đây về 0 vì không có dữ liệu; vẫn phải kiểm trên staging. Nếu DB production đã có dữ liệu thì áp cột "Khi bảng lớn".

| Thứ tự | Migration | Rủi ro | Khi bảng lớn |
|---|---|---|---|
| 1–3 | `0001_01_01_*` users, cache, jobs | - | - |
| 4–9 | personal_access_tokens, audit_logs, consents, otp_codes, staff_devices, subjects | - | - |
| 10–13 | courses, chapters/lessons/video_assets, enrollments/lesson_progress, coupons | - | - |
| 14–17 | carts, quizzes, vl_videos, orders | - | - |
| 18 | `2026_10_14_110000` FK `enrollments.order_id` | FK mới | Chỉ lớn nếu enrollments nhiều dòng |
| 19 | quiz_attempts | - | - |
| 20 | `2026_10_16_100000` **trigger chặn UPDATE/DELETE `audit_logs`** | Cần quyền `TRIGGER` cho `vv_migrate` và `SET GLOBAL log_bin_trust_function_creators=1` trong lúc migrate (trả về 0 sau đó, không ghi vào `my.cnf`). Thiếu thì lỗi 1419. Trigger mang `DEFINER=vv_migrate`: không xoá/đổi tên user này | - |
| 21–23 | `add_notified_at_to_vl_videos`, teacher_profiles (CHECK), video_upload_usages | - | - |
| 24 | `2026_10_20_100000_add_parent_notice_opt_out_at_to_users` | ADD COLUMN nullable, INSTANT trên MySQL 8.4 | Không khoá |
| 25 | `2026_10_20_100000_create_video_provider_migrations` (trùng timestamp với 24, thứ tự theo tên) | - | - |
| 26 | `2026_10_20_110000_backfill_parent_consent_status` | **Backfill**: UPDATE `users.parent_consent_status` về `not_required` theo lô 1.000 id, idempotent, `down()` rỗng (không hoàn tác được) | Mỗi lô khoá ms; code mới phải chạy trước hoặc kiểm/bù sau deploy, vì code cũ còn ghi `pending` |
| 27 | `create_parent_notice_suppressions_table` | - | - |
| 28 | `2026_10_21_100000_add_manual_payment_columns_to_orders` | 3 cột nullable INSTANT + FK `confirmed_by` INPLACE | Không khoá |
| 29 | `create_order_notes_table` | - | - |
| 30 | `2026_10_21_120000_add_payment_method_check_to_orders` | CHECK chạy COPY, chặn ghi (200k dòng khoảng 8 giây) | Chạy giờ thấp điểm |

Sao lưu trước khi migrate = SNAPSHOT ổ đĩa của nhà cung cấp (PO 2026-10-10; không còn script dump):

| Việc | Chi tiết |
|---|---|
| Snapshot nhà cung cấp chụp ngay trước deploy | Ghi tên snapshot trong biên bản; `deploy.sh deploy <sha> --ack-snapshot` xác nhận. Diễn tập khôi phục snapshot trên staging một lần (checklist §11) |
| Chỉ khi `users` đã có dữ liệu | Tạo bảng sao `users_parent_consent_bak_t29` (id, parent_consent_status) trước migration 26; giữ khoảng 30 ngày. Script nằm ở `docs/review/T29.md` mục DBA |
| Sau deploy | `SELECT COUNT(*) FROM users WHERE parent_consent_status <> 'not_required'` phải bằng 0; còn thì chạy lại UPDATE theo lô |
| T36 | Trước deploy: `SELECT COUNT(*) FROM users WHERE role='giao_vien' AND (bio IS NOT NULL OR avatar_path IS NOT NULL)` (checklist §3) |
| Không chạy ở V1 | T29-1 (xoá cột `parent_consent_status`) và T36-1 (xoá `bio`, `avatar_path`) chỉ ở release sau |

## 3. Biến môi trường

So `backend/.env.example` với `infra/production/.env.production.example`: mọi biến US-022/T29/T34 (`FEATURE_MANUAL_PAYMENT`, `ORDERS_*`, `PAYMENT_CONTACT_*`, `PRIVACY_*`, `FEATURE_PARENT_NOTICES`) đã có trong mẫu production. Biến chỉ có ở dev (`AWS_*`, `BCRYPT_ROUNDS`, `BROADCAST_CONNECTION`, `APP_FAKER_LOCALE`) không cần. Mẫu production có thêm `VIDEOLAB_*`, `UPLOADS_PATH`, `REDIS_*`, `SUPPORT_EMAIL`, `OPS_AUDIT_RETENTION_MONTHS`.

| Nhóm | Biến (chỉ tên) | Ai cấp | Ghi chú |
|---|---|---|---|
| Lõi | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, `APP_API_HOST`, `APP_ADMIN_API_HOST`, `FRONTEND_URL`, `ADMIN_URL`, `STATIC_URL` | Hạ tầng + PO (tên miền) | `STATIC_URL` phải là miền riêng; guard chặn nếu sai. `APP_URL` còn dùng cho link huỷ nhận trong thư phụ huynh |
| Phiên/bảo mật | `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT=true`, `SESSION_DOMAIN=null`, `SESSION_COOKIE`/`SESSION_ADMIN_COOKIE` (tiền tố `__Host-`), `SANCTUM_STATEFUL_DOMAINS`, `TRUSTED_PROXIES`, `INTERNAL_API_TOKEN` | Hạ tầng | Guard chặn khởi động nếu sai |
| Mới T29 | `PRIVACY_NOTICE_TOKEN_KEY` (>= 32 byte, riêng, **không bao giờ xoay**), `PRIVACY_PARENT_NOTICE_DAILY_CAP` (1..20), `PRIVACY_POLICY_VERSION` (không rỗng), `PRIVACY_PARENT_CONTACT_SUGGEST_AGE` | Hạ tầng sinh khoá; pháp chế chốt phiên bản | Guard chặn khởi động nếu thiếu/ngoài khoảng |
| Mới US-022 | `FEATURE_MANUAL_PAYMENT`, `ORDERS_MANUAL_PENDING_TTL_HOURS` (1..168), `ORDERS_MANUAL_APPROVAL_WINDOW_DAYS` (0..90), `ORDERS_MANUAL_PER_DAY` (1..50), `ORDERS_CUSTOMER_NOTE_RETENTION_DAYS` (30..3650), `ORDERS_STAFF_TEXT_RETENTION_DAYS` (1..3650), `ORDERS_MANUAL_NOTIFY_EMAILS`, `PAYMENT_CONTACT_PHONE/ZALO_URL/EMAIL/HOURS` | PO | Khi bật cờ, guard bắt buộc có kênh liên hệ hợp lệ và hộp thư nhận thông báo đơn. Mẫu production đã điền giá trị PO chốt 2026-10-09; cần xác nhận hộp thư `hotro@` có người đọc |
| Thư/captcha | `MAIL_*` (SMTP thật), `CAPTCHA_DRIVER=turnstile`, `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET`, `AUTH_OTP_CHANNELS=email` | PO/hạ tầng | Guard KHÔNG kiểm `TURNSTILE_SECRET` rỗng và `MAIL_MAILER=log`; phải kiểm tay bằng đăng ký thử + OTP thật |
| DB/Redis | `DB_*` (user `vv_app`), `REDIS_*` + `REDIS_PREFIX` | Hạ tầng | Mật khẩu khác nhau cho `vv_app`, `vv_migrate`, `vv_worker_video` |
| Video (Bunny) | `VIDEO_PROVIDER=bunny`, `VIDEO_ENABLED_PROVIDERS=bunny`, `BUNNY_LIBRARY_ID`, `BUNNY_API_KEY`, `BUNNY_CDN_HOST=cdn.vitaminvui.asia`, `BUNNY_TOKEN_KEY`, `BUNNY_WEBHOOK_TOKEN` (>= 32 ký tự); `VIDEOLAB_ENABLED=false` | Hạ tầng | Không có worker-video/redis-video ở V1; VideoLab chỉ khi PO bật lại |
| Cờ | `FEATURE_PAID_CHECKOUT=false`, `PAYMENT_GATEWAYS` để trống, `MOMO_*` để trống | - | Guard chặn bật paid checkout khi chưa có IPN |
| Log/vận hành | `LOG_STACK=daily`, `LOG_LEVEL=warning`, `LOG_DAILY_DAYS=90`, `OPS_*`, `SUPPORT_EMAIL` | Hạ tầng | |

Không khớp / thiếu cần xử lý:

| # | Phát hiện | Đề xuất |
|---|---|---|
| E1 | **Không có mẫu env production cho frontend.** Chỉ có `.env.example` local của `apps/web` và `apps/admin`. Biến cần: `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_HOSTS` (host VideoLab `https://video.<domain>`), `NEXT_PUBLIC_TURNSTILE_SITE_KEY`, `API_INTERNAL_URL`, `INTERNAL_API_TOKEN`; admin: `NEXT_PUBLIC_ADMIN_API_URL`, `NEXT_PUBLIC_ADMIN_URL`, `NEXT_PUBLIC_STATIC_URL`, `NEXT_PUBLIC_VIDEO_UPLOAD_URL` | Việc code D2 (mục 9). Biến `NEXT_PUBLIC_*` được nhúng lúc build, phải đúng trước `next build` |
| E2 | `NEXT_PUBLIC_MOMO_HOSTS` mặc định `test-payment.momo.vn` (nếu không đặt) lọt vào CSP production dù MoMo tắt | Đặt rõ giá trị cho production (rỗng hoặc host thật); kiểm `env.ts` cho phép rỗng |
| E3 | `V2_PREVIEW` phải để trống ở production (trang `/v2/*` mặc định trả 404) | Ghi vào mẫu env frontend |
| E4 | Checklist §1.1 không liệt kê `PRIVACY_NOTICE_TOKEN_KEY` (chỉ nằm ở §13), và không có `FEATURE_MANUAL_PAYMENT`, `ORDERS_*`, `PAYMENT_CONTACT_*` ở bất kỳ mục nào | Xem mục "Đề xuất cập nhật checklist" |
| E5 | Mẫu production để `FEATURE_ENROLLMENT_DECISION_MAIL=true`, local `false` | Có chủ đích; chỉ đúng khi SMTP đã chạy |
| E6 | Worker-video cần `STATIC_URL`, `FRONTEND_URL`, `ADMIN_URL`, `SESSION_SECURE_COOKIE`, `SANCTUM_STATEFUL_DOMAINS` hợp lệ (guard chạy cả ở `queue:work`) | Kiểm bằng `php artisan about` trong container worker (test T31 bị Skipped trong container php) |

## 4. Cờ tính năng và thứ tự bật

| Bước | Cờ | Điều kiện trước khi bật | Tắt khẩn |
|---|---|---|---|
| 0 | Deploy với `FEATURE_PAID_CHECKOUT=false` (giữ vĩnh viễn ở V1), `FEATURE_MANUAL_PAYMENT=false`, `FEATURE_PARENT_NOTICES=false` | Migration xong, smoke test đăng nhập/học pass | - |
| 1 | `FEATURE_PARENT_NOTICES=true` | SMTP gửi thật OK, queue worker chạy, FW7 đã lên (trang `/phu-huynh/huy-nhan-thong-bao`), `PRIVACY_NOTICE_TOKEN_KEY` đã đặt, Nginx đã che `?t=` (mục 6), pháp chế đã xem nội dung thư | Đặt `false` rồi `config:cache` + `queue:restart` |
| 2 | `FEATURE_MANUAL_PAYMENT=true` | T39 + FA8 đã deploy (đã đủ trong V1), `PAYMENT_CONTACT_*` + `ORDERS_MANUAL_NOTIFY_EMAILS` hợp lệ và hộp thư có người trực, scheduler chạy `orders:expire-manual`, QTV đã được hướng dẫn quy trình duyệt/huỷ | Đặt `false`: học sinh không đặt đơn mới, đơn cũ vẫn duyệt/huỷ được (xác nhận hành vi trên staging) |
| 3 | `VIDEO_PROVIDER` | **`bunny` ở V1** (PO 2026-10-10; `BUNNY_CDN_HOST=cdn.vitaminvui.asia`); Bunny C1–C9 ĐẠT trên staging (mục 6) là ĐIỀU KIỆN go-live. VideoLab TẮT (`VIDEOLAB_ENABLED=false`, `VV_VIDEOLAB=0`) | Đổi lại `internal` cần bật VideoLab (`infra/production/README.md` mục 12); video đã `--delete-source` thì không hoàn tác được |
| - | `FEATURE_PAID_CHECKOUT` | KHÔNG bật ở V1 (chờ MoMo, T19/T20, T17-1) | - |

Hiện tượng cần biết: `GET /api/v1/config/public` phải trả `paid_checkout_enabled=false` (checklist §1.3).

## 5. Scheduler và queue

`OperationsServiceProvider` đã đăng ký đủ các lệnh dưới đây (đều `withoutOverlapping()->onOneServer()`, cần cache Redis dùng chung):

| Lệnh | Lịch | Thuộc |
|---|---|---|
| `orders:expire-manual` | 15 phút | T38 (huỷ đơn thủ công quá 72 giờ) |
| `orders:purge-customer-notes` | 03:45 | T38-1 |
| `orders:purge-staff-notes` | 03:46 | T38-2 |
| `audit:purge` | 03:40 | T30 (24 tháng; trigger ghi cứng 24 tháng) |
| `users:purge-unverified` | 03:50 | T30 |
| `otp:prune` 03:00, `queue:prune-failed` 03:10 (720 giờ) | | |
| `quizzes:auto-submit-expired` | mỗi phút | T22 |
| `videolab:notify` mỗi phút, `videolab:cleanup` 03:20, `videos:check-stuck` 15 phút, `videos:prune-orphans` giờ, `images:prune-orphans` 04:10, `counters:recount` 03:30 | | |
| `queue:monitor`, `ops:health --log` 5 phút, nhịp scheduler mỗi phút | | |

Đánh giá:

| # | Nhận xét |
|---|---|
| Q1 | Đủ lệnh cho V1. Mẫu Supervisor có `queue:work redis --queue=default,exports`, `schedule:work`, `worker-video`. |
| Q2 | Checklist §6 liệt kê `schedule:list` thiếu 4 lệnh: `images:prune-orphans`, `orders:expire-manual`, `orders:purge-customer-notes`, `orders:purge-staff-notes`. Cập nhật để tránh bỏ sót khi nghiệm thu. |
| Q3 | Thư (OTP, đơn đã thanh toán, thông báo phụ huynh, thông báo đơn mới cho QTV) đi qua queue `default`: worker phải chạy và `failed_jobs` phải được xem hằng ngày. |
| Q4 | `vv_app` cần quyền DELETE trên `audit_logs` (đã có `grants-users.sql`/`grants-worker.sql`); kiểm `php artisan audit:purge --dry-run` trên staging. |
| Q5 | Chưa thấy lệnh dọn payload thư chứa email trong `failed_jobs` ngoài `queue:prune-failed` 720 giờ (T34-S5, mức Low). |

## 6. Việc hạ tầng còn thiếu

| # | Việc | Trạng thái | Ghi chú |
|---|---|---|---|
| H1 | **T35 staging** (image + CI build, server staging) | Chưa làm: không có `.github/workflows`, không có compose production | Chặn: chưa kiểm được bất kỳ mục nào bên dưới trên môi trường thật |
| H2 | **Đóng gói/chạy Next.js production** (`apps/web`, `apps/admin`) | Chưa có Dockerfile hoặc unit systemd/PM2 nào trong repo; `infra/production/` chỉ có Nginx, Supervisor (PHP), MySQL, Redis | Chặn. Cần chốt cách chạy (Docker image hoặc `next start` dưới systemd), chỉ nghe loopback/mạng nội bộ |
| H3 | T31 giá trị thật (tên miền, SMTP, Turnstile, IP LB/app/Next, `<IP_MONITOR_LB>`; DNS-only nên không có `set_real_ip_from`, README mục 12) | Mẫu xong, chưa điền | Chờ PO/hạ tầng |
| H4 | Nginx che token huỷ nhận phụ huynh | Chưa làm: `infra/production/nginx` không có rule nào cho `/phu-huynh/huy-nhan-thong-bao` | FW7 review M2: access log Nginx, Next, CDN ghi `?t=<token>`. Cần log location này theo `$uri` (như `vv_noargs` của webhook Bunny) trên host web và log của Next/LB. `proxy.ts` đã đặt `no-referrer` |
| H5 | Bunny production (C1–C9, `docs/qa/T37.md`) | **CHẶN go-live V1** (video qua Bunny): C2/C3 đã ĐẠT trên thư viện staging | Còn: tạo thư viện production riêng, Allowed domains, token + embed auth, TẮT MP4 fallback, kiểm 403 phủ định, webhook, cảnh báo băng thông. Video dev đang ở Bunny không tự sang production |
| H6 | VideoLab production | KHÔNG triển khai ở V1 (compose profile `videolab` tắt) | Chỉ khi PO bật lại: host `video.<domain>`, volume `videolab`, worker-video, Redis/DB user riêng (README mục 12) |
| H7 | Token CDN ràng IP (`VIDEO_BIND_IP=true`) | Local đã tắt; chưa kiểm thật | Cần `TRUSTED_PROXIES` đúng; kiểm máy dual-stack IPv4/IPv6 trên staging, nếu sai thì phát video 403 |
| H8 | Load test catalog (cache ấm 50 req/s), `limit_req` lớp học chung NAT | Chưa đạt/chưa đo (`frontend/apps/web/loadtest/catalog.k6.js`) | Đo trên staging |
| H9 | Backup = snapshot ổ đĩa tự động hằng ngày của nhà cung cấp (giữ >= 7 ngày) + snapshot thủ công trước deploy có migration + diễn tập khôi phục 1 lần ở staging | Chưa có | Checklist §11; rủi ro chấp nhận: khôi phục cả máy, mất tối đa ~1 ngày, không theo thời điểm. `.env`/secret vẫn cần bản lưu riêng ngoài server |
| H10 | Quyền DB: `vv_app` / `vv_migrate` / `vv_worker_video` theo `grants-users.sql`/`grants-worker.sql`; `log_bin_trust_function_creators` tạm thời khi migrate | Mẫu có, chưa áp | Kiểm `SHOW TRIGGERS LIKE 'audit_logs'` và UPDATE bằng `vv_app` phải lỗi |
| H11 | Redis: ACL, `check-acl.sh`, DB tách, `protected-mode`, tuỳ chọn Redis riêng cho queue video | Mẫu có, chưa áp | |
| H12 | Giám sát/log: nối `ops:health`, `queue:monitor` vào kênh cảnh báo; xoay log; không log body `/auth/*`; cảnh báo băng thông Bunny; (chỉ khi bật VideoLab) theo dõi `LLEN queues:video` | Chưa có | Cần kênh nhận cảnh báo (PO) |
| H13 | DNS (CAA, SPF/DKIM/DMARC, không wildcard), TLS 6 host, header bảo mật, `/up` giới hạn IP, cổng 8081 | Chưa | Checklist §3, §9 |
| H14 | So `php artisan about` staging vs production; `security review T31` trên staging | Chưa | Checklist §10, §12 |
| H15 | Tài khoản admin đầu tiên `php artisan staff:create`; KHÔNG chạy `db:seed` hay seed demo/e2e (tài khoản demo có mật khẩu công khai trong board) | Chưa | |
| H16 | Thay đổi chưa commit trong working tree: `infra/docker-compose.yml`, `infra/nginx/conf.d/vitaminvui.conf` (máy chủ ảnh tĩnh local `:8080`), `docs/board.md` | Chỉ ảnh hưởng local | Commit hoặc loại trước khi cắt phiên bản phát hành để bản build sạch |

## 7. Việc PO / bên ngoài phải cung cấp

| # | Việc | Ai | Chặn? |
|---|---|---|---|
| P1 | Tên miền chính + tên miền tĩnh riêng (không là subdomain), tài khoản DNS, bật khoá chuyển nhượng/2FA | PO | Có |
| P2 | Server production (app, Next, MySQL 8.4, Redis, nơi lưu backup), IP LB/app/Next/văn phòng | PO / hạ tầng | Có |
| P3 | SMTP thật (host, user, mật khẩu, địa chỉ gửi) + SPF/DKIM/DMARC cho `no-reply@` | PO / hạ tầng | Có |
| P4 | Cloudflare Turnstile: bộ key production (và staging) với hostname cho phép | PO | Có |
| P5 | Hộp thư `hotro@vitaminvui.vn` hoạt động, SĐT/Zalo 0915 592 224 có người trực 8h–17h, quy trình QTV duyệt đơn (thu tiền ngoài hệ thống rồi duyệt) | PO | Có, trước khi bật `FEATURE_MANUAL_PAYMENT` |
| P6 | Văn bản chính sách thật (điều khoản, chính sách dữ liệu, nội dung thư phụ huynh) + chốt `PRIVACY_POLICY_VERSION` | PO + pháp chế | Có (không nên mở với bản "tạm") |
| P7 | Pháp chế xác nhận: bỏ đồng ý phụ huynh với học sinh chưa thành niên; gửi tên/đơn/số tiền tới email phụ huynh chưa xác minh; "ẩn danh" hay "giả danh"; file xuất có liên hệ phụ huynh; giữ `audit_logs` (IP/UA) 24 tháng; xoá `consents` khi xoá tài khoản chưa xác thực; lưu dữ liệu trong nước | Pháp chế | Có thể chấp nhận rủi ro bằng văn bản nếu PO quyết |
| P8 | Quyết định còn mở: quyền hoàn tiền của quản lý trang (T24-V1 S3), thời hạn lưu `refund_note`/`order_notes.body`/`payment_reference`/audit `view_pii` (đề xuất 24 tháng), tìm đơn theo SĐT/email, `FEATURE_ENROLLMENT_DECISION_MAIL`, thời hạn log `learning`, giới hạn truy cập admin theo IP | PO | Không chặn nếu chấp nhận mặc định |
| P9 | Nội dung thật: poster người sáng lập, ảnh/câu hỏi thật, hồ sơ giáo viên có đồng ý | PO | Tuỳ PO |
| P10 | Bunny production: tạo thư viện, domain cho phép, ngưỡng cảnh báo băng thông (V1 dùng Bunny; xem H5) | PO | **Chặn go-live** (cùng H5) |
| P11 | Kênh nhận cảnh báo vận hành (email/chat) và người trực; người quyết định rollback | PO | Có |

## 8. Thứ tự deploy đề xuất và rollback

**Trước ngày go-live (chỉ làm trên staging, không chạm production)**

1. [ ] Hoàn tất H1, H2, H3, P1–P4: dựng staging giống production (tên miền, khoá, mật khẩu riêng).
2. [ ] Chạy checklist `docs/ops/production-checklist.md` trọn vẹn trên staging, kể cả §3 Nginx, §4 Redis, §5 MySQL, §7 worker-video, §10 so `about`.
3. [ ] CI sạch trên đúng commit phát hành: `composer ci`, Pint, PHPStan, vitest, lint, typecheck, build; push các commit còn lại; gắn tag SemVer (do PO/người vận hành, agent không tạo tag).
4. [ ] Diễn tập: migrate trên bản sao có dữ liệu mẫu, đo thời gian; diễn tập rollback; gửi OTP + thư phụ huynh + thư đơn thật; xem log không có token/mật khẩu.
5. [ ] Một vòng laravel-security rà `backlog-v2` + diff V1 trên staging.
6. [ ] Smoke test đầy đủ các luồng: đăng ký/OTP, học video (internal), quiz, khóa của tôi, xuất/xoá dữ liệu, huỷ nhận thư phụ huynh, giỏ → đặt đơn thủ công → QTV duyệt → học sinh vào học, huỷ/hết hạn, nhật ký thao tác.

**Ngày go-live (người vận hành chạy)**

1. [ ] Thông báo bảo trì (bật `SESSION_ENCRYPT`/`__Host-` làm mọi phiên đăng xuất một lần). Ghi tên snapshot nhà cung cấp mới + `.env` hiện tại.
2. [ ] `php artisan down --secret=<token>`.
3. [ ] Lấy code/image đúng tag; `composer install --no-dev --optimize-autoloader`.
4. [ ] Điền `.env` theo mục 3 (3 cờ ở bước 0 mục 4: `FEATURE_MANUAL_PAYMENT=false`, `FEATURE_PARENT_NOTICES=false`, `FEATURE_PAID_CHECKOUT=false`); `php artisan about --only=environment` không ném lỗi guard.
5. [ ] `SET GLOBAL log_bin_trust_function_creators=1` (admin) → `php artisan migrate --force` (user `vv_migrate`) → trả về 0. Kiểm `migrate --status`, trigger `audit_logs`, đếm `parent_consent_status <> 'not_required'` = 0.
6. [ ] `php artisan optimize`; build frontend (`NEXT_PUBLIC_*` đúng), khởi động web + admin; reload Nginx (kèm rule che `?t=`).
7. [ ] `php artisan queue:restart`; khởi động worker, scheduler, worker-video; `php artisan schedule:list` đủ lệnh mục 5; `ops:health` xanh.
8. [ ] `php artisan staff:create` (admin đầu tiên), đổi mật khẩu, bật MFA.
9. [ ] `php artisan up`; smoke test (đăng nhập từng vai trò, học một bài, `ops:health`, log không lỗi mới).
10. [ ] Bật lần lượt `FEATURE_PARENT_NOTICES`, rồi `FEATURE_MANUAL_PAYMENT` (mỗi lần: sửa `.env`, `config:cache`, `queue:restart`, thử một ca thật, theo dõi log và hộp thư QTV).
11. [ ] Theo dõi 24–48 giờ đầu: `failed_jobs`, tỉ lệ 429/403 video, hộp thư `hotro@`, đơn chờ duyệt.

**Rollback**

| Điều kiện kích hoạt | Hành động |
|---|---|
| Lỗi đăng nhập/học diện rộng, 5xx liên tục, video 403 hàng loạt sau go-live | Đổi symlink về release trước, `queue:restart`, xoá cache cấu hình, kiểm `ops:health` |
| Thư phụ huynh gửi sai hoặc spam | Tắt `FEATURE_PARENT_NOTICES` (không cần rollback code) |
| Đơn thủ công gặp sự cố nghiệp vụ | Tắt `FEATURE_MANUAL_PAYMENT`; QTV xử lý đơn tồn tay |
| Migration lỗi giữa chừng | Giữ site ở chế độ `down`; xác định migration nào xong; khôi phục snapshot nhà cung cấp nếu `down()` không an toàn |

Lưu ý riêng: migration 26 (backfill) có `down()` rỗng và migration 30 sửa CHECK; rollback DB bằng `migrate:rollback --step=N` chỉ khi từng `down()` an toàn và không mất dữ liệu, còn lại khôi phục từ backup (hoặc `users_parent_consent_bak_t29` nếu đã tạo). Lần go-live đầu (DB trống): rollback DB = khôi phục bản backup trống. KHÔNG `migrate:fresh|reset`, `db:wipe` trên môi trường thật. Sau khi khách đã đăng ký thì khôi phục backup sẽ mất dữ liệu phát sinh: người quyết định rollback sau thời điểm đó phải do PO chỉ định.

## 9. Việc CODE còn lại trước go-live (để coordinator giao dev)

| # | Việc | Đội | Ước lượng | Chặn? |
|---|---|---|---|---|
| D1 | Rule Nginx che query `t` của `/phu-huynh/huy-nhan-thong-bao` trong `infra/production/nginx/conf.d/vitaminvui.conf` (log theo `$uri`), kèm ghi chú cho log của Next/LB; thêm vào checklist | release / ops | 0,25 | Có (trước bật `FEATURE_PARENT_NOTICES`) |
| D2 | Mẫu env production cho `apps/web` và `apps/admin` (E1–E3) và cách đóng gói/chạy Next.js production (Dockerfile hoặc unit systemd, nghe loopback) cho web + admin | dev frontend + release | 1–1,5 | Có |
| D3 | T35: workflow CI build image (backend + 2 app Next) và đẩy registry; compose/triển khai staging | release | 1,5–2 | Có |
| D4 | Rà cuối bảo mật: `backlog-v2.md` (34 dòng "Hoãn v2") + diff V1 (FW7, FW3, FA8, T38-1/2, T16-1, FW3-1, FA12, T33-1); sửa Medium trở lên | laravel-security + dev | 1–2 | Có (theo quy tắc board) |
| D5 | Kiểm `env.ts` cho phép `NEXT_PUBLIC_MOMO_HOSTS` rỗng ở production hoặc đặt giá trị mặc định đúng; xác nhận CSP không còn host sandbox | dev frontend | 0,25 | Không |
| D6 | Cân nhắc thêm vào `ProductionConfigGuard` kiểm `TURNSTILE_SECRET` rỗng và `MAIL_MAILER` là `log`/`array` (L4 backlog; test cũ T01/T04/T11 cần cập nhật baseline) | dev backend | 0,5 | Không (hiện kiểm tay) |
| D7 | T34-S5: dọn payload thư trong `failed_jobs` sau khi xoá tài khoản (hoặc ghi nhận chấp nhận 720 giờ), T29-S4/S6, T39-S1/S4 | dev backend | 0,5–1 | Không |
| D8 | Sửa race test T18 làm rò dữ liệu giáo viên (nợ test) để CI ổn định | dev backend | 0,25 | Không |
| D9 | Chạy lại CI toàn bộ trên commit phát hành (backend + frontend, nhóm race tách riêng khi load < 20) | QA | 0,5 | Có |
| D10 | Cập nhật tài liệu cũ: `docs/bao-cao-task.md` (còn ghi FW7 chưa làm, ngày 2026-10-08), `docs/board.md` (ghi 8 commit chưa push nhưng git chỉ còn 4), checklist §13 ("T34 chưa làm") | coordinator | 0,25 | Không |
| D11 | Sau go-live (không chặn): T29-1 (xoá `parent_consent_status`, gom `parent_notice_opt_out_at`), T36-1 (xoá `bio`, `avatar_path`) | dev backend | 0,75 | Không, chỉ sau khi backfill đã kiểm |

Ngoài phạm vi code: H1–H15 (hạ tầng) và P1–P11 (PO/pháp chế).

## Đề xuất cập nhật `docs/ops/production-checklist.md` (chưa sửa theo yêu cầu)

| # | Mục | Thiếu / lỗi thời | Đề xuất |
|---|---|---|---|
| K1 | §1.1 | Thiếu `PRIVACY_NOTICE_TOKEN_KEY`; thiếu toàn bộ biến US-022 | Thêm dòng bắt buộc cho `PRIVACY_*`, `ORDERS_*`, `PAYMENT_CONTACT_*`, `ORDERS_MANUAL_NOTIFY_EMAILS` và nhắc quy tắc guard |
| K2 | §1.3 | Bảng cờ không có `FEATURE_MANUAL_PAYMENT` | Thêm: mặc định mẫu production `false`; chỉ bật sau T39 + FA8 + kênh liên hệ thật + QTV sẵn sàng |
| K3 | §1.3 / §13 | `FEATURE_PARENT_NOTICES` ghi "mặc định true" nhưng điều kiện bật (FW7, SMTP, Nginx che token) nằm rải rác | Gom thành thứ tự bật cờ như mục 4 của báo cáo này |
| K4 | §3 | Không có mục che token `/phu-huynh/huy-nhan-thong-bao` | Thêm checklist kiểm `grep 't=' /var/log/nginx/*.log` không thấy token (ở cả Nginx, Next, LB) |
| K5 | §6 | `schedule:list` thiếu `images:prune-orphans`, `orders:expire-manual`, `orders:purge-customer-notes`, `orders:purge-staff-notes` | Bổ sung |
| K6 | §8 | Bảng lưu giữ thiếu `orders.customer_note` (90 ngày), nội dung nhân viên nhập (7 ngày), `order_notes`, `refund_note` | Bổ sung kèm lệnh purge và chờ PO chốt thời hạn |
| K7 | §11 | Chưa có bước tạo bảng sao `users_parent_consent_bak_t29` và kiểm sau backfill | Thêm bước vào "Trước deploy" và "Sau deploy" |
| K8 | §13 | "T34 chưa làm" đã lỗi thời (T34, FW7 đã commit) | Cập nhật; thêm ý "chính sách thật thay bản tạm" |
| K9 | Mới | Chưa có mục cho frontend production (đóng gói Next, env `NEXT_PUBLIC_*`, `V2_PREVIEW` trống) | Thêm mục "Frontend" |
| K10 | §12 | Chưa nêu "không chạy seed demo/e2e ở production" | Thêm |
