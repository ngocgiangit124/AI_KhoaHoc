# Truy vết xử lý review thiết kế — 2026-09-25

**Nguồn:** Security `docs/security/audit-2026-09-25.md` (S1–S24, I1–I3) · DBA `docs/db/design-review.md` §3 (#1–#10)
**Trạng thái:** ✅ Đã xử lý trong thiết kế · 🔧 Hiện thực trong task (yêu cầu đã ghi vào task) · ⏳ Mặc định an toàn, chờ PO/pháp chế xác nhận · ⏸ Hoãn có chủ đích

## Security

| # | Mức | Phát hiện | Xử lý ở đâu | Task | Trạng thái |
|---|---|---|---|---|---|
| S1 | High | Laravel 11/MySQL 8.0 hết hỗ trợ | PO chốt **Laravel 13 ngay từ đầu** + PHP 8.3 + MySQL 8.4 LTS (bỏ qua Laravel 12 vì hết vá ~02/2027); CLAUDE.md, ADR-004 Bối cảnh, data-model §0; rủi ro còn lại: package bên thứ ba chưa hỗ trợ Laravel 13 → README R1, tasks.md G2 | T01 | ✅ |
| S2 | High | Upload ảnh SVG → stored XSS trên origin API | api-contract §4 (rule mimes/mimetypes, mã hoá lại WebP, bỏ EXIF, UUID); tên miền tĩnh riêng + CSP sandbox (ADR-004 §2.1, §6); data-model `thumbnail_path`, `avatar_path` | T08, T31 | ✅ (⏳ mua tên miền tĩnh) |
| S3 | High | ffmpeg LFI/SSRF, path traversal, giới hạn TUS | ADR-002 §3a (magic bytes, `-protocol_whitelist`/`-format_whitelist`, Process mảng, container sandbox, ràng buộc route + realpath, TUS max_bytes/TTL/1 upload, CORS riêng, `library/*` nội bộ); api-contract §2.7 | T12 | ✅ |
| S4 | High | FakeGateway/sandbox lọt production | ADR-001 §1 (allowlist `enabled_gateways`, `whereIn`, boot guard, Fake chỉ local/testing); api-contract §2.6; ADR-004 §6 | T01, T17, T19 | ✅ |
| S5 | High | IDOR route quản trị lồng nhau | ADR-004 §3 (scopeBindings, Policy theo khóa gốc, không lấy ID từ request); api-contract §2.5 (curriculum order tập ID khớp, LessonRequest không nhận asset/course, teacher_ids bỏ qua với GV, quiz course suy ra) | T08, T09, T21 | ✅ |
| S6 | High | Cookie `.vitaminvui.vn` dùng chung staging; admin cùng origin | ADR-004 §2 viết lại: 2 host API, cookie host-only tách tên, admin origin riêng + `EnsureAdminOrigin`, staging domain riêng, CSRF qua header, trustHosts, cam kết DNS; api-contract §1.1–1.3 | T01, T28, T31 | ✅ (⏳ tên staging) |
| S7 | High | Thiếu cơ chế đồng ý dữ liệu trẻ em/phụ huynh | data-model `consents`, `parent_consent_status`, cast `encrypted`, §7 retention; api-contract §2.2, §2.8; README §8 (mục 9, 10, 15) + mục pháp chế; story US-017, US-018 | T03, T29, T30, T34 | ⏳ (cơ chế sẵn, **cần pháp chế**) |
| S8 | Medium | Hợp đồng render rich text/LaTeX, CSP | api-contract §4 (Purifier allowlist, quiz văn bản thuần, KaTeX trust:false, cấm dangerouslySetInnerHTML); ADR-004 §2.6 (CSP nonce) | T08, T21, FE0, FW2, FW5 | ✅ |
| S9 | Medium | OTP race, trần ngày, SMS log | data-model `otp_codes` (tăng attempts nguyên tử, random_int, destination binding); api-contract §1.6, §2.2; kênh chỉ email ở production | T04 | ✅ |
| S10 | Medium | Throttle theo IP, TrustProxies | ADR-004 §2.4 (IP cụ thể, không `*`); api-contract §1.6 (2 lớp tài khoản + IP); captcha Turnstile | T01, T03 | ✅ (⏳ captcha) |
| S11 | Medium | Phiên cũ sống lại; không có đổi mật khẩu; phiên staff 7 ngày | ADR-003 viết lại (destroy session cũ + tombstone, `logged_out`, bỏ nhánh nhận nuôi, huỷ phiên khi đổi mật khẩu/khoá, validate device id); ADR-004 §2.2 (idle 120', tối đa 12h); US-015 | T05, T27, T28 | ✅ (⏳ US-015 story) |
| S12 | Medium | 9 điểm IPN/đối soát | ADR-001 §2 (bảng mã, accessKey từ config, partnerCode/requestId, amount nghiêm ngặt, orderInfo không PII, body 16 KB, 120/phút, TLS, log), §3 (needs_review khi vượt max_uses), §7 (khoá `/pay`, unique → attempt hiện có) | T17–T20 | ✅ |
| S13 | Medium | Rò nội dung trả phí | ADR-002 §4 (TTL 15', ràng IP, throttle 30/phút/user, log + cảnh báo, link ngoài chỉ preview dựng từ ID, nocookie/dnt, iframe sandbox, outline không URL); data-model `lessons` | T09, T13, FW4 | ✅ (⏳ PO; watermark ⏸) |
| S14 | Medium | Export/danh sách lộ PII, CSV injection | api-contract §2.5, §4 (che PII, include_contact chỉ admin + lý do, không xuất phụ huynh, 10 lần/ngày, audit, ký tự `= + - @ \t \r`, tên file, signed URL đúng host); data-model `exports` | T24, T25 | ✅ (⏳ PO) |
| S15 | Medium | Thiếu audit log, MFA staff | data-model `audit_logs`; ADR-004 §3–4 (AuditLogger, MFA email admin/QLT, staff:create mật khẩu ngẫu nhiên, không seed admin prod); US-016 | T02, T28, T33 | ✅ (⏳ MFA, US-016) |
| S16 | Medium | SSR cache lẫn dữ liệu | ADR-004 §2.5 (no-store + Vary, publicFetch/authFetch, force-dynamic, không CDN cache API trừ public); api-contract §2.1 tách `viewer-state` | T01, T10, FE0 | ✅ |
| S17 | Medium | Mass assignment | data-model §0 (cột cấm fillable); ADR-004 §4 (`$fillable`, `validated()`, shouldBeStrict); test kiến trúc | T02, T03, T08 | ✅ |
| S18 | Low | Dò mã, giữ chỗ bằng đơn pending, mã 100% | api-contract §1.7 (`COUPON_INVALID` gộp), §1.6 (30 sai/ngày); ADR-001 §6 (`coupon_hold_until` ≤ 30'); data-model CHECK mã 100% | T15, T16, T18, T20 | ✅ (⏳ PO) |
| S19 | Low | Middleware không nhất quán | api-contract §1.3 (nhóm chuẩn, OTP trong nhóm student); ADR-004 §4; test duyệt route | T02 | ✅ |
| S20 | Low | Dò tài khoản, thứ tự ACCOUNT_LOCKED, chiếm email | `ACCOUNT_LOCKED` chỉ khi mật khẩu đúng (api-contract §2.2); captcha; `users:purge-unverified` 7 ngày (data-model §7); dò qua AC2 được chấp nhận có captcha | T03, T30 | ✅ (⏳ 7 ngày) |
| S21 | Low | PII/OTP trong queue, failed_jobs, log | ShouldBeEncrypted cho mail PII; `queue:prune-failed` 7 ngày; `dontFlash`; không log body `/auth/*` (ADR-004 §4, data-model §3.7) | T01, T04, T30 | ✅ |
| S22 | Low | Checklist production | ADR-004 §6 (Nginx mặc định, header, Redis tách DB + mật khẩu, secret, `/config/public` allowlist, IIS lưu ý) | T01, T31 | ✅ (⏳ Nginx vs IIS) |
| S23 | Low | Next.js open redirect, pay_url, JSON-LD, NEXT_PUBLIC | ADR-004 §2.6; FE0 helpers `safeRedirect`, `isAllowedPayUrl`, `jsonLd`; Next ≥ bản vá CVE-2025-29927; `pnpm audit` | FE0, FW3 | ✅ |
| S24 | Low | LIKE wildcard; token guard | Helper `Like::contains` (data-model §3.2, api-contract §2.1/§2.5); không phát hành personal access token (data-model §3.1) | T10, T24 | ✅ |
| I1 | Info | Không DRM | Chấp nhận; giảm thiểu bằng S13 | — | ⏸ chấp nhận |
| I2 | Info | Gian lận 90% | Kẹp delta + `lockForUpdate` (ADR-002 §5) | T13 | ⏸ chấp nhận |
| I3 | Info | Lộ đáp án | Resource lượt đang làm không có `is_correct`/`explanation` + test | T22 | ✅ |

## DBA (`docs/db/design-review.md` §3)

| # | Điểm | Xử lý ở đâu | Task | Trạng thái |
|---|---|---|---|---|
| 1 | Cấu hình `READ COMMITTED` sai khoá | data-model §6: `PDO::MYSQL_ATTR_INIT_COMMAND` mức session; test `@@transaction_isolation`; docker `--transaction-isolation` | T01 | ✅ |
| 2 | Mâu thuẫn thứ tự khoá coupons/enrollments | Thống nhất `carts → orders → payment_attempts → enrollments → coupons/coupon_usages → courses` (data-model §4 = ADR-001 §3/§4); log critical khi hết retry | T18–T20 | ✅ |
| 3 | Thiếu job dọn `payment_webhook_events` | `payments:purge-webhook-events` (lô 5.000); data-model §7; README §4 | T30 | ✅ |
| 4 | Index `(gateway, received_at)` kém | Đổi thành IX(`received_at`) | T19 | ✅ |
| 5 | CHECK `discount_value`; đối soát `used_count` | `chk_coupons_percent_range` (+ `chk_coupons_full_discount_limited`); `counters:recount` gồm `coupons.used_count` | T15 | ✅ |
| 6 | CHECK `JSON_LENGTH(question_ids)` | `chk_quiz_attempts_question_count` 1–200 (khớp `quiz.max_questions`, ⏳ PO) | T22 | ✅ |
| 7 | Chốt collation | `utf8mb4_0900_ai_ci` + test tên có/không dấu ở T01 | T01 | ✅ (⏳ PO biết hệ quả) |
| 8 | `users.email` 191 → 254 | Đổi `varchar(254)` | T02 | ✅ |
| 9 | Phân trang đơn quản trị | `cursorPaginate` + `COUNT` riêng, khoảng ngày bắt buộc ≤ 366 (data-model §3.5, api-contract §1.5); Designer cập nhật US-010 | T24, FA8 | ✅ (⏳ UX PO/Designer) |
| 10 | Mốc theo dõi bảng tổng hợp tiến độ | p95 > 300 ms trong 7 ngày hoặc > 20M dòng (data-model §2); log thời gian xử lý ở T23 | T23 | ✅ |

Góp ý thêm của DBA đã áp dụng: `lockForUpdate` khi heartbeat (ADR-002 §5); cảnh báo hot row + load test (data-model §4); đặt tên constraint `chk_*`; checklist §5 đưa vào T01/T07/T18/T24; giữ `innodb_flush_log_at_trx_commit=1`.
