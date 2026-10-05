# Bảng theo dõi VitaminVui

Cập nhật: 2026-10-05. Phiên tiếp theo (kể cả Claude Code on the web) đọc file này trước tiên.

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
| FA2 | Màn chuyên đề admin | QA | Review APPROVE, đã sửa; đang QA | — | 2026-10-05 |

## Việc tiếp theo (theo thứ tự)

1. Commit FA2 khi QA xong.
2. Lượt mới: **T09** Chương/bài (sau T08), **T15** Mã giảm giá (T07, T06), **FA3** Khóa học admin (T08; lưu ý lỗi 413 HTML của Nginx, errors.teacher_ids), **FW1 còn lại** (quên/đổi mật khẩu theo API T27), **FW2** Danh mục (T10).
3. Gom sửa lỗi nhỏ (một task riêng): file `lang/vi/validation.php` cho toàn dự án; T28 BUG-1 (csrf 419 sau khi phiên bị huỷ) và BUG-2 (thiếu Accept JSON → 500); mã lỗi OTP riêng `OTP_INVALID`/`OTP_EXPIRED`; nới hạn mức OTP cho môi trường e2e; email báo duyệt/từ chối đăng ký (US-012 AC2/AC3).
4. Sau đó: T11 → T12 ∥ T13; T16 (sau T14, T15) → T18 → T19 → T20; FA6 (duyệt đăng ký).
5. **Trước T20**: kiểm phản hồi query của sandbox MoMo (cần tài khoản sandbox từ PO).
6. Mỗi task: dev → `laravel-reviewer` → sửa → `laravel-qa` → tự commit (không push).

## Chờ PO xác nhận (đã chọn mặc định để không chặn)

- T14 R3: duyệt yêu cầu miễn phí khi khóa đã đổi sang có phí → 422 COURSE_NOT_FREE (mặc định).
- T08: xoá khóa bị chặn khi có đăng ký ở mọi trạng thái (kể cả bị từ chối); giáo viên không đổi được giá.
- T27: thông điệp khi thua race dùng mã reset; reset bằng đúng mật khẩu cũ có được không.
- FA2: giáo viên xem chuyên đề ở chế độ chỉ đọc (design ghi 403); bỏ xem trước slug trong form.

## Quy tắc làm việc đã thống nhất với PO

- **2026-10-05 · Tạm dừng cổng `laravel-security`** (và việc sửa lỗi bảo mật không nghiêm trọng) để đẩy tiến độ; quy trình mỗi task tạm thời là dev → `laravel-reviewer` → `laravel-qa` → PO duyệt. Lỗi bảo mật đã biết được ghi ở `docs/security/backlog-v2.md`, review lại và sửa ở v2, bắt buộc trước go-live. Lỗi Critical/High vẫn phải báo PO ngay.

- Chỉ commit/push khi PO đồng ý. `main` chỉ chứa code đã qua review.
- **2026-10-05 · PO cho phép commit tự động (không push)** sau khi task qua dev → reviewer → QA PASS, mỗi task một commit; xong task nào thì commit rồi tự chạy task kế tiếp theo thứ tự "Việc tiếp theo", không cần hỏi lại. Chỉ dừng khi gặp quyết định của PO, lỗi Critical/High, hoặc lỗi môi trường không tự xử lý được. Push vẫn do PO tự làm.
- Dev chạy mọi thứ trong Docker ở máy local. Trên cloud, `scripts/cloud-setup.sh` cài trực tiếp (xem CLAUDE.md).
- Không cài package ngoài danh sách đã duyệt trong tasks.md (cổng G2) mà không hỏi PO.
- Không tự bịa field ngoài api-contract. Thấy thiếu hoặc mâu thuẫn thì dừng và hỏi Architect.

## Việc đã hoãn (có người phụ trách)

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
- 2026-10-05 · T06, T07, T17, T27, T28, FA1 · Dev + Reviewer + QA (chạy song song) · tất cả PASS; composer ci 667 test xanh; queue worker thêm restart: unless-stopped
- 2026-10-05 · T08, T10, T14 · Dev + Reviewer + QA · PASS; sửa race xoá khóa ↔ đăng ký; composer ci 828 test xanh
