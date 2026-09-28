# T07 — Checklist DBA (docs/db/design-review.md §5, mục 1–3)

**Phạm vi:** chỉ các bảng do T07 tạo (`subjects`, `courses`, `course_subject`,
`course_teacher`, `chapters`, `video_assets`, `lessons`, `enrollments`,
`lesson_progress`). Mục 4–7 của checklist §5 (EXPLAIN ANALYZE với seed lớn,
deadlock có chủ đích cho coupon, collation subjects) áp dụng cho các bảng
chưa tồn tại ở T07 (`orders`, `quiz_attempts`, `coupons`) hoặc cần seed quy mô
lớn — để lại cho T15/T18–T20/T22 (đã ghi chú trong tasks.md).

Môi trường chạy: Claude Code on the web (không Docker), MySQL 8.0.46 cài trực
tiếp qua `scripts/cloud-setup.sh` (bản 8.0, không phải 8.4 LTS của
production/Docker local — kết quả dưới đây cần xác nhận lại trên Docker local
8.4 trước khi merge lên môi trường chung, theo lưu ý trong CLAUDE.md/nhiệm vụ).
DB test: `vitaminvui_testing_t07`.

## 1. `php artisan migrate` local → `SHOW CREATE TABLE` xác nhận generated column + CHECK

Chạy `php artisan migrate --force` (DB `vitaminvui_testing_t07`, đã tạo
collation `utf8mb4_0900_ai_ci`), sau đó `SHOW CREATE TABLE`.

**`enrollments`** — generated column STORED đúng biểu thức, unique gồm đúng cột generated:
```sql
`live_flag` tinyint GENERATED ALWAYS AS
  ((case when (`status` in (_utf8mb4'pending_approval',_utf8mb4'active')) then 1 end)) STORED,
...
UNIQUE KEY `enrollments_user_course_live_unique` (`user_id`,`course_id`,`live_flag`)
```
✅ Khớp thiết kế data-model §3.3 / design-review §2.2.

**`courses`** — CHECK có tên tường minh, hoạt động đúng:
```sql
CONSTRAINT `chk_courses_grade_level` CHECK ((`grade_level` between 6 and 12))
```
✅ `DROP CHECK chk_courses_grade_level` chạy được trong `down()` (xác nhận qua
`migrate:rollback` ở mục dưới — migration không tự xoá CHECK riêng, xoá cả
bảng bằng `dropIfExists` nên không cần `DROP CHECK` tường minh, nhưng tên vẫn
đặt đúng quy ước `chk_*` để nếu sau này có migration ALTER riêng thì `down()`
tham chiếu được).

**Phụ thuộc vòng `video_assets.lesson_id` ↔ `lessons`:** xác nhận FK thật chỉ
được thêm sau khi cả 2 bảng tồn tại (`ALTER TABLE` trong migration
`..._create_lessons_table.php`), đúng thứ tự migration data-model §5:
```sql
-- video_assets (trước khi có FK)
`lesson_id` bigint unsigned NOT NULL,
KEY `video_assets_lesson_id_index` (`lesson_id`)
-- sau migration lessons:
CONSTRAINT `video_assets_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE RESTRICT
```
✅ Không có lỗi FK "bảng chưa tồn tại" khi chạy migrate theo thứ tự.

**Migrate + rollback sạch:** `php artisan migrate:rollback --step=9` xoá đúng
9 migration T07 theo thứ tự ngược (FK `video_assets.lesson_id` được
`dropForeign` trước khi `dropIfExists('lessons')`), không lỗi FK còn treo.
✅ PASS (xem log lệnh trong phiên làm việc).

**`order_id` của `enrollments` — cố ý CHƯA có FK:** bảng `orders` thuộc T18
(sau T07 theo thứ tự migration data-model §5). Đã ghi rõ trong docblock
migration: T18 phải tự thêm FK bằng `Schema::table('enrollments', ...)` khi
tạo `orders` — KHÔNG sửa migration T07.

## 2. Test race 2 dòng "đang sống" cho enrollments (Pest, mô phỏng 2 request gần như đồng thời)

File: `tests/Feature/T07/SchemaConstraintsTest.php`.

- `unique user_id-course_id-live_flag chan 2 enrollment dang song cho cung khoa`:
  tạo 1 dòng `pending_approval`, tạo dòng thứ 2 cùng (user, course) →
  `QueryException` mã `1062 Duplicate entry` — ✅ PASS (không tạo ra 2 dòng
  "đang sống").
- `... khong chan gui lai sau khi bi tu choi` / `... khong chan mua lai sau khi bi thu hoi`:
  dòng `rejected`/`revoked` có `live_flag = NULL` → gửi lại/mua lại tạo được
  dòng mới bình thường — ✅ PASS (đúng US-012 AC5).
- Tương tự cho `lesson_progress` (U user_id+lesson_id) và `video_assets`
  (U provider+provider_video_id) — ✅ PASS.

`quiz_attempts`/`orders`/`coupons` chưa tồn tại ở T07 → phần "race N request
checkout dùng chung 1 mã giảm giá" (mục 6 gốc của checklist §5) để lại cho
T15/T18.

## 3. `SELECT @@transaction_isolation` = `READ-COMMITTED`

Đã có test từ T01 (`tests/Feature/T01/DatabaseConfigTest.php`) xác nhận
`PDO::MYSQL_ATTR_INIT_COMMAND` set đúng `READ COMMITTED` ở mức session/connection
— chạy lại cùng bộ test suite của T07 (`vendor/bin/pest -c phpunit.local.xml`)
xác nhận vẫn PASS, không bị ảnh hưởng bởi migration/model mới. Không cần thêm
test riêng cho T07 (không có bảng nào của T07 dùng nhiều SELECT thường trong
1 transaction theo kiểu rủi ro nêu ở design-review §2.1 — CRUD nội dung không
có luồng "đọc rồi khoá coupon" như đơn hàng).

## Ngoài phạm vi checklist §5 mục 1–3 (để lại cho task sau)

- Mục 4 (EXPLAIN ANALYZE với seed ≥100k đơn/1M lesson_progress): cần dữ liệu
  lớn + các bảng `orders`/query thực tế của T18/T24 — chưa áp dụng được ở T07.
- Mục 5 (CHECK `discount_value`/`JSON_LENGTH`): thuộc `coupons`/`quiz_attempts`
  (T15/T22), không tồn tại ở T07.
- Mục 6 (deadlock có chủ đích N request cùng 1 mã giảm giá): thuộc T15/T18.
- Mục 7 (test collation 2 chuyên đề có/không dấu): đã làm ở T01
  (`tests/Feature/T01/DatabaseConfigTest.php`, bảng tạm) và lặp lại trực tiếp
  trên bảng thật `subjects` ở
  `tests/Feature/T07/SchemaConstraintsTest.php::subjects.name unique bo qua dau`
  — ✅ PASS ("Hình học" vs "Hinh hoc" → `1062 Duplicate entry`, đúng US-011 BR1).
