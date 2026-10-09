# Bảng theo dõi VitaminVui

Cập nhật: 2026-10-06. Phiên tiếp theo (kể cả Claude Code on the web) đọc file này trước tiên.

## Tình trạng 2026-10-08

### 2026-10-09 chiều: US-022 XONG (thanh toán "Liên hệ Quản trị viên") — 8 commit chưa push
- `7ddf063` T38, `bb5c394` T38-1, `c353b88` Design, `ff3a8c7` T24-V1, `333e910` FW3, `fd7f31b` T39, `37a0a65` FA8, `c4ce8cc` sửa tên trường tiếng Việt. Mục TẠM DỪNG bên dưới đã xử lý.
- QA FA8 chạy trọn luồng HS đặt đơn → QTV duyệt → HS vào học + thư; huỷ có lý do; nhả mã; duyệt muộn.
- Dev server Docker macOS: route mới có thể 404 tới khi `touch page.tsx` (không cần restart).
- Seed `seed-e2e-fa8.sh --clean` lỗi FK `coupons.created_by` nếu còn mã do admin e2e tạo → xoá mã trước.
- Production: chỉ bật `FEATURE_MANUAL_PAYMENT` khi đã deploy T39 + FA8 (đã đủ). Chờ PO: quyền hoàn tiền QLT (S3), thời hạn lưu `refund_note`/`order_notes`/`payment_reference`/audit view_pii.

### TẠM DỪNG 2026-10-09 ~09:40 (PO yêu cầu) — chạy tiếp từ đây
- Commit chưa push: `7ddf063` T38, `bb5c394` T38-1, `c353b88` Design US-022. Push khi PO bảo.
- **T24-V1 (admin đơn hàng phần đọc + hoàn tiền):** review APPROVE, security PASS có điều kiện (S1, S2 đã sửa), QA PASS. Toàn bộ file T24-V1 ĐÃ NẰM TRONG GIT INDEX (30 file, kèm `docs/bao-cao-task.md`). CI sạch bị dừng giữa chừng (Pint/PHPStan đã sạch) → chạy lại CI backend từ index (`git write-tree`) rồi commit.
- **T39 (duyệt/duyệt muộn/huỷ/ghi chú, thư):** laravel-dev bị DỪNG giữa chừng — file đang sửa dở trong working tree (backend, `docs/review/T39.md` nếu có). Việc tiếp: giao laravel-dev kiểm `git diff` (working tree so với index) + `tests/Feature/T39`, hoàn tất theo "Xong khi", chạy test → review → security → QA. KHÔNG `git add` T39 trước khi commit T24-V1.
- **FW3 (giỏ/thanh toán/đơn của tôi web):** dev xong, review APPROVE; dev bị DỪNG giữa lúc sửa R1 (AuthProvider.refresh giữ state user khi lỗi tạm), R2 (test AC28 trùng), R3 (mailto regex), R4 (role=alert), R5 (test zalo_url sai). Việc tiếp: giao nextjs-dev kiểm phần đã sửa, làm nốt, vitest + eslint + tsc + e2e phần giỏ → QA → CI frontend → commit.
- Sau đó: FA8 (quản trị đơn hàng, cần T24-V1 + T39). Chỉ bật `FEATURE_MANUAL_PAYMENT` ở production sau T39 (security T38 S2).
- Chờ PO: quyền hoàn tiền cho quản lý trang (S3); cho tìm theo SĐT/email (đã có audit + 30/phút, 300/ngày); thời hạn lưu `refund_note`, `order_notes.body`, audit `view_pii` (đề xuất 24 tháng).
- Máy: tiến trình macOS (`knowledgeconstructiond`, `trustd`) đẩy load 60–160 → race test timeout giả; chỉ chạy race khi load < 20.


### 2026-10-08 tối: FW7 commit `0487dc0` (chưa push). PO yêu cầu mới: thanh toán "Liên hệ Quản trị viên" (MoMo ẩn), QTV liên hệ rồi duyệt đơn
- Story `docs/stories/US-022-...md` (BA, 16 câu hỏi PO, đang dùng mặc định), ADR-007 (Proposed), `docs/tech/US-022.md`, api-contract §2.3.1/§2.5.1, task T38 → (T24-V1 ∥ FW3) → T39 → FA8 trong tasks.md.
- Đang chạy: DBA review mô hình dữ liệu (`docs/review/T38-dba.md`), nextjs-designer dựng màn US-022. Sau DBA: laravel-dev làm T38 [SEC][DBA].
- 2026-10-09 sáng: commit (chưa push) `7ddf063` T38, `bb5c394` T38-1 (xoá customer_note sau 90 ngày), `c353b88` Design US-022. DB dev đã migrate 3 migration T38; `backend/.env` local bật `FEATURE_MANUAL_PAYMENT` + kênh liên hệ thật. Đang chạy: T24-V1 (laravel-dev, admin đơn hàng phần đọc), FW3 (nextjs-dev, giỏ/thanh toán/đơn của tôi, seed riêng `fw3-*`). Sau: T39 → FA8.
- PO 2026-10-09 đã quyết (xem story mục "Quyết định PO 2026-10-09"): SĐT/Zalo 0915592224, 8h–17h; không STK/QR; 72h; chấp nhận rủi ro duyệt không thu tiền; không giới hạn giữ chỗ mã; chấp nhận spam thư QTV; link thư → "Đơn đã gửi". FW7 đã push (`0487dc0`).
- (cũ) Chờ PO: Q1 kênh liên hệ thật (SĐT/Zalo/email/giờ), Q2 có hiện STK/QR không (mặc định không), Q3 hạn chờ (mặc định 72h), duyệt ADR-007 + chấp nhận rủi ro QTV duyệt không thu tiền (chỉ giảm nhẹ bằng audit/`confirmed_by`).

### 2026-10-08 ~20:30: PO cho chạy tiếp — T34 đã sửa xong vòng review+security, QA PASS (docs/qa/T34.md), đang CI → commit → push (T29, FW1, T34). SLN8 (nút "Tiếp tục học" trang chi tiết khóa) đang dev. Danh sách việc theo đội: docs/bao-cao-task.md.

### (Đã xử lý) TẠM DỪNG 2026-10-08 ~19:00
- Commit chưa push: `b24db58` (T29), `40601c1` (FW1-ADR006). Push khi PO bảo.
- **T34 (đang dở, chưa commit):** dev xong bản đầu (101 test), review APPROVE (R1–R4), security PASS có điều kiện (docs/security/T34.md: S1 Medium bắt buộc, S2 chờ PO, S3/S4 Low). Agent dev bị DỪNG giữa vòng sửa. Đã thấy trong code: `LockedUser::lockActive` ở ContactService/ConsentService/OtpService, `afterCommit` xoá ảnh ở TeacherProfileService::erase, Job `$timeout=60` + `failed()`. CHƯA chắc: PasswordService + ParentContactService dùng `lockActive`, S4 (xoá file `users.avatar_path` sau commit), R2 (pha B tự phát lại job khi bỏ qua đơn), sửa `context.*`→`errors.*` trong tasks.md, mục "Dev sửa theo review + security" trong docs/review/T34.md. Việc tiếp: giao laravel-dev kiểm/hoàn tất các điểm trên + chạy Pint/PHPStan/test (T34, T29, T36, T03, T04, T18, T27) → laravel-qa (gợi ý QA cuối docs/review/T34.md và docs/security/T34.md) → CI sạch → commit.
- **Chờ PO:** S2 — IP/UA trong `audit_logs` của tài khoản đã xoá: đề xuất giữ, tự xoá sau 24 tháng (`audit:purge` 03:40 đã có trong OperationsServiceProvider; `queue:prune-failed` cũng đã có). Pháp chế: ẩn danh hay giả danh, file xuất có liên hệ phụ huynh đầy đủ.
- **Sau T34:** FW7 (trang quyền dữ liệu cá nhân + `/phu-huynh/huy-nhan-thong-bao`). Chỉ bật `FEATURE_PARENT_NOTICES` ở production khi FW7 đã lên.
- **Thay đổi chưa commit ngoài T34:** máy chủ ảnh tĩnh local `localhost:8080` (`infra/nginx/conf.d/vitaminvui.conf` + port `127.0.0.1:8080:8080` trong `infra/docker-compose.yml`) — đang chạy. Đề xuất chờ PO: đồng bộ trạng thái video mỗi phút ở local (Bunny webhook không tới được localhost; hiện `videos:check-stuck` 15 phút + ngưỡng 15 phút).
- Môi trường dev: `VIDEO_PROVIDER=bunny` (php/queue/scheduler đã recreate), 5 asset Bunny ready (khóa 55 + khóa "Giang test" 285); T29 đã migrate DB dev (bảng sao lưu `users_parent_consent_bak_t29`); `.env` có `PRIVACY_POLICY_VERSION=2026-10-tam`, `PRIVACY_NOTICE_TOKEN_KEY` riêng. Tài khoản demo: admin@/teacher@/student@vitaminvui.test mật khẩu `Demo-VitaminVui-2026` (MFA qua Mailpit :8025); fw4-hs-*/fw5-hs-* `matkhau-123`.
- QUY TẮC (2026-10-08, sự cố SLN8): agent KHÔNG chạy `--clean`/`--reset` của các seed đang làm dữ liệu demo cho PO (`seed-e2e-learn.sh` → khóa video demo + `fw4-*`, `seed-e2e-quiz.sh` web → `fw5-*`); e2e mới phải dùng tiền tố riêng. Demo hiện tại: khóa video demo 287 (`e2e-fw4-hoc-video`), `fw4-hs-none` đã duyệt vào 287 và 285 (Giang test).
- Sau mỗi lần recreate container php: nếu API 502 thì `docker compose restart nginx` (nginx giữ IP php cũ).

- **PO 2026-10-08 (T29/T34, thay đổi US-017):** KHÔNG cần phụ huynh đồng ý — học sinh mua/học không phải chờ phụ huynh; hệ thống chỉ GỬI THÔNG BÁO cho phụ huynh (nếu có email phụ huynh). Đăng ký KHÔNG bắt buộc nhập thông tin phụ huynh. Không có luồng rút lại đồng ý của phụ huynh. Học sinh tải dữ liệu cá nhân tối đa 2 lần/ngày. Làm T29/T34 với nội dung chính sách TẠM (thay khi có bản pháp chế). Giữ nút "Bật lại mã" (FA7). Mật khẩu khởi tạo staff: Admin tự gửi (FA10).
- FW8 + FW9, FA6 (+91f244b), FA11-1 (57c56b7): đã commit; 91f244b, 57c56b7 chưa push.
- FW5 (web làm quiz, katex 0.19.0) + SLN6 (nộp từ `expires_at` là tự nộp): review APPROVE, QA PASS (docs/qa/FW5.md); nợ Low: e2e hết giờ chưa assert nhãn "tự động nộp", chưa thử Safari/Firefox, CORS `max_age`.
- SLN7 (PO 2026-10-08): API `PUT .../quizzes/{quiz}/questions/order` đổi thứ tự câu + xoá câu đánh lại position; review APPROVE, QA PASS (docs/qa/SLN7.md). FA5 cần thêm UI kéo thả sau khi commit.
- FA5 (soạn quiz quản trị + kéo thả sắp xếp câu dùng SLN7, hook `useUnsavedChangesGuard` dùng chung): review 2 vòng APPROVE, QA 2 vòng PASS (docs/qa/FA5.md). Backlog: đưa 3 file KaTeX trùng (admin/web) vào `packages/ui`; `has_attempts` cho câu hỏi; cờ `quiz_time_limit_enabled` ở `/admin/auth/me`.
- Backlog từ FW6: `best_attempt_id` trong `quizzes[]` của `/me/courses/{course}/progress` (nút đang là "Xem kết quả lượt gần nhất"); `packages/ui` `DataTable` cần khung cuộn `relative` để `sr-only` không gây tràn ngang 375px.
- FA5 đã commit (f5a978c). FW6 (Khóa học của tôi + tiến độ): review APPROVE, QA PASS (docs/qa/FW6.md), đã sửa BUG-1/NIT-1/NIT-2.
- FA7 (mã giảm giá): review APPROVE, QA PASS (docs/qa/FA7.md). Chờ PO: giữ/ẩn nút "Bật lại mã" (đang giữ). Backlog: API trả giá khóa rẻ nhất (FE đang quét ≤200 khóa).
- FA10 (tài khoản staff): review APPROVE (không có điểm bảo mật Critical/High), QA PASS (docs/qa/FA10.md). Chờ PO: kênh gửi mật khẩu khởi tạo (đang: Admin tự gửi). Backlog: `released_course_ids` trả kèm tên khóa.
- T29 (ADR-006: bỏ đồng ý phụ huynh, chỉ thông báo; thông tin phụ huynh tuỳ chọn; huỷ nhận + danh sách chặn HMAC): review APPROVE (sau BLOCKER R1 chèn link vào thư), DBA PASS, security PASS có điều kiện (S1–S3 đã sửa; S4/S6 backlog), QA PASS. Cần pháp chế xác nhận việc gửi thông tin học sinh tới địa chỉ phụ huynh chưa xác minh. Deploy: thêm cột → code → backfill + kiểm/bù (docs/review/T29.md mục DBA). Production bắt buộc `PRIVACY_NOTICE_TOKEN_KEY` riêng ≥32 byte, không xoay.
- Video dev (2026-10-08, PO yêu cầu): `VIDEO_PROVIDER=bunny`, 2 asset đã `videos:migrate-provider` sang Bunny (nguồn VideoLab còn giữ); web `NEXT_PUBLIC_VIDEO_HOSTS` có `https://cdn.vitaminvui.asia`, admin upload `https://video.bunnycdn.com`. Production vẫn `internal` tới khi xong C1–C9.
  - 2026-10-08 tối: PO báo "video sẵn sàng nhưng không xem được" → link Bunny bị 403 vì token ràng IP (`VIDEO_BIND_IP` mặc định true) mà Laravel trong Docker chỉ thấy IP `172.18.0.1`. Local đặt `VIDEO_BIND_IP=false` trong `backend/.env` (đã recreate php/queue/scheduler + restart nginx). Production giữ true, bắt buộc `TRUSTED_PROXIES` đúng (đã có trong checklist); kiểm thêm ở staging: trình duyệt tới CDN bằng IPv6 còn API thấy IPv4 thì cũng 403. Đã chuyển nốt asset 8, 9 (khóa demo 287) sang Bunny; 4 bài 247, 248, 253, 254 phát 200.
- Đã xong toàn bộ FW1–FW6, FW8, FW9, FA1–FA7, FA10, FA11. Còn: FW3/FA8/FA9 (thanh toán, đơn hàng, xuất file — phụ thuộc bật thanh toán), FW7 (xác nhận phụ huynh, quyền dữ liệu cá nhân).
- SỰ CỐ 2026-10-08 ~09:48: QA SLN7 chạy `php artisan migrate:fresh --env=testing` trong container php — không có `.env.testing` nên xoá trắng DB dev `vitaminvui` (không có bản sao lưu). Đã chạy lại `db:seed` + seed-e2e catalog/home/learn/quiz và duyệt lại ghi danh fw4-hs-none (khóa 55). QUY TẮC: agent không bao giờ chạy `migrate:fresh|refresh|reset`, `db:wipe`, `db:seed` trong container php; DB test chỉ qua `pest -c phpunit.local-{e,g,h}.xml` (RefreshDatabase tự migrate).

## Tình trạng 2026-10-07 tối (PO tạm dừng)

- FA11 (d56d9a2), FW4 (6ac9f14): đã push.
- FW8 + FW9 (trang chủ thật): dev xong, review APPROVE, dev đã sửa R1/R2/R5/R6; chưa commit (working tree apps/web). Việc tiếp: QA (dừng giữa chừng, chạy lại từ đầu; đã `seed-e2e-home.sh --clean`) → CI → commit.
- PO 2026-10-07: GIỮ chữ phụ 14px trên CourseCard/TeacherCard (AC10 ≥16px chỉ áp nội dung chính). Bunny CDN đã có DNS, phát thật ĐẠT (docs/qa/T37.md); PO cần đặt Allowed domains trước production. FA11 R2 → FA11-1.
- Task tiếp sau FW8: FW5/FA5 (quiz), FW6, FA6, FA7, FA10.

## Tình trạng 2026-10-07

- Đã xong và đã push (tới `fbaefae`): Sửa lỗi nhỏ 4, T37 Bunny, trần video 1 GB, design v2 (nền ô ly, màn mới, poster tạm), FW-V2 + FW2, FA-V2.
- Đang làm: FA3 (khóa học quản trị v2 + 3 Minor QA FA-V2), FW1 phần còn lại (xác thực theo v2).
- Tiếp theo: FA4 → FW4 → FW8/FW9/FA11 → FW5, FW6, FA5, FA6, FA7, FA10. Báo cáo: `docs/bao-cao-task.md`.
- Bunny: thư viện 772566 là STAGING; khoá trong `backend/.env` (không commit); chờ PO chép lại CDN Hostname để thử phát; production giữ `VIDEO_PROVIDER=internal` tới khi xong checklist C1–C9 (`docs/qa/T37.md`).
- Môi trường: Docker ~7,75 GB RAM — tối đa 2–3 agent chạy lệnh nặng; MySQL local đã `SET PERSIST log_bin_trust_function_creators=1`; web dev mở `http://api.localhost:3000`.

## Trạng thái task

Danh sách task, phụ thuộc và định nghĩa "xong": `docs/architecture/tasks.md`. Báo cáo tổng hợp đã xong / đang làm / chưa làm: `docs/bao-cao-task.md`.

| Task | Tên | Bước hiện tại | Trạng thái | Chặn bởi | Cập nhật |
|---|---|---|---|---|---|
| T01 | Khởi tạo backend Laravel 13 + Docker | Xong | ✅ Review, Security (PASS có điều kiện), QA (PASS) | — | 2026-09-28 |
| T02 | Users, audit_logs, vai trò, staff:* | Xong | ✅ như T01 | — | 2026-09-28 |
| FE0 | Khởi tạo frontend Next.js 16 | Xong | ✅ Review; 64 unit + e2e backend thật | — | 2026-09-25 |
| T03 | Đăng ký/đăng nhập học sinh | Xong | ✅ Review, QA; nợ bảo mật ở backlog-v2 | — | 2026-10-05 |
| FW1 (phần 1 + OTP) | Đăng ký, đăng nhập, đăng xuất, OTP (apps/web) | Xong một phần | ✅ Review, QA; còn màn quên/đổi mật khẩu (API T27 đã có) | — | 2026-10-05 |
| T04 | OTP + GET /auth/me | Xong | ✅ Review, QA (316 test, e2e OTP 11/11) | — | 2026-10-05 |
| T05 | Một phiên học sinh | Xong | ✅ Review, QA (e2e 2 thiết bị 7/7) | — | 2026-10-05 |
| T06 | Chuyên đề CRUD (admin-api) | Xong | ✅ Review, QA (59 test T06+T07, race thật); Minor: lỗi per_page tiếng Anh | — | 2026-10-05 |
| T07 | Schema nội dung + ghi danh | Xong | ✅ Review (kiêm DBA), QA; FK enrollments.order_id làm ở T18 | — | 2026-10-05 |
| T17 | Thanh toán: abstraction + MoMo | Xong (chưa kiểm sandbox) | ✅ Review, QA (141 test); PHẢI kiểm sandbox MoMo trước T20 (backlog T17-1) | — | 2026-10-05 |
| T27 | Quên/đổi mật khẩu học sinh | Xong | ✅ Review, QA (race 8 tiến trình); đã sửa timing dummy hash (ảnh hưởng cả login T03/T28) | — | 2026-10-05 |
| T28 | Đăng nhập quản trị | Xong | ✅ Review, QA (e2e admin thật 15 pass); Minor BUG-1 (csrf 419 sau khi phiên bị huỷ), BUG-2 (thiếu Accept JSON → 500) | — | 2026-10-05 |
| FA1 | Layout quản trị, đăng nhập, MFA, đổi mật khẩu lần đầu | Xong | ✅ Review, QA cùng T28; Minor N1 (từ ngữ MFA sai mã) | — | 2026-10-05 |
| T08 | Quản trị khóa học | Xong | ✅ Review, QA (upload: không lỗ thực thi/XSS); Minor: lỗi validate tiếng Anh (chung toàn dự án) | — | 2026-10-05 |
| T10 | Danh mục công khai + chi tiết | Xong | ✅ Review, QA (320 khóa thật, 0,05–0,09 s) | — | 2026-10-05 |
| T14 | EnrollmentService (xin học, duyệt, grantPurchase) | Xong | ✅ Review, QA (race xoá khóa ↔ xin học đã sửa); chưa có email báo duyệt/từ chối (AC2/AC3) | — | 2026-10-05 |
| FA2 | Màn chuyên đề admin | Xong | ✅ Review, QA (khoá giữa phiên thật, hai tab, 31 chuyên đề); Minor: ẩn/hiện gặp 404 không tải lại, overlay khoá còn lộ khung phía sau | — | 2026-10-05 |
| T09 | Chương/bài (CRUD, sắp xếp, link ngoài) | Xong | ✅ Review (M1 khóa published không xoá được bài cuối), QA (69 test, có race) | — | 2026-10-05 |
| T15 | Mã giảm giá quản trị | Xong | ✅ Review (thêm CHECK DB), QA (48 test, race tạo trùng mã). T16/T18 dùng `Coupon::state()`, `normalizeCode()`; `coupon_usages` do T18 tạo | — | 2026-10-05 |
| T11 | Contract video (upload TUS, webhook, đồng bộ, dọn mồ côi) | Xong | ✅ Review (R1 gọi provider ngoài transaction), QA (35 test, race hạn mức). Chưa có adapter thật: VideoLab ở T12, Bunny chờ tài khoản | — | 2026-10-05 |
| T21 | Soạn quiz (admin) | Xong | ✅ Review, QA (32 test, race). `x<y` bị chặn, dùng `\lt` (ghi vào hướng dẫn FA5) | — | 2026-10-05 |
| Sửa lỗi nhỏ 1 | validation tiếng Việt, T28 BUG-1/2, OTP_INVALID/OTP_EXPIRED, nới OTP e2e, email duyệt đăng ký | Xong | ✅ Review, QA (456 test) | — | 2026-10-05 |
| T16 | Giỏ hàng | Xong | ✅ Review (M1 theo US-013 BR6), QA (59 test, race). T18 phải xử lý đơn 0đ | — | 2026-10-05 |
| T26 | Vận hành queue/scheduler + throttle catalog SSR | Xong | ✅ Review, QA (commit 1ed4c44). Kiểm Nginx xoá header nội bộ trên staging: T31 | — | 2026-10-06 |
| T33 | Tài khoản staff + nhật ký thao tác | Xong | ✅ Review, QA (commit 49d867e). Race test cần chạy lại khi máy rảnh (sửa assert `others`) | — | 2026-10-06 |
| T13 | Học & tiến độ | Xong | ✅ Review, QA (commit b8a5ddb, sửa PHPStan + deadlock heartbeat↔revoke ở 7c72589) | — | 2026-10-06 |
| T12 | VideoLab | Xong | ✅ Review, QA e2e (upload TUS → transcode → webhook → HLS, sandbox 5/5), sửa BUG-1 xoá giữa transcode. Commit 78f8986 | — | 2026-10-06 |
| T31 | Checklist production & mẫu cấu hình | Xong (chờ giá trị thật) | ✅ Review 2 vòng, QA (Nginx dựng thật, grants.sql). Commit a0ebac1. Còn điền tên miền/SMTP/Turnstile/IP khi dựng staging | — | 2026-10-06 |
| Sửa lỗi nhỏ 2 | T03 M2/M3 (bộ đếm đăng nhập nguyên tử), T27-5 (reset không lộ tài khoản), timeout race 180s | Xong | ✅ Review (R1 khoá oan NAT), QA (race 15 tiến trình 12/12 xanh). Commit 0701cff | — | 2026-10-06 |
| T18 | Checkout | Xong | ✅ Review, QA (commit 297a527 + 744c8be Pint). CI worktree sạch: test + race xanh | — | 2026-10-06 |
| Khoá thanh toán + T30 (phần lẻ) | Cờ `FEATURE_PAID_CHECKOUT` (tắt, 503 PAYMENT_DISABLED), `audit:purge` 24 tháng, `users:purge-unverified` 7 ngày | Xong | ✅ Review (M1 khoá ứng viên, M2 cô lập user lỗi), QA (60 test + 5 race). Commit edca8ad | — | 2026-10-06 |
| T23 | Khóa học của tôi + tiến độ | Xong | ✅ Review APPROVE (R1 quiz xoá mềm, R2 test quyền), QA (27 test T23, IDOR). Commit 9ad3765 | — | 2026-10-06 |
| T22 | Làm quiz | Xong | ✅ Review, QA (commit 7919cab) | — | 2026-10-06 |
| T36 | Hồ sơ giáo viên công khai (US-019/US-020, ADR-005) | Xong | ✅ Review APPROVE, Security PASS (đã sửa hết), QA PASS (BUG-1 cờ emoji đã sửa). Commit 270289a. Frontend FW8/FW9/FA11 chờ PO duyệt design v2 | — | 2026-10-06 |

## Việc tiếp theo (theo thứ tự)

1. **Frontend TẠM DỪNG (PO 2026-10-05) chờ design mới.** Code dở chưa commit, giữ nguyên trong working tree: FW2 (dev xong, e2e 24/24, chưa review; load test cache ấm chưa đạt) và FA3 (dev dở). Khi có design mới: rà lại FW2/FA3 theo design rồi mới review/QA.
2. **Backend làm xong toàn bộ API trước (PO 2026-10-05)**, sau đó PO gửi design cho frontend. Chạy tối đa 4 dev backend song song, mỗi dev một DB test riêng `backend/phpunit.local-a|b|c|d.xml`. Thứ tự:
   - **Backend MVP đã xong toàn bộ** (2026-10-06). Còn: V2 (thanh toán, pháp lý) và điền giá trị thật cho T31 khi dựng staging (xem `docs/ops/production-checklist.md` mục cuối).
   - Production cần: user DB có quyền DELETE trên `audit_logs` (cho `audit:purge`); pháp chế (V2) xác nhận giữ audit 24 tháng và xoá `consents` khi xoá tài khoản chưa xác thực. T20 (V2): `/orders/{code}/pay` phải kiểm cờ `paid_checkout`.
3. Gom sửa lỗi nhỏ (một task riêng): file `lang/vi/validation.php` cho toàn dự án; T28 BUG-1 (csrf 419 sau khi phiên bị huỷ) và BUG-2 (thiếu Accept JSON → 500); mã lỗi OTP riêng `OTP_INVALID`/`OTP_EXPIRED`; nới hạn mức OTP cho môi trường e2e; email báo duyệt/từ chối đăng ký (US-012 AC2/AC3).
4. Frontend (sau khi có design mới): FW1 còn lại, FW2, FA3, FA4, FA6, FA7 và các màn còn lại.
5. **Trước T20**: kiểm phản hồi query của sandbox MoMo (cần tài khoản sandbox từ PO).
6. Mỗi task: dev → `laravel-reviewer` → sửa → `laravel-qa` → tự commit (không push).

## Chờ PO xác nhận (đã chọn mặc định để không chặn)

- T16: mã giảm tiền cố định lớn hơn tổng giá các khóa được áp dụng → giảm tối đa bằng tổng đó (theo US-013 BR6); riêng trường hợp đơn về 0đ thì mã phải có giới hạn lượt dùng và ngày hết hạn. Hết lượt dùng báo COUPON_EXPIRED. Giỏ hàng không yêu cầu xác thực OTP, chỉ chặn ở checkout.
- T21: một chương/bài gắn được nhiều quiz; chưa có API đổi thứ tự quiz/câu; xoá quiz không bị chặn dù đã có lượt làm; công thức dùng `\lt`, `\gt` thay cho `<`, `>` sát chữ.
- T09: xoá chương/bài chỉ bị chặn khi đã có tiến độ học (lesson_progress), không xét học sinh đã ghi danh; xoá bài/chương cuối của khóa đang xuất bản → 409 COURSE_LAST_LESSON. Link ngoài chỉ cho bài học thử, chỉ Vimeo công khai.
- T15: thêm thao tác bật lại mã; trần mã giảm tiền 100.000.000đ; phạm vi mã = khóa chọn ∪ khóa thuộc chuyên đề chọn; xoá chuyên đề thì mã tự thu hẹp; "giá khóa rẻ nhất" (ràng buộc mã 100%) tính trên mọi khóa đang bán.
- T14 R3: duyệt yêu cầu miễn phí khi khóa đã đổi sang có phí → 422 COURSE_NOT_FREE (mặc định).
- T08: xoá khóa bị chặn khi có đăng ký ở mọi trạng thái (kể cả bị từ chối); giáo viên không đổi được giá.
- T27: thông điệp khi thua race dùng mã reset; reset bằng đúng mật khẩu cũ có được không.
- FA2: giáo viên xem chuyên đề ở chế độ chỉ đọc (design ghi 403); bỏ xem trước slug trong form.

## Quy tắc làm việc đã thống nhất với PO

- **2026-10-06 · PO thêm agent `nextjs-designer`** vào đội: phụ trách design system và dựng trang/component Next.js + Tailwind bằng dữ liệu mẫu; `nextjs-dev` nối API vào giao diện đó. Quy trình frontend: `nextjs-designer` → `nextjs-dev` → `laravel-reviewer` → `laravel-qa`.
- **2026-10-06 · PO mở lại cổng `laravel-security`** sau khi backend MVP xong: audit theo 4 cụm (1 xác thực/phiên; 2 nội dung/upload/video/học tập; 3 thanh toán T17–T18; 4 T31 + cấu hình tổng thể), chạy 2 cụm một lúc. Critical/High báo PO ngay rồi giao dev sửa; Medium/Low ghi `docs/security/backlog-v2.md`. Song song: "Sửa lỗi nhỏ 3" sửa các mục Low còn mở (trừ thanh toán, pháp lý, mục đã hoãn V2).
- **2026-10-05 · Tạm dừng cổng `laravel-security`** (và việc sửa lỗi bảo mật không nghiêm trọng) để đẩy tiến độ; quy trình mỗi task tạm thời là dev → `laravel-reviewer` → `laravel-qa` → PO duyệt. Lỗi bảo mật đã biết được ghi ở `docs/security/backlog-v2.md`, review lại và sửa ở v2, bắt buộc trước go-live. Lỗi Critical/High vẫn phải báo PO ngay.

- Chỉ commit/push khi PO đồng ý. `main` chỉ chứa code đã qua review.
- **2026-10-05 · PO cho phép commit tự động (không push)** sau khi task qua dev → reviewer → QA PASS, mỗi task một commit; xong task nào thì commit rồi tự chạy task kế tiếp theo thứ tự "Việc tiếp theo", không cần hỏi lại. Chỉ dừng khi gặp quyết định của PO, lỗi Critical/High, hoặc lỗi môi trường không tự xử lý được. Push vẫn do PO tự làm.
- Dev chạy mọi thứ trong Docker ở máy local. Trên cloud, `scripts/cloud-setup.sh` cài trực tiếp (xem CLAUDE.md).
- Không cài package ngoài danh sách đã duyệt trong tasks.md (cổng G2) mà không hỏi PO.
- Không tự bịa field ngoài api-contract. Thấy thiếu hoặc mâu thuẫn thì dừng và hỏi Architect.

## Việc đã hoãn (có người phụ trách)

- **V2 — Thanh toán (PO quyết định 2026-10-06, chờ PO kết nối MoMo):** T19 IPN & fulfillment, T20 đối soát/`/pay`/đơn của tôi, T24 admin đơn hàng, T25 xuất file đơn, phần `payments:purge-webhook-events` của T30, FW3, FA8, FA9. Checkout có tính tiền bị khoá bằng cờ `FEATURE_PAID_CHECKOUT` (mặc định tắt); giỏ hàng, preview, đơn 0đ và đăng ký khóa miễn phí vẫn chạy. Code T18 giữ nguyên, bật cờ khi làm V2.

- Môi trường (2026-10-06): máy host 16 GB thiếu RAM (swap 5/6 GB, VM Docker 8 GB) → load 100–200, agent treo, race test timeout. Chạy tuần tự tối đa 1–2 việc test cùng lúc cho tới khi PO giảm tải máy.
- Nợ kỹ thuật test: các race test dùng tiến trình con có timeout 60s/tiến trình, fail khi máy quá tải (T16 CartRaceTest ở load ~240). Nâng timeout (vd 180s) và chạy nhóm `race` tách riêng trong CI.
- Nợ e2e frontend (QA FA3 2026-10-07, Minor): `khoa-hoc-real` không chạy lại được nếu không `seed-e2e-courses.sh --clean` rồi seed lại; `admin-real` cần đặt lại cờ `must_change_password`, `chuyen-de-qa-real` cần ≥ 25 chuyên đề `E2E CD *` và script locker/attacher ngoài. Gom vào seed script/README e2e.
- Nợ giao diện (QA FW1 2026-10-07, Minor): logo header web cao 39px ở 375px (< 44px vùng chạm); `Field` v2 chưa có `aria-live` cho lỗi (designer quyết, dùng chung với admin); FA3 R5 (Nổi bật #n ở danh sách), `beforeunload` chỉ theo dõi form chính.
- FW1 R10 (PO 2026-10-07): bước 2 quên mật khẩu che email (`a**@x.vn`) và SĐT (3 số cuối) — đã làm.
- FW4/FW5: hộp thoại mất phiên phải `pause()` player khi mở.
- PO 2026-10-08: quiz nộp lúc `now >= expires_at` tính là tự nộp (`auto_submitted=true`), giữ 30 giây ân hạn cho mạng chậm (review FW5 R1).
- Bài học 2026-10-08: agent build/`next start` vào `.next` dùng chung làm hỏng dev server web (500 postcss). Build/e2e production BẮT BUỘC dùng `NEXT_DIST_DIR=.next-…` hoặc bản copy.
- Backlog backend cho FA6 (review FA6 2026-10-07): route admin thu hồi đăng ký (`EnrollmentService::revoke` đã có), tham số `q` tìm học sinh, endpoint đếm theo trạng thái (badge menu), tab Đã duyệt/Từ chối sắp theo thời điểm xử lý mới nhất, lọc khoá giá 0 cho ô chọn khoá. Duyệt không có hộp xác nhận (theo design v2 §14.1).
- FA11 R2 (PO 2026-10-07: LÀM — task FA11-1, sau FA6): người đã đổi vai trò không còn đường UI tự rút đồng ý/xoá ảnh hồ sơ giáo viên (trùng L1 security T36); hiện admin/QLT xoá ảnh hộ. Seed `seed-e2e-profiles.sh` nên cố định email (Faker trùng email gây lỗi 1 lần).

- V2 (PO quyết định 2026-10-05): cách khoá đăng nhập chống khoá tài khoản người khác (M1 backlog), ngưỡng AC6; ý nghĩa đổi SĐT qua `/auth/contact`; trang `/dieu-khoan`, `/chinh-sach-du-lieu`; pháp chế (thời hạn lưu IP/UA trong `consents`, quy tắc tuổi/phụ huynh của T29).

- Quyền MySQL/trigger chặn sửa `audit_logs` (DBA, trước staging).
- Header bảo mật cho response do Nginx tự trả (N2), cấu hình log production (L5), giới hạn IP cho `/up`: T31.
- L2: route domain dạng tham số, khi có route như vậy.

## Chờ PO trả lời

1. Throttle `csrf` đang 30 lần/phút/IP. Có nâng lên 120 cho lớp học dùng chung NAT không? nâng lên 120 phút
2. Các mặc định an toàn ở `docs/architecture/README.md` §8 (MFA staff, che PII khi xuất file, TTL link HLS...). tạm thời bỏ qua nếu ko cần thiết
3. LB/CDN production có chuyển tiếp `X-Forwarded-Host` từ client không (chốt ở T31). chưa hiểu câu hỏi
4. Pháp chế: Luật BVDLCN 2025, ngưỡng tuổi cần phụ huynh đồng ý, thời hạn lưu log có IP, chuyển dữ liệu ra nước ngoài., ngưỡng tuổi cần phụ huynh đồng ý tạm thời bỏ qua, thời hạn lưu log có IP bạn tự quyết định,chuyển dữ liệu ra nước ngoài thì ko đc

## Nhật ký

- 2026-09-25 · US-001..018 · BA/Designer/Architect/Security/DBA · Xong tài liệu thiết kế
- 2026-09-25 · T01, T02, FE0 · Dev + Reviewer · Xong, đã sửa R1–R5
- 2026-09-28 · T01, T02 · Security + QA · Security FAIL (H1) → sửa → PASS có điều kiện; QA PASS; 114 test; commit 58fa34d
- 2026-09-28 · T03, FW1 · Dev · Tạm dừng trước khi code, bàn giao cho Claude Code on the web
- 2026-10-05 · T03, FW1 · Dev + Reviewer + Security + QA · Review APPROVE; Security PASS có điều kiện (hoãn v2); QA tìm BUG-1..5, đã sửa, PASS có điều kiện; host local đổi thành api.localhost:3000 và admin-api.localhost:3001; csrf throttle 120/phút
- 2026-10-05 · T04, FW1 (OTP) · Dev + Reviewer + QA · Review APPROVE; QA tìm BUG-1..4 (mã OTP rò vào log, Retry-After chưa expose CORS...), đã sửa, PASS
- 2026-10-05 · T05 · Dev + Reviewer · Một phiên học sinh (ADR-003): bind/tombstone/middleware thật; login bỏ `guest`, register `guest.student`; Review APPROVE, đã sửa R1 (không destroy trước bind), R2 (register 201 không phiên khi bind lỗi), R3, R4, R6; R5 ghi vào T27; chờ QA
- 2026-10-06 · T12, T31, Sửa lỗi nhỏ 2 · Dev + Reviewer + QA · PASS; commit 78f8986, a0ebac1, 0701cff (CI worktree sạch, gồm race)
- 2026-10-06 · T18, khoá thanh toán + T30, T23 · Dev + Reviewer + QA · PASS; commit 297a527, 744c8be, edca8ad, 9ad3765 (CI worktree sạch)
- 2026-10-05 · T21, sửa lỗi nhỏ 1, T16 · Dev + Reviewer + QA · PASS; commit cc14b43, 3366d60, b17c69b (mỗi commit chỉ phần của task)
- 2026-10-05 · T11 · Dev + Reviewer + QA · PASS; commit d9575f7 (chỉ phần T11, CI chạy trên worktree sạch: 982 test, 0 fail)
- 2026-10-05 · Frontend · PO · Tạm dừng đội frontend chờ design mới; dừng FA3 (dev dở), review FW2, e2e web
- 2026-10-05 · T09, T15 · Dev + Reviewer + QA · PASS; composer ci 945 test xanh; thêm DB test riêng cho từng dev (vitaminvui_testing_a|b|c)
- 2026-10-05 · Hạ tầng · Orchestrator · Push origin/main đến 0367da2; sửa frontend/scripts/pnpm.sh và playwright.sh chạy được trên macOS
- 2026-10-05 · T06, T07, T17, T27, T28, FA1 · Dev + Reviewer + QA (chạy song song) · tất cả PASS; composer ci 667 test xanh; queue worker thêm restart: unless-stopped
- 2026-10-05 · T08, T10, T14 · Dev + Reviewer + QA · PASS; sửa race xoá khóa ↔ đăng ký; composer ci 828 test xanh
