---
name: project-review-conventions
description: Quy ước review của dự án VitaminVui (đường dẫn báo cáo, security hoãn, kiểm Redis thật, docs cần đồng bộ)
metadata:
  type: project
---

- Báo cáo review lưu ở `docs/reviews/review-<Txx>.md` (có "s"), trong worktree của task, không phải `docs/review/`. Kết luận dạng PASS/FAIL hoặc APPROVE.
- Security review bị hoãn đến cuối dự án nên reviewer là cổng cuối cho các task [SEC]: soi luôn IDOR, limiter, chống dò, PII log.
- Host không có PHP: Pint/Larastan/Pest do điều phối viên chạy trong Docker; reviewer chỉ đọc code + vendor source. Test dùng cache/limiter `array`, nên mọi limiter/lock phải ghi board "kiểm Redis thật trước staging" (tiền lệ T05, T16).
- Dev hay để lại `TODO(Txx)` cầu nối giữa task (vd `Schema::hasTable('coupon_usages')` ở T16): yêu cầu ghi vào `docs/board.md` để task sau dỡ.
- Dev đôi khi tự quyết lệch tài liệu (gộp mã lỗi, đổi cách phân bổ phần dư): ghi rõ là "cần PO/Architect chốt + sửa docs", không tự coi là lỗi.

**Why:** tránh nhầm thư mục/kiểu kết luận và tránh bỏ sót các việc chỉ reviewer mới giữ.
**How to apply:** đầu mỗi review đọc `docs/board.md` + tasks.md; cuối review liệt kê việc cần ghi board.
