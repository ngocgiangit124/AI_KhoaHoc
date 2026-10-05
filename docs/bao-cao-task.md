# Báo cáo task VitaminVui

Cập nhật: 2026-10-05 (tối, sau T09, T15). Nguồn định nghĩa task: `docs/architecture/tasks.md`. Trạng thái chi tiết và quy tắc làm việc: `docs/board.md`.
Ngày công là ước lượng của tài liệu, chưa tính thời gian chờ PO duyệt và sửa sau review/QA.

## Tóm tắt

| Nhóm | Tổng | Đã xong | Đang làm | Chưa làm | Ngày công còn lại |
|---|---|---|---|---|---|
| Backend (T01–T34, không có T32) | 33 | 15 | 3 | 15 | ~32 |
| Frontend (FE0, FW1–FW7, FA1–FA10) | 18 | 3 | 3 | 12 | ~29 |

Ghi chú: mọi task đã xong đều đã commit local (chưa push, PO tự push). Cổng `laravel-security` tạm dừng theo quyết định PO; nợ bảo mật ở `docs/security/backlog-v2.md`.

## 1. Đã xong

| Task | Tên | Ghi chú |
|---|---|---|
| T01 | Khởi tạo backend Laravel 13 + Docker | Review, Security, QA |
| T02 | Users, audit_logs, vai trò, staff:* | Như T01 |
| T03 | Đăng ký/đăng nhập học sinh | Review, QA |
| T04 | OTP + `GET /auth/me` | Review, QA |
| T05 | Một phiên học sinh (ADR-003) | Review, QA |
| T06 | Chuyên đề CRUD | Review, QA |
| T07 | Schema nội dung + ghi danh | Review (kiêm DBA), QA |
| T17 | Thanh toán: abstraction + MoMo | Review, QA; chưa kiểm sandbox MoMo (bắt buộc trước T20) |
| T27 | Quên/đổi mật khẩu học sinh | Review, QA |
| T28 | Đăng nhập quản trị (MFA, idle, đổi mật khẩu) | Review, QA |
| T08 | Quản trị khóa học | Review, QA |
| T10 | Danh mục công khai + chi tiết | Review, QA |
| T14 | EnrollmentService | Review, QA |
| FE0 | Khởi tạo frontend Next.js 16 | Review |
| FA1 | Layout quản trị, đăng nhập, MFA, đổi mật khẩu lần đầu, menu theo vai trò | Review, QA cùng T28 |
| FA2 | Quản lý chuyên đề (admin) | Review, QA; 2 lỗi Minor chuyển FA3 sửa |
| T09 | Chương/bài | Review, QA |
| T15 | Mã giảm giá quản trị | Review, QA |

## 2. Đang làm

| Task | Tên | Bước hiện tại | Ngày công |
|---|---|---|---|
| T11 | Contract video | Dev đang làm | 1,5 |
| T16 | Giỏ hàng | Dev đang làm | 1,5 |
| Sửa lỗi nhỏ | validation tiếng Việt, T28 BUG-1/2, mã lỗi OTP, email duyệt đăng ký | Dev đang làm | ~1 |
| FW2 | Danh mục web `/khoa-hoc`, `/lop-{grade}`, chi tiết khóa | Dev đang làm | 3,5 |
| FA3 | Khóa học (admin), kèm sửa 2 lỗi Minor của FA2 | Dev đang làm | 2 |
| FW1 | Đăng ký, đăng nhập, OTP đã xong; còn màn quên/đổi mật khẩu | Chờ làm phần còn lại (API T27 đã có) | còn ~0,5 |

## 3. Chưa làm — Backend

| Task | Tên | Ngày công | Phụ thuộc | Cờ |
|---|---|---|---|---|
| T12 | Module VideoLab (task dài và rủi ro nhất) | 4 | T11 | SEC |
| T13 | Học & tiến độ | 2 | T11, T05 | DBA, SEC |
| T18 | Checkout | 2 | T16, T17 | SEC, DBA |
| T19 | IPN & fulfillment | 2 | T18, T14 | SEC, DBA |
| T20 | Đối soát + huỷ 12h + `/pay` + đơn của tôi | 2 | T19 | SEC, DBA |
| T21 | Soạn quiz | 1,5 | T09 | SEC |
| T22 | Làm quiz | 2 | T21, T13 | DBA |
| T23 | Khóa học của tôi + tiến độ | 1 | T13, T22 | |
| T24 | Admin đơn hàng | 2 | T19, T28 | SEC, DBA |
| T25 | Xuất CSV/XLSX | 2 | T24 | SEC, DBA |
| T29 | Đồng ý của phụ huynh (nội dung pháp lý chờ pháp chế) | 2 | T03, T04 | SEC |
| T30 | Job dọn dữ liệu & bộ đếm | 1 | T19, T04, T25 | DBA |
| T34 | Quyền dữ liệu cá nhân | 2 | T29 | SEC |
| T33 | Quản lý tài khoản staff | 1,5 | T28 | SEC |
| T26 | Vận hành queue/scheduler | 1 | — | |
| T31 | Checklist production & DNS | 1 | — | SEC |

Tổng backend còn lại (kể cả T11, T16 đang làm): ~32 ngày công. T32 (nâng cấp sau go-live) nằm ngoài MVP.

## 4. Chưa làm — Frontend

| Task | Nội dung | Ngày công | Phụ thuộc |
|---|---|---|---|
| FW1 (còn lại) | Quên/đổi mật khẩu, overlay phiên, màn chặn checkout | (trong 3) | T05, T27 |
| FW3 | Giỏ hàng, checkout, kết quả thanh toán, đơn của tôi | 3 | T16–T20 |
| FW4 | Học video (hls.js, heartbeat), iframe link ngoài | 3 | T13 |
| FW5 | Quiz (KaTeX, đồng hồ, autosave) | 2,5 | T22 |
| FW6 | Khóa học của tôi, tiến độ | 1,5 | T23 |
| FW7 | Xác nhận phụ huynh, quyền dữ liệu cá nhân | 1,5 | T29, T34 |
| FA4 | Cây chương/bài, upload TUS, trạng thái video | 3 | T09, T11, T12 |
| FA5 | Soạn quiz | 2 | T21 |
| FA6 | Duyệt đăng ký | 1 | T14 |
| FA7 | Mã giảm giá | 1,5 | T15 |
| FA8 | Đơn hàng | 2 | T24 |
| FA9 | Xuất file | 0,5 | T25 |
| FA10 | Quản lý tài khoản staff | 1,5 | T33 |

## 5. Mốc demo

1. Sau T10 + FW2: danh mục khóa học.
2. Sau T13 + FW4: học video.
3. Sau T20 + FW3: mua khóa bằng MoMo sandbox.
4. Sau T25 + FA8/FA9: vận hành đơn hàng.

## 6. Đang chờ quyết định

- PO: thông tin sandbox MoMo (bắt buộc trước T20); các mặc định trong mục "Chờ PO xác nhận" của `docs/board.md`.
- Nợ kỹ thuật: e2e web 7/21 chạy lại sau khi FW2 xong (FW2 đang dùng cổng 3000).

## 7. Để sang V2 (PO quyết định 2026-10-05)

- Cách khoá đăng nhập chống khoá tài khoản người khác (M1 trong backlog bảo mật); ngưỡng khoá đăng nhập AC6 (5 lần/15 phút).
- Ý nghĩa đổi SĐT qua `/auth/contact` khi production chỉ xác thực email.
- Trang `/dieu-khoan` và `/chinh-sach-du-lieu`.
- Pháp chế: thời hạn lưu IP/UA trong `consents`; quy tắc tuổi/phụ huynh (nội dung T29). Bàn lại ở V2.

Đã xử lý: push lên `origin/main` đến `0367da2`; sửa `frontend/scripts/pnpm.sh` và `playwright.sh` chạy được trên macOS.
