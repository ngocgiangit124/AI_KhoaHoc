---
name: t04-otp-review-pitfalls
description: Điểm hay sai ở T04/FW1 OTP: invalidateAll quá rộng, fetchCurrentUser coi lỗi là guest, cooldown ban đầu, frontend nằm trong commit không phải working tree
metadata:
  type: feedback
---

- FE đã commit trong `fa936c7` ("chinh code") chứ không ở working tree: dùng `git log --stat -- frontend` khi `git status` không thấy frontend.
- `ContactService::update` gọi `invalidateAll` cho mọi kênh; đổi SĐT-only ở production làm mất mã email đang chờ mà UI vẫn báo "đã gửi mã mới".
- `fetchCurrentUser` trả null cho mọi lỗi (5xx/mạng) → coi là guest → trang yêu cầu đăng nhập redirect nhầm.
- Test song song OTP xoá toàn bảng trong DB `vitaminvui_testing` (an toàn vì phpunit.xml ép DB), nhưng nên có guard tên DB.
- Cổng kiểm: `cd infra && docker compose exec -T php composer ci` chạy được cho reviewer.
**How to apply:** soi các điểm này khi review lại T05/T27/T28 và các luồng OTP tái sử dụng `OtpService::consume`.
