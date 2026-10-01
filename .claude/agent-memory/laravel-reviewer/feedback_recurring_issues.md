---
name: recurring-issues
description: Lỗi hay lặp lại khi review code Laravel của dự án (limiter, binding withTrashed, insertOrIgnore, giới hạn số)
metadata:
  type: feedback
---

- Limiter nhiều khoá (user + IP): dev hay `hit()` tất cả khoá rồi mới xét chặn, khiến request bị chặn ở khoá này vẫn đốt quota khoá kia (T16 R1). Kiểm mọi limiter tự viết: dừng ở khoá đầu bị vượt + hoàn lượt khoá trước.
- Route binding `withTrashed()` cho DELETE idempotent làm lộ tồn tại bản ghi nháp (200 vs 404) trong khi Request POST cố ý gộp lỗi. Ưu tiên nhận id số.
- `insertOrIgnore` nuốt cả lỗi FK; ưu tiên bắt `UniqueConstraintViolationException`.
- Hằng số chặn tràn số kiểu tổng (3 tỷ) có thể biến thành 500 với "không giới hạn" của nghiệp vụ: nên dựa giá dòng tối đa.
- Điều kiện nghiệp vụ lặp ở scope Model và Service (Coupon::scopeState vs CouponEvaluator): nhắc gom một nguồn.

**Why:** những chỗ này đã xuất hiện ở T16 và dễ lặp ở T18-T20 (checkout, pay).
**How to apply:** soi đầu tiên khi diff có RateLimiter/Route::...->withTrashed/insertOrIgnore.
