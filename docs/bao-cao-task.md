# Báo cáo task VitaminVui

Cập nhật: 2026-10-07. Nguồn định nghĩa task: `docs/architecture/tasks.md`. Trạng thái chi tiết và quy tắc làm việc: `docs/board.md`.
Ngày công là ước lượng của tài liệu, chưa tính thời gian chờ PO duyệt và sửa sau review/QA.

## Tóm tắt

| Nhóm | Đã xong | Đang làm | Chưa làm | Hoãn V2 |
|---|---|---|---|---|
| Backend MVP (T01–T34, không có T32) | 26 + T30 phần không thanh toán | 0 | 0 | 6 (T19, T20, T24, T25, T29, T34) + phần thanh toán của T30 |
| Backend bổ sung sau MVP | T36, T37, T37-1, Sửa lỗi nhỏ 1–5, Bảo mật cụm 1–4, trần video 1 GB | 0 | 0 | — |
| Frontend | FE0, FA1, FA2, FA3, FA4, FA11, FW1, FW2, FW4, FW-V2, FA-V2, design system v2 | 0 | FW8, FW9, FW5, FW6, FA5, FA6, FA7, FA10 | FW3, FW7, FA8, FA9 |

- Thanh toán đang khoá (`FEATURE_PAID_CHECKOUT=false`, chờ MoMo): khóa có phí hiện giá + "Sắp mở bán".
- Mọi task đã xong đã commit và push lên `origin/main`.
- Video: production vẫn dùng VideoLab (`VIDEO_PROVIDER=internal`) cho tới khi làm xong checklist staging Bunny C1–C9 (`docs/qa/T37.md`).

## 1. Đã xong

### Backend

| Task | Tên | Ghi chú |
|---|---|---|
| T01–T18, T21–T23, T26–T28, T31, T33 | Backend MVP | Review, QA (xem `docs/board.md`) |
| T30 (một phần) | `audit:purge`, `users:purge-unverified` | Phần dọn dữ liệu thanh toán để V2 |
| Sửa lỗi nhỏ 1–3, Bảo mật cụm 1–4 | Validation tiếng Việt, chống chiếm tài khoản, mật khẩu, audit bất biến, cấu hình production, Redis ACL | Review, Security, QA |
| T36 | Hồ sơ giáo viên công khai (US-019/US-020) | Review, Security, QA |
| Sửa lỗi nhỏ 4 | Limiter catalog cho SSR nội bộ + Nginx mẫu (ADR-004 §2.8) | Review, QA trên Nginx thật |
| T37 | Kết nối Bunny Stream (US-021) | Review, Security (PASS có điều kiện), QA; đã thử thật tải 3 video lên thư viện staging 772566: chữ ký TUS, trạng thái, thời lượng, xoá đều đạt |
| Trần video 1 GB | `VIDEO_MAX_UPLOAD_MB=1024` (PO 2026-10-07) | CI xanh |
| T37-1 | Lệnh `videos:migrate-provider` chuyển video VideoLab sang Bunny (có hoàn tác) | Review, QA; chạy thật trên Bunny làm ở staging |

### Frontend

| Task | Tên | Ghi chú |
|---|---|---|
| FE0 | Khởi tạo Next.js 16 | Review |
| Design system v2 | "Vở ô ly & mực tím", bản xem trước `/v2`, nền ô ly chủ đạo, poster người sáng lập tạm | PO duyệt 2026-10-06/07 |
| FW-V2 + FW2 | Web học sinh sang v2; danh mục `/khoa-hoc`, `/lop-{n}`, chi tiết khóa | Review, QA (e2e thật 72 pass); sửa đổi email/SĐT gửi mật khẩu hiện tại |
| FA-V2 (gồm FA1, FA2 làm lại) | Quản trị sang v2: đăng nhập, MFA, đổi mật khẩu lần đầu, 403, chuyên đề | Review, QA (e2e thật 26 pass) |
| FA3 | Khóa học quản trị v2: danh sách, tạo, sửa, gán giáo viên, ảnh bìa | Review, QA (e2e thật 30 pass) |
| FW1 | Đăng nhập/đăng ký/OTP v2, quên + đặt lại mật khẩu, màn chặn, hộp thoại mất phiên, captcha Turnstile | Review (2 vòng), QA (bản build production, e2e thật 69 pass) |

## 2. Đang làm

Không có. Task tiếp theo: FW8 (trang chủ thật) + FW9 (khu giáo viên trang chủ), rồi FW5/FA5 (quiz).

## 3. Chưa làm — Frontend (theo thứ tự đề xuất)

| Task | Nội dung | Ngày công |
|---|---|---|
| FA4 | Cây chương/bài, tải video lên (TUS, VideoLab/Bunny), trạng thái video | 3 |
| FW4 | Học video (hls.js, lấy lại link trước khi hết hạn 15 phút, heartbeat) | 3 |
| FW8 | Trang chủ thật (gồm poster người sáng lập) | 2,75–3 |
| FW9 | Khu vực giáo viên ở trang chủ | 1,5 |
| FA11 | Hồ sơ giáo viên (quản trị) | 2,5 |
| FW5 | Làm quiz (KaTeX, đồng hồ, autosave) | 2,5 |
| FW6 | Khóa học của tôi, tiến độ | 1,5 |
| FA5 | Soạn quiz | 2 |
| FA6 | Duyệt đăng ký | 1 |
| FA7 | Mã giảm giá | 1,5 |
| FA10 | Quản lý tài khoản staff | 1,5 |

## 4. Để sang V2

| Task | Lý do |
|---|---|
| T19, T20, T24, T25, FW3, FA8, FA9 | Thanh toán/đơn hàng, chờ MoMo |
| T29, T34, FW7 | Pháp chế (phụ huynh, quyền dữ liệu cá nhân) |

## 5. Mốc demo

1. Đã có: danh mục + chi tiết khóa học (T10 + FW2), đăng nhập quản trị + chuyên đề (FA-V2).
2. Sau FA4 + FW4 (FA3 đã xong): giáo viên tạo khóa, tải video, học sinh học video.
3. Sau FW8 + FW9 + FA11: trang chủ thật có giáo viên và poster.
4. V2: mua khóa bằng MoMo.

## 6. Đang chờ PO

- Bunny staging (thư viện 772566): chép lại CDN Hostname đúng (tên hiện có không tồn tại) để thử phát video.
- Bunny production: thư viện riêng, tên miền phát video, ngân sách băng thông, hợp đồng xử lý dữ liệu (pháp chế).
- Staging (T35): GHCR + Docker trên server staging; giá trị thật cho T31 (tên miền, SMTP, Turnstile, IP).
- Ảnh/tên/câu thật của người sáng lập (đang dùng nội dung tạm).
- Các câu nghiệp vụ đã có mặc định: xem `docs/board.md` và `docs/design/design-system-v2.md` §18.

## 7. Rủi ro / nợ

- Load test catalog cache ấm 50 req/s chưa đạt trên máy dev; đo lại trên staging (mở rộng instance Next nếu cần).
- Bunny: công thức token phát chưa thử thật (chờ CDN Hostname); checklist C1–C9 trên staging.
- Máy dev: Docker ~7,75 GB RAM, tối đa 2–3 agent chạy lệnh nặng cùng lúc.
