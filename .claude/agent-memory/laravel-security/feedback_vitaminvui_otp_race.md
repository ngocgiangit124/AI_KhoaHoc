---
name: feedback-vitaminvui-otp-race
description: Throttle Laravel không nguyên tử với request song song; OTP phải gắn destination; xác minh bằng test tạm (T04)
metadata:
  type: feedback
---

- `ThrottleRequests::handleRequest()` (Laravel 13.33) kiểm `tooManyAttempts()` cho mọi limit rồi mới `hit()` → N request đồng thời đều lọt. Đếm-rồi-INSERT trong DB cũng vậy nếu không `lockForUpdate`. Đừng chấp nhận lập luận "route throttle đã chặn nên race lớp 2 không chạm tới" (review code T04 vòng 2 kết luận sai như vậy).
- Mã OTP/xác thực phải gắn với `destination` và bị huỷ trong cùng transaction đổi email/SĐT; nếu không, mã gửi tới địa chỉ cũ xác thực được địa chỉ mới.
- Đổi liên hệ không cần mật khẩu/OTP kênh cũ + chưa có thông báo → chiếm tài khoản khi có quên mật khẩu (T27). Soi lại khi review T27.

**Why:** T04 security review 2026-09-28 (M1, M2, M3 trong `docs/security/review-T04.md`).
**How to apply:** Với mọi trần gửi/verify, xem có khoá hàng hoặc bộ đếm nguyên tử không; xác minh bằng test tạm trong `tests/Feature/TmpSec*/` rồi xoá. Liên quan [[feedback-vitaminvui-otp-throttle-pattern]].

**Vòng 2 (c4a60b5, 2026-09-29):** M1/M2 đóng bằng `OtpService::createCodeAtomically()` (lockForUpdate users → kiểm trần → đổi liên hệ → huỷ/tạo mã; mail sau commit). Bẫy còn lại cần soi ở các task sau:
- Kiểm trần theo đích phải chạy trên địa chỉ SẼ GỬI (sau khi áp thay đổi), không phải `$user` cũ (N1).
- Khoá hàng rồi vẫn dùng `$user` nạp trước khoá → dữ liệu cũ (I10); `assertCanSend()` không khoá, không kiểm đích — đừng để T27/T29 dùng lại (I11).
- DB dùng READ COMMITTED (config/database.php) → ít rủi ro gap-lock deadlock; thứ tự khoá chuẩn: `users` trước, rồi bảng con. Không gọi `send()` trong transaction ngoài.
- Cách kiểm nhanh: test tạm gọi thẳng `ContactService::update()` với config trần nhỏ (`max_per_*_per_destination=2`, cooldown 0).
