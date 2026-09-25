---
name: mysql-vitaminvui-patterns
description: Các bảng lớn, index quan trọng, đặc thù MySQL/InnoDB đã review trong data-model.md của VitaminVui (2026-09-25)
metadata:
  type: project
---

Kết quả review thiết kế đầu tiên (chưa có DB thật) nằm ở `docs/db/design-review.md`. Tóm tắt để tránh đọc lại toàn bộ `docs/architecture/data-model.md` mỗi lần:

**Bảng lớn dự kiến:** `lesson_progress` (~30M dòng/3 năm, ~50 ghi/giây heartbeat, ước tính ~7-9GB — KHÔNG cần partition ở MVP, chỉ cần nếu sau này archive theo năm học và lúc đó phải đổi PK thành (id, created_at) vì MySQL bắt buộc partition key nằm trong mọi unique key). `orders` (~700k-1M dòng/3 năm). `payment_webhook_events` (~2M dòng/~4GB sau 3 năm, chỉ là log kỹ thuật thô, không phải hồ sơ kế toán — `orders`/`payment_attempts`/`order_status_logs` mới là hồ sơ cần giữ lâu dài không xoá).

**Cơ chế chống trùng đã dùng:** generated column STORED + `CASE WHEN status IN (...) THEN 1 END` + unique index (MySQL không có filtered/partial index) cho: `enrollments(user_id, course_id, live_flag)`, `orders(user_id, pending_flag)`, `quiz_attempts(user_id, quiz_id, in_progress_flag)`. Đây là pattern chuẩn cho dự án này, dùng lại khi có yêu cầu "chỉ 1 bản ghi đang active" tương tự.

**Isolation level:** dự án đổi từ REPEATABLE READ (mặc định InnoDB) sang READ COMMITTED ở mức session/connection để tránh (a) snapshot cũ khi đếm sức chứa coupon trong transaction có nhiều SELECT thường, (b) gap lock gây deadlock khi nhiều học sinh insert đơn/enrollment đồng thời. Cấu hình đúng là `PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'` trong `options` của connection `mysql` — **KHÔNG** dùng khoá `isolation_level` (đó là khoá riêng của driver `sqlsrv`, không tồn tại cho MySQL trong Laravel). Locking read (`lockForUpdate()`) luôn đọc bản mới nhất bất kể isolation level — dùng đúng chỗ này thay vì đổi isolation toàn cục nếu muốn thu hẹp phạm vi ảnh hưởng.

**Thứ tự khoá thống nhất (checkout/IPN/đối soát/huỷ đơn):** `carts(user)` → `orders` → `payment_attempts` → `enrollments` → `coupons`/`coupon_usages` → `courses` (increment). Lưu ý: bản data-model §4 tóm tắt viết coupons trước enrollments, còn ADR-001 §4 chi tiết luồng IPN lại làm enrollments trước coupons — đã báo Architect thống nhất lại, cần kiểm tra đã sửa chưa ở lần review tiếp theo.

**Collation:** dự án dùng `utf8mb4_unicode_ci` (mặc định Laravel 11) — lưu ý hành vi đã biết: coi nhiều chữ có dấu/không dấu tiếng Việt là BẰNG NHAU trong so sánh unique/ORDER (ví dụ "Hình học" = "Hinh hoc"), đây là hành vi thật của MySQL UCA 4.0.0, không phải bug. Đã đề xuất Architect cân nhắc đổi sang `utf8mb4_0900_ai_ci` (UCA 9.0.0, nhanh hơn) trước migration đầu tiên vì đổi sau tốn kém (`ALTER TABLE CONVERT` trên bảng lớn). Course tìm kiếm không dấu dùng cột `search_text` chuẩn hoá bằng PHP (`Str::ascii`), không dựa vào collation — cách tiếp cận đúng, nên khuyến nghị lại cho các tính năng tìm kiếm tiếng Việt khác trong dự án.

**CHECK constraint:** dự án dùng CHECK MySQL 8.0.16+ cho `grade_level BETWEEN 6 AND 12`; đã đề xuất thêm cho `coupons.discount_value` (theo `discount_type`) và `JSON_LENGTH(quiz_attempts.question_ids)`.
