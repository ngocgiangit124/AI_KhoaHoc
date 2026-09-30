# DB Review: T15 (mã giảm giá quản trị, US-013)

**Kết luận: PASS (kèm 2 mục MEDIUM cần xử lý trước khi gộp T18, 3 mục LOW/INFO; không có mục nào chặn gộp T15).**

Phạm vi: migration `2026_09_29_090100/090200/090300`, `Coupon`, `CouponService`,
`CouponController@index`, `CouponRequest`, `CouponUsedCountRecounter`, nhánh
`claude/zen-dirac-fmucf7-t15` (commit 51e20b8) so với base `b23efe4`.
Môi trường: MySQL 8.4.11 (Docker), DB `vitaminvui_testing_t15` (schema do Pest dựng
từ migration của nhánh). Chạy SQL trực tiếp, đã dọn sạch dữ liệu thử (coupons = 0, users = 0).
Không chạy `php artisan migrate*`, không đụng DB khác.

## 1. Schema (`SHOW CREATE TABLE` thật)
- `coupons`: kiểu cột đúng data-model §3.5. `code varchar(50) NOT NULL`, `discount_value int unsigned NOT NULL`, `max_uses int unsigned NULL`, `used_count int unsigned NOT NULL DEFAULT 0`, `valid_from datetime NOT NULL`, `valid_until datetime NULL`, `status varchar(20) NOT NULL DEFAULT 'active'`, `is_restricted tinyint(1) NOT NULL DEFAULT 0`, FK `created_by` RESTRICT, collation `utf8mb4_0900_ai_ci`. Key `code` 50 x 4 = 200 byte, không lo giới hạn 3072.
- Không có cột tiền kiểu float; VND dùng `int unsigned` (tối đa ~4,29 tỷ, đủ cho giá khóa học).
- `datetime` (không phải `timestamp`) cho `valid_from/valid_until`: đúng, không dính 2038 và không lệ thuộc time zone phiên. `created_at/updated_at` là `timestamp` do Laravel mặc định (chấp nhận, đồng bộ toàn dự án).

## 2. CHECK trên MySQL 8.4 thật (lỗi 3819)
| Trường hợp | Kết quả |
|---|---|
| percent 100, `max_uses` NULL, `valid_until` NULL | bị chặn (đúng) |
| percent 100, `max_uses`=5, `valid_until` NULL | bị chặn (đúng) |
| percent 100, `max_uses` NULL, `valid_until` có | bị chặn (đúng) |
| percent 100, `max_uses`=5, `valid_until` có | cho phép (đúng) |
| percent 0, percent 101 | bị chặn bởi `chk_coupons_percent_range` |
| percent 99 không giới hạn; fixed_amount 0; fixed_amount 100 | cho phép |
| UPDATE `max_uses` = NULL trên mã 100% | bị chặn (3819) |

Đánh giá NULL semantics: biểu thức `NOT (type='percent' AND value=100) OR (max_uses IS NOT NULL AND valid_until IS NOT NULL)` không bao giờ cho kết quả NULL vì `type`/`value` NOT NULL, và `IS NOT NULL` luôn trả 0/1; nên CHECK không bị "lọt" do NULL. Viết đúng. MySQL biên dịch lại thành dạng tương đương (đã xem trong `SHOW CREATE TABLE`).
`discount_type` và `status` không có CHECK: `INSERT ... 'xyz'` được chấp nhận ở tầng DB (xem LOW-1).

## 3. `code` unique với `utf8mb4_0900_ai_ci`
Thử thật: sau khi có `abc`, chèn `ÀBC` -> 1062 (trùng); `ĐABC` chèn 2 lần -> 1062; `WHERE code='dabc'` khớp `ĐABC` (đ = d trong 0900_ai_ci). `ABC ` (thừa dấu cách cuối) được coi là KHÁC `abc` vì 0900 là NO PAD.
Đánh giá: với mã giảm giá, không phân biệt hoa/thường là mong muốn (BR1). Việc gộp dấu là vô hại vì `CouponRequest` chỉ nhận `^[A-Z0-9_-]+$` sau `mb_strtoupper(trim())`, nên ký tự có dấu và dấu cách không thể vào từ API. Unique DB là lưới an toàn thứ hai đúng ý. Không cần đổi collation.
Lưu ý cho T16/T18: tra mã người dùng nhập phải `trim` + không dùng `BINARY`/`COLLATE` khác, để giữ index unique (`WHERE code = ?` sẽ `const` lookup).

## 4. Index cho truy vấn admin
`index()` chạy `ORDER BY id DESC LIMIT n` + filter `state` + `paginate` (COUNT). Hiện không có search theo code (api-contract §2.5 chỉ có filter `state`).
EXPLAIN (bảng 8 dòng, planner chọn PK backward scan là hợp lý):
- Không filter: `PRIMARY`, `Backward index scan`, không filesort.
- `state=inactive|expired|exhausted|active`: `possible_keys = coupons_status_valid_until_index` nhưng chọn `PRIMARY` + `Using where` (không filesort). Với vài nghìn mã, quét PK ngược cũng rẻ.
- `code LIKE 'AB%'`: `range` trên `coupons_code_unique` (nếu sau này thêm search tiền tố thì đã có index).
Bảng coupons nhỏ (data-model §2: "nhỏ", tối đa vài nghìn), nên không cần thêm index. Index `(status, valid_until)` gần như không mang lại lợi ích (đặc thù lọc thấp, `exhausted` so sánh 2 cột) nhưng chi phí ghi không đáng kể; INFO-1: giữ được, T18 (tra mã hợp lệ theo `code`) mới là truy vấn nóng và đã được unique `code` phục vụ.
Ghi chú: `state` dùng `now()` trong mệnh đề `valid_until < now()`: cột không bị bọc hàm, sargable.

## 5. Pivot `coupon_course`, `coupon_subject`
- PK `(coupon_id, X_id)` + index phụ `X_id`; FK `coupon_id` CASCADE, `course_id`/`subject_id` RESTRICT (đã thấy đúng trong `SHOW CREATE TABLE coupon_course`). Đúng đề bài; index phụ `X_id` là bắt buộc cho kiểm FK RESTRICT khi xoá course/subject và cho truy vấn ngược (T16/T18: mã nào áp cho khóa X).
- `sync()` xoá + chèn theo lô nhỏ; danh sách ID từ request có `Rule::exists` cho từng phần tử (N truy vấn nhỏ, chấp nhận với admin, LOW-2 dưới đây nếu `course_ids` dài).
- `down()`: `dropIfExists` từng bảng theo thứ tự ngược của migration (pivot trước, `coupons` sau); CHECK bị xoá cùng bảng nên không cần `DROP CHECK` riêng. Rollback sạch.

## 6. `used_count` là counter nóng: đề xuất cho T16/T18
T15 chỉ đọc `used_count` (mặc định 0), chưa có luồng tăng. Yêu cầu cho T18 (khớp data-model §6/ADR-001 §6):
1. Tăng nguyên tử, ưu tiên cách một câu lệnh:
   ```sql
   UPDATE coupons SET used_count = used_count + 1
   WHERE id = ? AND status = 'active' AND used_count < COALESCE(max_uses, 4294967295)
     AND valid_from <= NOW() AND (valid_until IS NULL OR valid_until >= NOW());
   -- affected rows = 0 -> hết lượt/hết hạn/inactive -> lỗi nghiệp vụ, rollback
   ```
   (Đã thử `UPDATE ... WHERE used_count < max_uses` trên MySQL 8.4: ROW_COUNT = 1 khi còn chỗ; `used_count < NULL` là NULL nên phải dùng `COALESCE` hoặc `(max_uses IS NULL OR used_count < max_uses)` cho mã không giới hạn.) Trúng PK nên khoá đúng 1 dòng, không gap lock.
2. Tuân thủ thứ tự khoá data-model §6 (`orders -> ... -> coupons/coupon_usages -> courses`); `UPDATE coupons` chỉ ở cuối transaction fulfillment, giữ transaction ngắn để hạn chế hot row khi nhiều HS cùng mã.
3. Dùng `INSERT coupon_usages` (unique `coupon_id, user_id` và `order_id`) cùng transaction, bắt 1062 để idempotent với IPN gọi lại; không tăng `used_count` nếu insert usage trùng.
4. Sức chứa lúc tạo đơn = `used_count` + số đơn pending còn `coupon_hold_until > now` (ADR-001 §6): truy vấn đếm này cần index `orders (coupon_id, status)` đã có trong data-model.
5. Lệnh backup/recount xem mục MEDIUM-1 dưới.

## 7. `CouponUsedCountRecounter`
- Bỏ qua khi thiếu `coupon_usages` qua `Schema::hasTable`: an toàn và đúng. Yêu cầu cho T18: bảng `coupon_usages` PHẢI có cột `coupon_id` (và unique `(coupon_id, user_id)`, `order_id` theo data-model); recounter `GROUP BY coupon_id`, nên nên có index bắt đầu bằng `coupon_id` (unique `(coupon_id, user_id)` đã đủ, covering, `Using index`).
- Nối vào `counters:recount`: chưa thể kiểm (lệnh do T14 tạo song song, không có trong nhánh). Việc gọi `app(CouponUsedCountRecounter::class)->recount()` trong `handle()` là hợp lý; T14/T18 cần nhớ làm và có test. INFO-2: ghi việc này vào board để không mất.
- MEDIUM-1 (race, chỉ có hại khi T18 bật): `recount()` lấy snapshot `COUNT(*)` toàn bảng rồi mới lần lượt đọc-so-sánh-ghi từng dòng bằng `$coupon->save()`. Giữa hai bước, một đơn thanh toán có thể commit `used_count+1` và `coupon_usages` mới: recounter ghi đè về số ĐẾM CŨ (thấp hơn thật) -> mã bị coi là còn chỗ, có thể vượt `max_uses`; đây đúng loại sai lệch cần tránh với mã 100%. Đề xuất sửa trước khi T18 gộp (đề xuất để `laravel-dev`, không sửa trong review này): mỗi dòng một câu lệnh nguyên tử, tự tính lại trong SQL:
  ```sql
  UPDATE coupons c
  SET c.used_count = (SELECT COUNT(*) FROM coupon_usages u WHERE u.coupon_id = c.id)
  WHERE c.id BETWEEN ? AND ?          -- chunk theo id
    AND c.used_count <> (SELECT COUNT(*) FROM coupon_usages u WHERE u.coupon_id = c.id);
  ```
  (hoặc `UPDATE ... JOIN` với subquery đếm). Vì `COUNT` và `UPDATE` cùng một câu lệnh, dòng bị khoá khi ghi, race gần như biến mất; và không cần nạp toàn bộ bản đồ vào PHP. Ghi nhật ký (log) các dòng lệch để cảnh báo.
- Phụ: dùng `save()` làm đổi `updated_at`; cột chỉ để đối soát nên chấp nhận được, nhưng câu SQL trên tránh được.

## 8. Phát hiện tổng hợp
- **MEDIUM-1**: Recounter đọc-rồi-ghi không nguyên tử, xem mục 7. Chặn T18 nhưng không chặn T15 (hiện `coupon_usages` chưa tồn tại nên nhánh không chạy).
- **MEDIUM-2**: `CouponService::update()`/`delete()` kiểm `used_count > 0` trên model đã nạp, không khoá. Một IPN thanh toán có thể tăng `used_count` giữa lúc admin đổi `discount_value`/xoá (TOCTOU), khiến mã đã dùng bị đổi giá trị. Sửa: trong `DB::transaction`, `Coupon::lockForUpdate()->findOrFail($id)` rồi mới kiểm (khoá theo PK, chỉ 1 dòng, đúng quy tắc "khoá dòng chắc chắn tồn tại" của data-model §6). Ngoài ra api-contract nói xoá "Chỉ khi chưa có order tham chiếu", nhưng code chỉ kiểm `used_count`: đơn `pending` đang giữ chỗ mã (`orders.coupon_id`) chưa tăng `used_count`; khi T18 có FK `orders.coupon_id` RESTRICT, xoá sẽ vỡ ra lỗi 1451 (500). T18 cần thêm `orders.exists(coupon_id)` hoặc bắt 1451 thành 409 `COUPON_IN_USE`.
- **LOW-1**: `discount_type`, `status` là `varchar` không CHECK (chèn 'xyz' được ở tầng DB). Enum PHP chặn ở app, còn migration Laravel không tạo ENUM để dễ mở rộng; có thể thêm `CHECK (discount_type IN ('percent','fixed_amount'))` nếu muốn, không bắt buộc.
- **LOW-2**: `CouponRequest` chưa chặn hạ `max_uses` xuống dưới `used_count` (mã thành "exhausted" ngay). Có thể chấp nhận (admin chủ ý đóng mã) nhưng nên thống nhất với PO; DB không cần đổi.
- **LOW-3**: `state` filter + `paginate()` dùng `COUNT(*)`; bảng nhỏ nên không sao. Nếu list dài, chuyển `simplePaginate`.
- **INFO-1**: Chưa cần thêm index nào; xem mục 4. **INFO-2**: theo dõi nối `counters:recount` (T14/T18). **INFO-3**: `state=active` không lọc `valid_from > now()` (mã chưa tới ngày bắt đầu vẫn hiện "active"). Có thể là quy ước UI; báo `laravel-dev` xác nhận.

## 9. Checklist DBA
- design-review.md §5 mục 5 (CHECK 3819): ĐẠT cho `chk_coupons_percent_range` và `chk_coupons_full_discount_limited` (đã ghi vào `docs/db/design-review.md`).
- design-review.md §5 mục 7 (collation, unique có/không dấu): ĐẠT cho `coupons.code`.
- design-review.md §5 mục 6 (race `max_uses`, deadlock): CHƯA làm, chờ T16/T18.
- data-model §5 (CHECK có tên tường minh, `down()` sạch): ĐẠT.
- S18 (mã 100% bắt buộc `max_uses` + `valid_until`): ĐẠT ở tầng DB (kèm kiểm ở `CouponRequest`). Phần fixed_amount >= giá khóa rẻ nhất chỉ có ở app, đúng như data-model.

## 10. Kiểm chứng/rollback
Chỉ thay đổi DDL tạo bảng mới (không khoá bảng hiện có), `CREATE TABLE` tức thời. Rollback: `php artisan migrate:rollback --step=3` (pivot rồi `coupons`). Không có dữ liệu cần backfill.
