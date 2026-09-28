# Bảng theo dõi VitaminVui

Cập nhật: 2026-09-28 (chiều). Phiên tiếp theo (kể cả Claude Code on the web) đọc file này trước tiên.

## Trạng thái task

Danh sách task, phụ thuộc và định nghĩa "xong": `docs/architecture/tasks.md`.

| Task | Tên | Bước hiện tại | Trạng thái | Chặn bởi | Cập nhật |
|---|---|---|---|---|---|
| T01 | Khởi tạo backend Laravel 13 + Docker | Xong | ✅ Review, Security (PASS có điều kiện), QA (PASS) | — | 2026-09-28 |
| T02 | Users, audit_logs, vai trò, staff:* | Xong | ✅ như T01 | — | 2026-09-28 |
| FE0 | Khởi tạo frontend Next.js 16 | Xong | ✅ Review; 64 unit + e2e backend thật | — | 2026-09-25 |
| T03 | Đăng ký/đăng nhập học sinh | Chờ QA giai đoạn 1 | ✅ Review PASS; Security PASS có điều kiện (còn: L3 phần DB — DBA trước staging; L2 — PO; N5 — Architect sửa api-contract §1.7) | — | 2026-09-28 |
| FW1 (phần 1) | Màn đăng ký/đăng nhập/đăng xuất (apps/web) | Chờ QA giai đoạn 1 | ✅ Review PASS; Security PASS | — | 2026-09-28 |
| FW1 (phần 2) | Màn xác thực OTP | Dev xong, chưa review | Nhánh `claude/zen-dirac-fmucf7-fw1-otp` (7e5fa1f). Có 4 giả định cần đối chiếu với T04 | T04 | 2026-09-28 |
| T04 | OTP | Chưa bắt đầu | Worktree cloud chưa có code T04 (đã dừng sau khi sửa base). Làm lại từ nhánh chính | — | 2026-09-28 |
| T05 | Một phiên học sinh | Chưa làm | — | Chờ gộp T03/T04 (sửa cùng file) | — |
| T06 | Chuyên đề | Dev dở (WIP) | Nhánh `claude/zen-dirac-fmucf7-t06`. Test đang skip phần "chặn xoá khi đang gán" chờ T07 | — | 2026-09-28 |
| T07 → T10 | Schema nội dung → danh mục công khai | T07 dev xong; T10 dở (WIP) | Nhánh `claude/zen-dirac-fmucf7-t07-t10`. Checklist DBA: `docs/db/T07-checklist.md` | — | 2026-09-28 |
| T17 | Thanh toán: abstraction + MoMo | Dev gần xong, chưa chạy hết test | Nhánh `claude/zen-dirac-fmucf7-t17`. Chưa kiểm chứng sandbox MoMo thật | — | 2026-09-28 |

## Bàn giao về máy local (2026-09-28, cuối phiên Claude Code on the web)

Nhánh chính `claude/zen-dirac-fmucf7`: T03 + FW1 phần 1 đã qua review và security, chờ QA giai đoạn 1. Các nhánh phụ bên dưới **chưa review**, tách từ `57e2439`:

| Nhánh | Nội dung | Trạng thái |
|---|---|---|
| `claude/zen-dirac-fmucf7-fw1-otp` | FW1 phần 2: `/xac-thuc-otp` | Xong code, lint/typecheck/test/build xanh trên cloud |
| `claude/zen-dirac-fmucf7-t06` | T06 chuyên đề | WIP |
| `claude/zen-dirac-fmucf7-t07-t10` | T07 schema (xong code) + T10 danh mục (WIP) | WIP |
| `claude/zen-dirac-fmucf7-t17` | T17 thanh toán + MoMo | Gần xong |

Việc đầu tiên ở local:
1. Chạy `composer ci` đầy đủ (có Larastan) trong Docker trên nhánh chính. Trên cloud không chạy được Larastan và PHP là 8.4.
2. Mỗi nhánh phụ: chạy lại toàn bộ test trong Docker trước khi tin kết quả. Trên cloud, `vendor` của worktree là symlink về repo gốc nên Composer/Pest có thể đã nạp nhầm code của repo gốc; kết quả test agent báo ở các nhánh phụ **chưa đáng tin**.
3. Xung đột khi gộp: T06 và T07 cùng tạo `subjects` (migration `2026_09_28_090000_create_subjects_table.php`, `Subject`, `SubjectFactory`, `SubjectStatus`). Gộp T07 trước, T06 dùng lại bản của T07 và bật lại test "chặn xoá khi đang gán".
4. FW1 phần 2 giả định về T04 (đối chiếu khi làm T04): `resend_available_at` dạng ISO 8601 có offset; register chưa trả `resend_available_at` nên FE ước lượng cooldown lần đầu; email/SĐT ở màn OTP do FE tự che; 429 khoá 24h không có thời điểm mở khoá.
5. Quy trình mỗi nhánh: `laravel-reviewer` → `laravel-security` (T04, T17 [SEC]) → gộp vào nhánh chính. `laravel-qa` chạy khi xong giai đoạn.

## Việc tiếp theo (theo thứ tự)

1. **T03** (`laravel-dev`) và **FW1 phần đăng ký/đăng nhập** (`nextjs-dev`) chạy song song, cả hai bám `docs/architecture/api-contract.md` §2.2.
   - T03: làm theo tasks.md mục T03. Không làm OTP (T04), một phiên (T05), quên mật khẩu (T27), đăng nhập quản trị (T28), xác nhận phụ huynh (T29).
   - Khi T03 thêm route `auth:sanctum` đầu tiên: đổi `expect($checked)->toBe(0)` trong `backend/tests/Feature/T02/RouteMiddlewareGroupsTest.php` theo TODO, thêm `role:hoc_sinh` cho nhóm student và test kiến trúc cho host api (ghi chú của Security ở `docs/security/review-T01-T02.md`).
   - FW1: chỉ màn Đăng ký, Đăng nhập, đăng xuất. Được cài `react-hook-form` + `@hookform/resolvers`. Turnstile dùng script chính thức.
2. Mỗi task đi qua quy trình: `laravel-reviewer` → sửa → `laravel-security` (task có [SEC]) → `laravel-qa` → PO duyệt commit/push.
3. Sau đó: T04 → T05 → T27, rồi T28 (đăng nhập quản trị, phải xong trước FA1).

## Quy tắc làm việc đã thống nhất với PO

- Chỉ commit/push khi PO đồng ý. `main` chỉ chứa code đã qua review. Nhánh làm việc `claude/zen-dirac-fmucf7` được commit WIP (PO đồng ý 2026-09-28).
- (2026-09-28) Task không phụ thuộc nhau chạy song song: mỗi agent một git worktree (`.claude/worktrees/`, không commit) và DB test riêng (`vitaminvui_testing_<task>`), gộp vào nhánh làm việc sau khi qua review.
- (2026-09-28) `laravel-qa` chạy một lần khi xong mỗi giai đoạn trong `tasks.md`, không chạy sau từng task. Mỗi task vẫn qua `laravel-reviewer` và `laravel-security` (task [SEC]). Báo cáo QA: `docs/qa/giai-doan-<số>.md`.
- Dev chạy mọi thứ trong Docker ở máy local. Trên cloud, `scripts/cloud-setup.sh` cài trực tiếp (xem CLAUDE.md).
- Không cài package ngoài danh sách đã duyệt trong tasks.md (cổng G2) mà không hỏi PO.
- Không tự bịa field ngoài api-contract. Thấy thiếu hoặc mâu thuẫn thì dừng và hỏi Architect.

## Việc đã hoãn (có người phụ trách)

- Quyền MySQL/trigger chặn sửa `audit_logs` (DBA, trước staging).
- Header bảo mật cho response do Nginx tự trả (N2), cấu hình log production (L5), giới hạn IP cho `/up`: T31.
- L2: route domain dạng tham số, khi có route như vậy.

## Chờ PO trả lời

0. L2 (`docs/security/review-T03-FW1.md`): staff đúng mật khẩu ở host học sinh nhận `WRONG_PORTAL` → host học sinh thành chỗ thử mật khẩu staff. Đề xuất: trả lỗi chung như sai mật khẩu; ghi vào T28.

1. Throttle `csrf` đang 30 lần/phút/IP. Có nâng lên 120 cho lớp học dùng chung NAT không?
2. Các mặc định an toàn ở `docs/architecture/README.md` §8 (MFA staff, che PII khi xuất file, TTL link HLS...).
3. LB/CDN production có chuyển tiếp `X-Forwarded-Host` từ client không (chốt ở T31).
4. Pháp chế: Luật BVDLCN 2025, ngưỡng tuổi cần phụ huynh đồng ý, thời hạn lưu log có IP, chuyển dữ liệu ra nước ngoài.

## Nhật ký

- 2026-09-25 · US-001..018 · BA/Designer/Architect/Security/DBA · Xong tài liệu thiết kế
- 2026-09-25 · T01, T02, FE0 · Dev + Reviewer · Xong, đã sửa R1–R5
- 2026-09-28 · T01, T02 · Security + QA · Security FAIL (H1) → sửa → PASS có điều kiện; QA PASS; 114 test; commit 58fa34d
- 2026-09-28 · T03, FW1 · Dev · Tạm dừng trước khi code, bàn giao cho Claude Code on the web
- 2026-09-28 · T03, FW1 · Dev + Reviewer (2 vòng) + Security · Review PASS; Security PASS có điều kiện, đã sửa; 192 test backend, 111 test frontend. Cloud: PHP 8.4, không chạy được Larastan (mạng chặn tải phpstan) → chạy `composer ci` trên Docker local trước khi merge main
- 2026-09-28 · PO · Đổi quy trình: QA theo giai đoạn; chạy song song task độc lập (T04, T06, T07→T10, T17, FW1 phần 2)
- 2026-09-28 · Orchestrator · Dừng các agent cloud, push nhánh phụ WIP để PO chuyển về local làm tiếp
