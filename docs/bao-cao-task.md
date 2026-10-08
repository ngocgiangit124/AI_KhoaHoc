# Báo cáo task VitaminVui

Cập nhật: 2026-10-08 tối. Nguồn định nghĩa task: `docs/architecture/tasks.md`. Trạng thái chi tiết và quy tắc làm việc: `docs/board.md`.
Ngày công là ước lượng của tài liệu, chưa tính thời gian chờ PO duyệt và sửa sau review/QA.

## Tóm tắt

| Nhóm | Đã xong | Đang làm | Chưa làm | Chờ bật thanh toán (V2) |
|---|---|---|---|---|
| Backend | T01–T18, T21–T23, T26–T28, T29, T31, T33, T34, T36, T37, T37-1, T30 (phần không thanh toán), Sửa lỗi nhỏ 1–7, Bảo mật cụm 1–4 | — | — | T19, T20, T24, T25, phần thanh toán của T30 |
| Frontend web (học sinh) | FE0, FW1 (+ ADR-006), FW2, FW4, FW5, FW6, FW8, FW9, FW-V2 | SLN8 (nút "Tiếp tục học" ở trang khóa học) | FW7 | FW3 |
| Frontend quản trị | FA1–FA7, FA10, FA11, FA11-1, FA-V2 | — | — | FA8, FA9 |

- Đã push lên `origin/main` tới `9342c02`. Commit chưa push: `b24db58` (T29), `40601c1` (FW1-ADR006); T34 sẽ commit sau QA, rồi push cả ba (PO đã đồng ý).
- Thanh toán đang khoá (`FEATURE_PAID_CHECKOUT=false`, chờ MoMo): khóa có phí hiện giá + "Sắp mở bán".
- Video: môi trường dev đã chuyển hẳn sang Bunny (`VIDEO_PROVIDER=bunny`). Production vẫn dùng VideoLab (`internal`) tới khi xong checklist staging C1–C9 (`docs/qa/T37.md`).
- PO 2026-10-08 (ADR-006): bỏ yêu cầu phụ huynh đồng ý; chỉ gửi thư thông báo cho phụ huynh; thông tin phụ huynh tuỳ chọn; xuất dữ liệu cá nhân tối đa 2 lần/ngày.

## 1. Đã xong trong ngày 2026-10-08

| Task | Nội dung | Commit |
|---|---|---|
| FA6 | Ghi rõ chỉ học sinh đã xác thực email nhận email kết quả | `91f244b` |
| FA11-1 | Người đã đổi vai trò tự rút đồng ý/xoá ảnh hồ sơ giáo viên | `57c56b7` |
| FW5 + SLN6 | Làm bài trắc nghiệm trên web (KaTeX, đồng hồ, tự lưu); nộp từ `expires_at` tính là tự nộp | `10f47d2` |
| SLN7 | API đổi thứ tự câu hỏi quiz; xoá câu đánh lại thứ tự | `fef30d3` |
| FA5 | Soạn quiz trong quản trị (xem trước KaTeX, kéo thả sắp xếp câu) | `f5a978c` |
| FW6 | Khóa học của tôi và tiến độ học | `027b251` |
| FA7 | Mã giảm giá | `aaacec2` |
| FA10 | Tài khoản nhân viên | `9342c02` |
| T29 | Bỏ đồng ý phụ huynh, chỉ gửi thông báo (ADR-006); huỷ nhận + danh sách chặn | `b24db58` (chưa push) |
| FW1-ADR006 | Thông tin phụ huynh không bắt buộc; gỡ luồng "chờ phụ huynh" | `40601c1` (chưa push) |

Các task đã xong trước đó: xem bảng Tóm tắt và `docs/board.md`.

## 2. Việc còn lại theo đội

### 2.1 Backend (`laravel-dev`)

| Việc | Trạng thái / ghi chú | Ước lượng |
|---|---|---|
| **T34** Quyền dữ liệu cá nhân (xuất dữ liệu, xoá tài khoản bằng OTP, chấp nhận lại chính sách) | Review APPROVE, security PASS có điều kiện, QA PASS (107 test + worker queue thật) → CI → commit → push | — |
| T29-1 | Xoá cột `users.parent_consent_status` (sau khi backfill đã chạy ở production); gom `parent_notice_opt_out_at` về bảng `parent_notice_suppressions` | 0,5 |
| T36-1 | Xoá cột `users.bio`, `users.avatar_path` ở release sau [DBA] | 0,25 |
| Đồng bộ video ở local (đề xuất, chờ PO) | Bunny webhook không tới được localhost → cho `videos:check-stuck` chạy mỗi phút, ngưỡng cấu hình được ở local | 0,25 |
| `best_attempt_id` (backlog FW6) | Trả id lượt điểm cao nhất trong `quizzes[]` của `/me/courses/{course}/progress` | 0,25 |
| `has_attempts` câu hỏi; cờ `quiz_time_limit_enabled` trong `/admin/auth/me` (backlog FA5) | Để màn soạn quiz báo trước và ẩn ô thời gian | 0,5 |
| Giá khóa rẻ nhất đang bán (backlog FA7) | FE mã giảm giá đang tự quét ≤200 khóa | 0,25 |
| `released_course_ids` kèm tên khóa (backlog FA10) | Hiện chỉ trả id | 0,25 |
| Security backlog T29-S4, T29-S6 | Che email trong lỗi gửi thư; trần tổng thư gửi bên thứ ba | 0,5 |
| Race test T18 rò dữ liệu giáo viên vào DB test | Làm `T36/AdminTeacherProfilesTest` đỏ khi chạy sau T18 | 0,25 |
| **V2 (chờ MoMo):** T19 IPN, T20 huỷ đơn 12h (phải quét cả đơn của tài khoản đã ẩn danh), T24 đơn hàng, T25 xuất file, T30 phần thanh toán | | ~8 |

### 2.2 Frontend web học sinh (`nextjs-dev`)

| Việc | Trạng thái / ghi chú | Ước lượng |
|---|---|---|
| **SLN8** Trang chi tiết khóa: nút "Tiếp tục học" và mục lục bài thành liên kết vào trang học | PO báo lỗi 2026-10-08 (TODO FW4 còn sót); **dev đang làm** | 0,25 |
| **FW7** Quyền dữ liệu cá nhân + trang công khai `/phu-huynh/huy-nhan-thong-bao` + banner chấp nhận lại chính sách + `/dieu-khoan`, `/chinh-sach-du-lieu` (bản TẠM) | Làm sau T34. **Bắt buộc lên production trước khi bật `FEATURE_PARENT_NOTICES`**. Màn OTP xoá tài khoản phải xử lý 429 `TOO_MANY_ATTEMPTS` (hết 5 lượt sai) và đọc `errors.*` (không phải `context.*`) | 2,5 |
| Gộp 3 file KaTeX trùng (web/admin) vào `packages/ui` | Backlog FA5 | 0,25 |
| `DataTable` (`packages/ui`) cần khung cuộn `relative` để `sr-only` không gây tràn 375px | Backlog FW6 | 0,25 |
| **V2:** FW3 giỏ hàng, thanh toán, đơn của tôi | Chờ MoMo | 3 |

### 2.3 Frontend quản trị (`nextjs-dev`)

| Việc | Ghi chú | Ước lượng |
|---|---|---|
| **V2:** FA8 đơn hàng, FA9 xuất file | Chờ MoMo | 2,5 |
| Nhật ký thao tác (menu đang "Sắp có") | API `/admin/audit-logs` đã có (T33) | 1 |

### 2.4 BA / Designer (`laravel-ba`, `nextjs-designer`)

- Cập nhật story + design US-017, US-018 theo ADR-006 (hiện chỉ có ghi chú "đã thay bởi ADR-006").
- Soạn nội dung chính sách TẠM (điều khoản, chính sách dữ liệu) để FW7 hiển thị, ghi rõ "Bản tạm".

### 2.5 QA / Review / Security

- QA T34 (đang chạy), rồi review + QA SLN8, FW7.
- Security: theo dõi các điều kiện "PASS có điều kiện" (T29 S1–S3 đã sửa; T34 S1/S3/S4 đã sửa, S2 theo quyết định tạm).
- E2E thật cần chạy lại trên staging: `markPaid` song song chỉ 1 thư phụ huynh (khi có IPN thật), Bunny C1–C9.

### 2.6 Hạ tầng / Release (`laravel-release`, ops)

- Commit cấu hình máy chủ ảnh tĩnh local `localhost:8080` (`infra/nginx/conf.d/vitaminvui.conf`, `infra/docker-compose.yml`) — đang chạy, chưa commit.
- Staging (T35): GHCR + Docker trên server staging; giá trị thật cho T31 (tên miền, SMTP, Turnstile, IP).
- Deploy T29: thêm cột → code → backfill → kiểm/bù (`docs/review/T29.md` mục DBA); sao lưu `parent_consent_status` trước backfill; đặt `PRIVACY_NOTICE_TOKEN_KEY` riêng ≥32 byte, KHÔNG xoay.
- Bunny production: thư viện riêng, Allowed domains, chạy checklist C1–C9 trên staging rồi mới đổi `VIDEO_PROVIDER=bunny`.
- Load test catalog (cache ấm 50 req/s) đo lại trên staging.

### 2.7 PO / Pháp chế

| Việc | Mặc định đang dùng |
|---|---|
| S2 (T34): IP/UA trong `audit_logs` của tài khoản đã xoá | Giữ, tự xoá sau 24 tháng (`audit:purge`) |
| 10 mặc định của ADR-006 (thư khi đơn có tiền, trang huỷ nhận, sửa liên hệ phụ huynh cần mật khẩu, xuất dữ liệu cần mật khẩu, 2 lần/ngày theo giờ VN, xoá tài khoản chỉ cần OTP, file xuất không gồm log truy cập/đáp án, xoá ip/ua trong consents, đổi chính sách chỉ hiện banner, BA soạn chính sách tạm) | Như ADR-006 |
| Pháp chế xác nhận: bỏ đồng ý phụ huynh với học sinh chưa thành niên; gửi tên/đơn/số tiền tới email phụ huynh chưa xác minh; "ẩn danh" hay "giả danh" khi giữ lịch sử mua/học; file xuất có liên hệ phụ huynh đầy đủ (Luật BVDLCN 2025, NĐ 356/2025) | Chờ |
| Có làm đồng bộ video mỗi phút ở local không | Chưa làm |
| Bunny production, server staging, ảnh/câu thật của người sáng lập, văn bản chính sách thật | Chờ |

## 3. Mốc demo

1. Đã có: danh mục, chi tiết khóa, trang chủ, học video (Bunny ở dev), làm quiz, khóa học của tôi; quản trị khóa học, chương/bài, quiz, duyệt đăng ký, mã giảm giá, tài khoản nhân viên, hồ sơ giáo viên.
2. Sau SLN8: vào học trực tiếp từ trang chi tiết khóa.
3. Sau T34 + FW7: học sinh tự tải dữ liệu, xoá tài khoản; phụ huynh huỷ nhận thư.
4. V2: mua khóa bằng MoMo (FW3, FA8, FA9, T19, T20, T24, T25).

## 4. Rủi ro / nợ

- Sự cố 2026-10-08 09:48: DB dev bị xoá trắng do agent chạy `migrate:fresh --env=testing`; đã tạo lại dữ liệu demo, mọi lần giao việc cấm `migrate*`, `db:wipe`, `db:seed`.
- Sau khi tạo lại container php ở local, nginx có thể trả 502 (giữ IP cũ) → `docker compose restart nginx`.
- Máy dev: Docker ~7,75 GB RAM, tối đa 2–3 agent chạy lệnh nặng cùng lúc; limiter đăng ký 30/giờ/IP làm e2e chạy lặp bị 429.
