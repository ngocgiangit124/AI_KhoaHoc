---
name: t27-password-review
description: T27 (quên/đổi mật khẩu) review notes - enumeration oracles ở reset, limiter đếm trước captcha
metadata:
  type: feedback
---
OtpService::consume ném EXPIRED trước khi băm khi không có mã -> reset phân biệt tài khoản tồn tại/không bằng thời gian và WRONG vs EXPIRED. **Why:** dummy hash chỉ ở nhánh ineligible. **How to apply:** với endpoint guest dùng OTP, kiểm cả timing lẫn thông điệp giữa 3 trạng thái (không tồn tại / không có mã / mã sai); throttle theo tài khoản chạy trước captcha = khoá nạn nhân không cần captcha.
Test helper luôn gửi X-Device-Id nên nhánh fallback device không được phủ. killSession chạy trong transaction ngoài (đề xuất afterCommit).
