# DBA review T38 / T24-V1 / T39 (US-022, thanh toán thủ công)
Ngày 2026-10-08 · người review: laravel-dba · MySQL 8.4.11 (Docker local), thử trên bảng sao chép `dba38_orders` (200k dòng) trong DB `vitaminvui_testing_g`, đã dọn (`DROP`) sau khi đo. Không đụng DB `vitaminvui`, không chạy `artisan migrate`.

**Kết luận: PASS có điều kiện** (3 chỉnh sửa nhỏ cho dev, mục "Chỉ dẫn cho dev"). Thiết kế không có rủi ro khoá/deadlock mới nếu giữ đúng thứ tự khoá.

## 1. Thêm cột vào `orders` (có `pending_flag` STORED)
Đo thực tế:
| Thao tác | Kết quả |
|---|---|
| `ADD COLUMN customer_note, cancel_reason_public varchar(500) NULL, ALGORITHM=INSTANT` | OK (cột generated STORED và unique `(user_id, pending_flag)` không cản INSTANT) |
| `ADD COLUMN confirmed_by bigint unsigned NULL, ALGORITHM=INSTANT` | OK |
| `ADD FOREIGN KEY ... INPLACE` (foreign_key_checks=1) | LỖI 1846 "Adding foreign keys needs foreign_key_checks=OFF" → mặc định là COPY, 200k dòng ~10 giây chặn ghi |
| cùng câu với `SET foreign_key_checks=0`, `INPLACE, LOCK=NONE` | OK, tức thời |
| `DROP FOREIGN KEY ... INPLACE` | OK |

Quyết định: **giữ FK `confirmed_by`, không tách migration, bọc câu thêm FK bằng `Schema::withoutForeignKeyConstraints()`**. Cột mới toàn NULL nên bỏ kiểm tra FK không mất an toàn; cột (INSTANT) và FK là 2 câu ALTER riêng nên không ảnh hưởng nhau. Production hiện rất ít đơn nên kể cả COPY cũng chấp nhận được, nhưng cách trên không tốn công và dùng lại được khi bảng lớn. Giới hạn INSTANT: tối đa 64 lần "row version" cho tới khi bảng được rebuild; 3 cột là ít, không lo. Không dùng `->after()` (không cần, đã đo không có nó).

FK `confirmed_by`: InnoDB tự tạo index `orders_confirmed_by_foreign` (ghi thêm 1 index trên bảng ghi ít, không đáng kể). Khi UPDATE `confirmed_by` khi duyệt, InnoDB lấy khoá S trên dòng `users` của staff (xem mục 4, không gây vòng chờ).

## 2. CHECK `chk_orders_payment_method`
- Giá trị trong code hiện chỉ có `momo`, `fake`, `none` (+ `manual` mới) và NULL. An toàn.
- `ADD CONSTRAINT CHECK` chỉ chạy COPY (đo 200k dòng ~8 giây, chặn ghi). Với bảng production nhỏ là tức thời. `DROP CHECK` là INPLACE nên `down()` an toàn.
- **Bẫy đã kiểm chứng:** CHECK so sánh theo collation cột (`utf8mb4_0900_ai_ci`, không phân biệt hoa thường). Dòng `'MANUAL'` LỌT CHECK nhưng PHP `=== 'manual'` sai. Câu kiểm trước migration phải dùng `BINARY payment_method NOT IN (...)` (thử: câu thường trả 0, câu BINARY trả 1). 
- Migration kiểm dữ liệu rồi `throw RuntimeException` (liệt kê giá trị + số dòng) trước khi chạy ALTER; không sửa dữ liệu. Đủ an toàn.
- Rủi ro bảo trì: thêm cổng mới (ví dụ vnpay) phải có migration sửa CHECK (COPY). Chấp nhận, ghi vào checklist thêm cổng. Nếu Architect không muốn gánh việc này thì bỏ migration 3 cũng được (app đã validate); không bắt buộc.

## 3. Bảng `order_notes`
Thiết kế đạt. Đã thử tạo với 2 FK: MySQL dùng index `(order_id, id)` làm chỉ mục FK `order_id` (không sinh index thừa), tự tạo index `author_id`. Không thêm index nào khác (truy vấn duy nhất: `WHERE order_id=? ORDER BY id`, khớp `(order_id, id)`). 
- Charset/collation theo mặc định bảng của dự án (`utf8mb4`, collation theo cấu hình connection/DB; DB hiện `0900_ai_ci`). `body` varchar(1000) = 4000 byte, không vướng giới hạn row/index.
- `created_at datetime NOT NULL` không default: model phải set (Laravel `UPDATED_AT = null` vẫn set `created_at`). OK.
- FK cascade `order_id` giống `order_status_logs`; đơn không bị xoá (các FK khác đều restrict) nên cascade vô hại.
- Dung lượng ≤ 500k dòng sau 3 năm: không cần partition/archival.
- Lưu ý ngoài phạm vi: `orders.created_at` đang là `timestamp` (giới hạn 2038). Chưa phải việc của T38, ghi nhận để Architect cân nhắc.

## 4. Thứ tự khoá và deadlock
Môi trường: `READ COMMITTED` (đã cấu hình ở `config/database.php`), nên không có gap lock đáng kể; chỉ còn khoá dòng theo PK.

Chuỗi chuẩn (khớp code hiện có `OrderFulfillmentService::apply`, `CheckoutService::prepare`, `AccountDeletionFinalizer`):
`carts (khoá theo PK, bỏ qua nếu không có) → orders (PK) → courses (tăng id) → enrollments → coupons`; `users` S (checkout, FK) không nằm trước `carts`.

Đánh giá từng luồng mới:
| Luồng | Chuỗi khoá | Vòng chờ? |
|---|---|---|
| Học sinh huỷ / hết hạn (`cancel`, `expireDue`) | `carts → orders` | Không (cùng tiền tố với checkout và markPaid) |
| Admin huỷ | `carts → orders` + INSERT `order_notes`/`audit_logs` | Không |
| Duyệt (`markPaid`) | `carts → orders → courses → enrollments → coupons`; móc `after` ghi `orders.confirmed_by`, `order_notes`, `audit_logs` | Không. FK chỉ lấy khoá S trên `orders` (đã giữ X) và `users` (staff): không có luồng nào giữ `users` staff X rồi chờ `orders`/`carts` |
| `addNote` | chỉ `orders` (hoặc chỉ INSERT, FK S trên `orders` + `users`) | Không, miễn là KHÔNG khoá `carts` sau `orders` |
| Checkout thay đơn manual (`replace_pending`) | `carts → users S → orders (đơn cũ, X) → courses S → coupons X`, rồi INSERT đơn mới | Không (giữ nguyên thứ tự hiện có) |
| Xoá tài khoản pha B (đơn `manual` bị giữ) | `carts → orders` | Không |

Điều kiện bắt buộc (đã ghi vào `docs/tech/US-022.md`):
1. Mọi luồng đọc `user_id` của đơn bằng đọc thường trước, lấy id giỏ bằng đọc thường rồi `lockForUpdate` theo PK (đúng như `apply()` đang làm). KHÔNG dùng `Cart::where('user_id')->lockForUpdate()` khi có thể không có dòng (khoá khoảng/index; `AccountDeletionFinalizer` đang làm vậy, ổn dưới RC, nhưng đừng sao chép).
2. `expireDue` lấy id bằng đọc thường (`status='pending' AND payment_method='manual' AND expires_at <= now()`), rồi MỖI ID một transaction ngắn, kiểm lại `status`/`expires_at` dưới khoá. Không khoá cả lô trong một transaction.
3. `approve` không mở transaction ngoài quanh `markPaid` (retry deadlock 1213/1205 của `markPaid` nằm ở mức transaction ngoài cùng; bọc ngoài làm retry vô hiệu). `cancel*` và `expireDue` dùng `DB::transaction($fn, 3)`.
4. `guard`/`after` được gọi lại khi retry nên phải chạy lại được (chỉ đọc/ghi DB trong transaction; mail để `DB::afterCommit` — bị huỷ khi rollback).
5. `addNote` nếu cần kiểm trạng thái thì chỉ `lockForUpdate` `orders` theo PK; không bao giờ khoá `carts` sau `orders`.
6. Đặt `after` ngay sau `transition` và trước dọn giỏ để giữ khoá `users` (staff, S) ngắn nhất; không đổi thứ tự khoá trên các bảng khác.

## 5. Chỉ mục cho truy vấn (EXPLAIN ANALYZE trên 200k đơn: 100k manual, 2000 manual pending, 500 momo pending, 400 fake)
| Truy vấn | Kế hoạch (index) | Thời gian |
|---|---|---|
| Danh sách theo ngày (30 hoặc 366 ngày) + `payment_method='manual'`, `ORDER BY created_at DESC, id DESC LIMIT 21` | range `orders_created_at_index` (reverse), lọc phương thức tại chỗ, dừng sớm khi đủ 21 dòng | 1 đến 11 ms |
| Như trên + `status IN ('paid')` | `orders_status_created_at_index` | 0,3 ms |
| Như trên, phương thức HIẾM (`fake`, 100 dòng/200k) | quét ngược `created_at` tới khi gom đủ 21 | 146 ms |
| `COUNT(*)` 30 ngày + manual | range `created_at` + lọc | 92 ms |
| `COUNT(*)` 366 ngày + manual + `status IN (...)` | table scan | 123 ms |
| Tab Chờ duyệt (không khoảng ngày) | `orders_status_created_at_index` (status='pending'), lọc `manual`, LIMIT 21 | 0,35 ms |
| `pending-count` (tất cả / sắp hết hạn) | `(status, created_at)` / `(status, expires_at)` | 10 ms với 2500 dòng pending (cực đoan) |
| `orders:expire-manual` quét | range `(status, expires_at)`, `LIMIT 500` | < 1 ms |

Thử các index ứng viên:
- `(payment_method, created_at)`: phương thức hiếm 146 ms → 0,9 ms; `COUNT` 366 ngày thành covering index (82 ms, ít I/O hơn); đa số truy vấn khác không đổi.
- `(payment_method, status, created_at)` (ADR/tech doc hỏi): **optimizer không dùng** khi `status IN (...)` + `ORDER BY created_at DESC` (vẫn chọn `orders_created_at_index` vì IN nhiều giá trị làm hỏng thứ tự); chỉ giúp tab Chờ duyệt (0,35 → 0,2 ms, không đáng). KHÔNG thêm.
- `(status, expires_at)` đã có sẵn (cho cả job hết hạn và `pending-count`). Không cần index mới cho `expire-manual`.

Quyết định: **không thêm index nào cho V1.** `orders` ~100k dòng/năm (thực tế thủ công thấp hơn), các truy vấn trên đều dưới 150 ms ở 200k dòng và có `cursorPaginate` + LIMIT. Điều kiện kích hoạt thêm `(payment_method, created_at)`: lọc theo phương thức hiếm hoặc `COUNT` có phương thức chậm hơn ~300 ms trên dữ liệu thật (đo bằng `EXPLAIN ANALYZE`); khi đó migration chỉ là `$table->index(['payment_method','created_at'])` (INPLACE, `LOCK=NONE`). Không có index trùng/thừa mới nào trong thiết kế (`orders_confirmed_by_foreign` cần cho FK).

Gợi ý truy vấn cho dev:
- `pending-count`: một câu `SELECT COUNT(*) AS total, COALESCE(SUM(expires_at < ?),0) AS soon FROM orders WHERE status='pending' AND payment_method='manual'` thay vì 2 `COUNT`.
- `COUNT(*)` tổng của danh sách (DBA #9 đã có): chỉ chạy khi client yêu cầu, không chạy ở mỗi trang cursor.
- Lọc theo ngày luôn viết `created_at >= ? AND created_at < ?` (đã đúng), không bọc `DATE()`.
- Hạn mức ngày `COUNT(*) WHERE user_id=? AND payment_method='manual' AND created_at >= ?` dùng `(user_id, created_at)`, vài dòng/HS.

## Chỉ dẫn cho dev (T38.1)
1. Migration 1: thêm 3 cột nullable (không `after`); thêm FK `confirmed_by` trong `Schema::withoutForeignKeyConstraints(fn () => Schema::table(...))` để INPLACE. `down()`: `dropForeign` rồi `dropColumn`.
2. Migration 3: câu kiểm dữ liệu dùng `BINARY payment_method NOT IN (...)`; có dòng lạ thì `throw` trước khi ALTER. Có thể bỏ migration này nếu Architect đồng ý (không bắt buộc).
3. Giữ các quy tắc khoá ở mục 4 (đặc biệt: `approve` không bọc transaction ngoài; `cancel*`/`expireDue` `DB::transaction($fn, 3)`; mỗi đơn một transaction ở job hết hạn).
4. Không thêm index mới ở V1. T24-V1: `EXPLAIN ANALYZE` lại trên dữ liệu seed 100k đơn như "Xong khi" yêu cầu; so với bảng số liệu trên.

## Kế hoạch chạy trên môi trường chung / production và rollback
1. Trước: `SELECT payment_method, COUNT(*) FROM orders GROUP BY 1` (chỉ `none`/NULL/`momo`/`fake` hợp lệ); `SELECT COUNT(*) FROM orders`.
2. Chạy migration 1 (INSTANT + FK INPLACE: không khoá), 2 (bảng mới), 3 (CHECK, COPY; giờ thấp điểm nếu bảng > vài chục nghìn dòng). Code deploy sau migration, cờ `FEATURE_MANUAL_PAYMENT` bật sau cùng.
3. Sau: `SHOW CREATE TABLE orders`, `SHOW CREATE TABLE order_notes`; `EXPLAIN` danh sách quản trị.
4. Rollback: `php artisan migrate:rollback --step=3` (drop CHECK INPLACE, drop bảng, drop FK + 3 cột); cột mới chưa có dữ liệu khi cờ còn tắt. Nếu đã có đơn `manual`, rollback trước tiên cần tắt cờ và huỷ/ghi nhận đơn `manual` (cột `confirmed_by`/`customer_note` sẽ mất dữ liệu).
