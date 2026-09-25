# DB: Review thiết kế CSDL — VitaminVui MVP (US-001 → US-014)

**Loại tài liệu:** Review thiết kế (chưa có DB thật, chưa có code) — không thay thế `docs/architecture/data-model.md`, chỉ bổ sung góc nhìn DBA/MySQL.
**Stack xác nhận:** Laravel 11 / PHP 8.3 / **MySQL 8.0.16+ (khuyến nghị 8.4 LTS), InnoDB** — theo CLAUDE.md và `docs/architecture/README.md` dòng 7 (không phải SQL Server).
**Tài liệu đã đọc:** `docs/architecture/data-model.md`, `docs/architecture/README.md`, `docs/architecture/tasks.md`, `docs/adr/ADR-001..004`, `docs/stories/US-010`, `docs/design/mockups/US-010-ds-don-hang.html`.
**Kết luận chung:** Thiết kế hiện tại đã áp dụng đúng phần lớn kỹ thuật MySQL 8/InnoDB nâng cao (generated column STORED thay filtered index, `binlog_format=ROW`, không dùng `ENUM`, thứ tự khoá tường minh, `JSON_SET` nguyên tử). Không có lỗi nghiêm trọng chặn triển khai. Có **10 điểm cần Architect sửa/làm rõ trước khi Dev bắt đầu** (mục 3) và một số đề xuất bổ sung.

---

## 1. Kết luận nhanh cho 9 điểm Architect yêu cầu xác nhận

| # | Chủ đề | Kết luận |
|---|---|---|
| 1 | `READ COMMITTED` thay `REPEATABLE READ` | **Đồng ý về nguyên tắc**, nhưng cách cấu hình ghi trong data-model (`'isolation_level' => 'READ COMMITTED'`) **không phải khoá hợp lệ** cho driver `mysql` của Laravel — cần sửa cách hiện thực (mục 2.1) |
| 2 | Generated column + unique cho "1 bản ghi đang hoạt động" | **Đồng ý, thiết kế đúng chuẩn MySQL 8** — không cần sửa nội dung, chỉ góp ý nhỏ về `VIRTUAL` vs `STORED` (mục 2.2) |
| 3 | Thứ tự khoá checkout/IPN/đối soát/huỷ đơn | **Đồng ý về bản chất, không có chu trình deadlock thật**, nhưng phát hiện **mâu thuẫn giữa 2 tài liệu** (data-model §4 vs ADR-001 §4) về thứ tự `coupons` so với `enrollments` — cần thống nhất lại mô tả (mục 2.3) |
| 4 | Index `orders` + `payment_attempts (status, next_check_at)` | **Đồng ý bộ index hiện có**; đề xuất thêm chiến lược cho ô tìm kiếm tự do (mã đơn/tên HS) và quyết định phân trang OFFSET vs cursor trước khi Dev code (mục 2.4) |
| 5 | `lesson_progress` (30M dòng/3 năm, ~50 ghi/giây) | **Đồng ý, không cần partition ở MVP**; 50 ghi/giây là tải rất nhẹ cho InnoDB; ước lượng dung lượng ~10–15 GB sau 3 năm — cần theo dõi thực tế, không cần hành động ngay (mục 2.5) |
| 6 | `JSON_SET` autosave + giới hạn JSON | **Đồng ý cú pháp**; đề xuất thêm `CHECK (JSON_LENGTH(...))` phòng vệ ở DB và cân nhắc `binlog_row_value_options=PARTIAL_JSON` (mục 2.6) |
| 7 | Lưu trữ/dọn log IPN, đối soát, file export | **Đồng ý chủ trương giữ 24 tháng**, nhưng **chưa có cron job/task tương ứng** trong `tasks.md`/README §4 — cần bổ sung; phát hiện index `(gateway, received_at)` gần như vô ích ở MVP (mục 2.7) |
| 8 | Collation utf8mb4, tìm kiếm có/không dấu, unique | **Đồng ý cách tiếp cận `search_text` chuẩn hoá ở PHP**; đề xuất cân nhắc đổi `utf8mb4_unicode_ci` → `utf8mb4_0900_ai_ci` **trước khi migrate lần đầu** (đổi sau sẽ tốn kém) (mục 2.8) |
| 9 | Kiểu tiền, timezone, thứ tự migration | **Đồng ý toàn bộ** (`int unsigned` VNĐ, `datetime` + connection `timezone=+07:00`, thứ tự migration không vi phạm FK); đề xuất thêm CHECK cho `coupons.discount_value` theo `discount_type` (mục 2.9) |

---

## 2. Phân tích chi tiết

### 2.1 Isolation level `READ COMMITTED`

**Cơ chế MySQL/InnoDB liên quan (để Dev hiểu rõ khi code các luồng tiền):**
- Dưới `REPEATABLE READ` (mặc định InnoDB), **câu SELECT thường** (non-locking) đầu tiên trong một transaction "đóng băng" một *read view* (snapshot); mọi SELECT thường sau đó trong cùng transaction đọc lại đúng snapshot đó, kể cả khi có transaction khác đã COMMIT dữ liệu mới. Ngược lại, **locking read** (`SELECT ... FOR UPDATE` / `LOCK IN SHARE MODE`, tức `lockForUpdate()`/`sharedLock()` trong Eloquent) **luôn đọc bản mới nhất đã commit**, bất kể isolation level — đây là điểm hay bị hiểu nhầm.
- Trong luồng checkout (ADR-001 §4): `BEGIN` → `SELECT carts FOR UPDATE` → **tìm đơn pending cũ bằng đọc thường** → `coupons FOR UPDATE` → đếm "số đơn pending đang giữ mã" (đọc thường). Vì đã có 1 SELECT thường trước đó trong transaction (tìm đơn pending cũ), read view đã "chốt" từ thời điểm đó dưới `REPEATABLE READ` → câu đếm sức chứa mã sau này có thể **không thấy** đơn pending vừa được HS khác commit ngay trước đó → vượt `max_uses`. Đây đúng là rủi ro thật, không phải lý thuyết suông.
- Với `INSERT` vào các cột có unique index (đặc biệt `orders(user_id, pending_flag)`, `enrollments(user_id, course_id, live_flag)`), `REPEATABLE READ` khiến InnoDB đặt gap lock/next-key lock để kiểm tra trùng khoá trên "khoảng trống" xung quanh giá trị sắp insert — 2 HS khác nhau insert gần như đồng thời (dù giá trị unique khác nhau) có thể chờ nhau theo thứ tự không xác định → deadlock. `READ COMMITTED` giảm mạnh việc dùng gap lock (chỉ giữ record lock, trừ khi kiểm tra duplicate-key/FK) → giảm loại deadlock này, **nhưng không loại bỏ hoàn toàn** (vẫn có gap lock khi kiểm tra FK/duplicate key) → vẫn cần retry.

**Kết luận:** đồng ý đổi sang `READ COMMITTED`, nhưng:

1. **Cách cấu hình trong data-model sai** — Laravel không có khoá config `isolation_level` cho driver `mysql` (khoá đó chỉ tồn tại cho driver `sqlsrv`). Cách đúng là chạy `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` ngay khi PDO mở kết nối:

```php
// config/database.php — connection 'mysql'
'mysql' => [
    // ...
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
    ]) : [],
],
```

2. **Phạm vi áp dụng: session/connection-level (toàn bộ connection `mysql`), không phải theo từng transaction.** Lý do: luồng checkout đã có 1 SELECT thường *trước* bước khoá coupon, nên nếu chỉ set isolation ngay trước `DB::transaction()` của riêng đoạn tính sức chứa thì đã quá muộn — snapshot đã chốt từ SELECT trước đó nếu code không set từ đầu transaction lớn hơn. Set ở mức session (mỗi kết nối PDO khi được mở) là cách chắc chắn nhất, không phụ thuộc thứ tự code.

3. **Phương án thay thế nếu muốn thu hẹp phạm vi ảnh hưởng** (giữ `REPEATABLE READ` mặc định cho phần còn lại của app, chỉ đổi cho các luồng tiền): gọi `DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')` **ngay trước khi mở transaction kế tiếp** (MySQL chỉ cho phép set trước `BEGIN`, áp dụng đúng 1 transaction rồi tự trả về mức mặc định). Nhược điểm: phải tự quản lý `DB::beginTransaction()/commit()/rollBack()` thủ công thay vì dùng closure `DB::transaction()`, dễ quên ở code mới thêm sau này (rủi ro con người cao hơn lợi ích thu hẹp phạm vi). **Khuyến nghị chọn phương án global (session-level)**.

4. **Phương án thay thế khác, không đổi isolation:** chỉ sửa riêng câu "đếm số đơn pending đang giữ mã" thành **locking read** (`Order::where('coupon_id', $id)->where('pending_flag', 1)->lockForUpdate()->get()` hoặc `sharedLock()`), vì locking read luôn đọc dữ liệu mới nhất bất kể isolation level. Cách này không đổi hành vi toàn ứng dụng nhưng chỉ giải quyết đúng 1 điểm rủi ro đã biết — nếu tương lai có thêm luồng đọc-sửa-đọc tương tự thì phải nhớ áp dụng lại thủ công. **Không khuyến nghị làm phương án chính**, chỉ nêu để Architect cân nhắc nếu ngại đổi isolation toàn app.

5. **Rủi ro cần test khi đổi toàn cục:** mọi đoạn code (hiện tại và tương lai) thực hiện **nhiều SELECT thường trong cùng 1 transaction và kỳ vọng chúng nhất quán với nhau** (transaction-level snapshot) sẽ đổi hành vi — mỗi SELECT giờ đọc bản mới nhất tại thời điểm chạy, có thể thấy dữ liệu khác nhau giữa 2 lần đọc cùng transaction. Rà soát hiện tại trong data-model **không thấy** pattern này (các luồng tiền đều dùng locking read đúng chỗ cần), nhưng cần lưu ý khi review code T18–T20, T22.
6. Bắt buộc giữ `binlog_format=ROW` (đã ghi đúng trong data-model §6) — statement-based replication với `READ COMMITTED` không an toàn (dữ liệu có thể lệch giữa primary/replica).

### 2.2 Generated column STORED + unique index

Đồng ý hoàn toàn — đây là cách chuẩn để mô phỏng "filtered/partial unique index" trên MySQL (MySQL không có tính năng này như SQL Server/Postgres). Cú pháp minh hoạ (Laravel 11 Schema Builder):

```php
// database/migrations/..._create_enrollments_table.php
Schema::create('enrollments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->foreignId('course_id')->constrained();
    $table->string('status', 20);
    // ... các cột khác

    // generated column STORED — NULL khi không cần duy nhất
    $table->tinyInteger('live_flag')->nullable()->storedAs(
        "CASE WHEN status IN ('pending_approval','active') THEN 1 END"
    );

    $table->unique(['user_id', 'course_id', 'live_flag'], 'enrollments_user_course_live_unique');
});
```

Tương tự cho `orders.pending_flag` (`CASE WHEN status = 'pending' THEN 1 END`, unique `(user_id, pending_flag)`) và `quiz_attempts.in_progress_flag` (`CASE WHEN submitted_at IS NULL THEN 1 END`, unique `(user_id, quiz_id, in_progress_flag)`).

**Góp ý nhỏ (không bắt buộc sửa):** MySQL 8 InnoDB cho phép đánh index trên **generated column VIRTUAL** (không chỉ STORED) từ 5.7.6+. Với các cột này chỉ dùng để phục vụ unique index (không bao giờ SELECT trực tiếp), dùng `virtualAs()` thay `storedAs()` sẽ **không tốn thêm byte lưu trữ trên mỗi dòng** (giá trị chỉ tính khi cần cho index), trong khi chi phí ghi (tính giá trị khi INSERT/UPDATE) là như nhau giữa STORED và VIRTUAL khi có index. Vì các bảng này (`enrollments`, `orders`, `quiz_attempts`) không thuộc nhóm ghi cực nhiều nên khác biệt không đáng kể — **giữ STORED như đã thiết kế cũng hoàn toàn ổn**, chỉ nêu để Dev biết có lựa chọn khác nếu sau này cần tối ưu dung lượng.

**Điểm cần bổ sung (không phải sửa lỗi):** cơ chế chống race chỉ đúng khi **mọi đường ghi dữ liệu đều đi qua đúng logic chuyển trạng thái** (ví dụ `EnrollmentService`, không có chỗ nào `Enrollment::insert()` thô hoặc sửa `status` trực tiếp bằng raw query bỏ qua Model/Service). Đây là việc `laravel-dev` cần tuân thủ khi code, không phải vấn đề CSDL.

### 2.3 Thứ tự khoá & deadlock (checkout / IPN / đối soát / huỷ đơn 12h)

**Xác nhận không có chu trình deadlock thật** giữa các bảng bị khoá: `carts` và `orders` bị khoá theo user cụ thể của từng transaction (không bao giờ 2 transaction khác nhau tranh cùng 1 dòng `carts`/`orders` — trừ trường hợp 2 tab của **cùng 1 học sinh**, lúc đó đúng là chờ nhau tuần tự, đó là mục đích thiết kế, không phải bug). Tài nguyên **dùng chung giữa nhiều học sinh khác nhau** chỉ có 2 loại: dòng `coupons` (khi nhiều HS cùng dùng 1 mã) và dòng `courses` (đếm `enrollments_count` khi nhiều HS mua cùng 1 khoá). Rà lại toàn bộ các luồng chạm 2 tài nguyên này:

- Checkout (ADR-001 §4): `carts → orders(read+lock nếu có) → coupons FOR UPDATE`. **Không chạm `courses`.**
- IPN/đối soát "Succeeded" (ADR-001 §4, đoạn xử lý sau khi khoá `attempt`): `carts → orders → attempt → enrollments (UPDATE) → coupon_usages/coupons.used_count (UPDATE) → courses.enrollments_count (UPDATE)`.
- Duyệt học miễn phí (US-012): chỉ chạm `enrollments → courses`, không chạm `coupons`.
- Huỷ đơn 12h (ADR-001 §7): `carts → orders (→ attempts nếu cần set expired)`, không chạm `coupons`/`courses`.

→ Vì không có transaction nào khoá `courses` trước rồi khoá `coupons` sau (chỉ tồn tại chiều `coupons → courses` trong luồng IPN, và `enrollments → courses` ở luồng duyệt miễn phí, không giao nhau ngược chiều), **không có chu trình chờ vòng tròn** giữa các dòng bị nhiều transaction tranh chấp. Kết luận: thiết kế an toàn về mặt logic khoá.

**Tuy nhiên phát hiện 1 điểm cần Architect thống nhất lại (không phải lỗi logic, mà là tài liệu tự mâu thuẫn, dễ gây nhầm cho Dev sau này khi thêm tính năng mới):**
- `data-model.md` §4 tóm tắt: *"Thứ tự khoá thống nhất: `carts` → `orders` → `payment_attempts` → `coupons` → `enrollments`/`courses`"* (coupons **trước** enrollments).
- `ADR-001` §4 (chi tiết luồng IPN "Succeeded") lại viết theo thứ tự: `attempt` → **`enrollments` active** → **`coupon_usages`/`coupons.used_count`** → `courses.enrollments_count` (enrollments **trước** coupons).

Hiện tại 2 thứ tự này không tạo ra deadlock thật (vì không có transaction nào đi ngược chiều — coupons và enrollments không bị 2 luồng khác nhau khoá theo 2 chiều ngược nhau), nhưng nếu **sau này** có thêm một luồng mới chạm cả `coupons` và `enrollments` theo chiều ngược lại với 1 trong 2 tài liệu trên (rất dễ xảy ra nếu Dev đọc nhầm tài liệu), sẽ tạo chu trình chờ vòng tròn thật. Đề xuất: Architect chọn **1 thứ tự chuẩn duy nhất** (khuyến nghị giữ đúng như code thực tế sẽ chạy — `enrollments` trước `coupons` trong luồng fulfillment, vì `enrollments` phản ánh quyền truy cập nên cần cấp sớm) và sửa câu tóm tắt ở data-model §4 cho khớp với ADR-001 §4.

**Rủi ro còn lại cần lưu ý khi vận hành:**
- Dòng `coupons` là **hot row** khi chạy chiến dịch mã giảm giá lớn (tải đỉnh ×5 theo ước lượng data-model §2) — mọi checkout dùng cùng mã sẽ tuần tự hoá qua khoá dòng này, giới hạn thông lượng theo tốc độ 1 transaction hoàn tất checkout, không phải theo CPU. Với ~275 đơn/ngày đỉnh, chia đều không đáng lo, nhưng nếu marketing tạo 1 mã "giảm 50% toàn site" chạy trong khung giờ ngắn (flash sale) thì cần **load test riêng kịch bản N học sinh cùng dùng 1 mã** trước khi launch campaign.
- Tương tự, `courses.enrollments_count` là hot row cho khoá bán chạy.
- Cả 2 rủi ro trên đã được giảm nhẹ đúng cách bằng `DB::transaction($fn, 3)` (tự retry khi deadlock/lock wait) — xác nhận cách này phù hợp; khuyến nghị thêm log cảnh báo khi retry hết 3 lần vẫn lỗi (tránh 500 âm thầm cho HS mà không ai biết).

**Đề xuất bổ sung (khoảng trống phát hiện thêm):** `courses:recount-enrollments` (cron hằng ngày) đối soát lại `courses.enrollments_count`, nhưng **không có job tương tự cho `coupons.used_count`** dù cơ chế tăng đếm này cũng có thể bỏ sót ở các nhánh `needs_review` (unique `coupon_usages` vi phạm → bỏ qua tăng đếm theo đúng thiết kế, nhưng cần một nguồn sự thật để đối chiếu định kỳ). Đề xuất thêm cron `coupons:recount-used` (hoặc gộp vào `courses:recount-enrollments` thành 1 job "recount denormalized counters") chạy hằng ngày: `UPDATE coupons c SET used_count = (SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = c.id) WHERE c.status = 'active'` (chạy theo lô nếu số coupon lớn — thực tế bảng `coupons` nhỏ nên không cần).

### 2.4 Index `orders` cho lọc/xuất/danh sách quản trị; `payment_attempts (status, next_check_at)`

**Bộ index hiện có trên `orders` — xác nhận đúng và đủ cho các truy vấn đã liệt kê:**
- `U(user_id, pending_flag)` — chống trùng đơn pending, đã bàn ở mục 2.2.
- `IX(status, created_at)` — phục vụ lọc theo trạng thái + sắp xếp/khoảng ngày trong cùng trạng thái (US-010 AC2, tab theo trạng thái).
- `IX(created_at)` — phục vụ lọc **chỉ** theo khoảng ngày (không chọn trạng thái, ví dụ "Tất cả"), vì MySQL 8.0 (< 8.0.31) **không hỗ trợ index skip scan** nên không thể tái dùng `IX(status, created_at)` hiệu quả khi không có điều kiện `status`. Nếu server chạy ≥ 8.0.31/8.4 LTS có Skip Scan, index này vẫn nên giữ làm phương án an toàn (skip scan phụ thuộc cost-based optimizer, không đảm bảo luôn được chọn).
- `IX(user_id, created_at)` — lịch sử đơn của 1 học sinh (US-005 AC7) và lọc admin theo học sinh cụ thể (nếu filter bằng `user_id`).
- `IX(coupon_id, status)` — đếm sức chứa mã giảm giá (mục 2.1/2.3).
- `IX(status, expires_at)` — job huỷ đơn 12h.
- `IX(needs_review, created_at)` — danh sách cần admin chú ý.

**Đề xuất cho ô tìm kiếm tự do "mã đơn / tên học sinh" (US-010 mockup `US-010-ds-don-hang.html`, dòng 45):** đây là truy vấn khó tối ưu bằng index vì kết hợp OR giữa 2 nguồn (mã đơn thuộc `orders`, tên thuộc `users` qua join) và `LIKE '%...%'` trên tên không thể dùng index (bất kể B-tree hay collation nào). Đề xuất chiến lược ở tầng Query (`OrderFilterQuery`), không cần index mới:
1. Luôn áp dụng filter ngày/trạng thái **trước** (dùng index có sẵn) để thu hẹp tập kết quả.
2. Nếu chuỗi tìm kiếm khớp định dạng mã đơn (`VV` + 6 số + ...), ưu tiên tra theo `orders.code` (unique, tra cứu tức thời) thay vì join.
3. Nếu không khớp định dạng mã đơn, coi là tìm theo tên: `JOIN users ON users.id = orders.user_id AND users.name LIKE ?` — chấp nhận scan trong phạm vi đã bị thu hẹp bởi (1); vì đây là công cụ vận hành nội bộ (admin/quản lý trang), tần suất gọi thấp, scan vài nghìn–chục nghìn dòng trong 1 khoảng ngày là chấp nhận được. **Không cần FULLTEXT index** ở quy mô này.
4. Khi Dev viết xong, chạy `EXPLAIN`/`EXPLAIN ANALYZE` với dữ liệu seed lớn để xác nhận filter ngày/trạng thái được dùng làm driving predicate (xem checklist mục 5).

**Đề xuất quyết định trước khi code (ảnh hưởng UI, cần Architect + Designer chốt):** README §6 đã tự đặt câu hỏi mở "trang sâu → DBA xem có cần `cursorPaginate`". Khuyến nghị: **dùng `cursorPaginate()` (keyset pagination theo `created_at, id`) ngay từ đầu** cho danh sách đơn quản trị, thay vì OFFSET, vì:
- Đơn hàng sẽ đạt 700k–1M dòng sau 3 năm; `OFFSET` lớn buộc MySQL phải duyệt và bỏ qua N dòng đầu trước khi trả kết quả (chi phí tăng tuyến tính theo số trang, không phải hằng số).
- Laravel hỗ trợ sẵn `cursorPaginate()` từ 8.x, tương thích tốt với composite index `(status, created_at)`/`(created_at)` đã có (thêm `id` làm tie-breaker: `orderByDesc('created_at')->orderByDesc('id')`).
- Đánh đổi: UI không hiển thị được "nhảy tới trang N", chỉ có Tiếp/Trước — **cần Designer xác nhận vì mockup hiện chưa có control phân trang cụ thể**. Nếu UI bắt buộc phải có số trang, chấp nhận OFFSET nhưng **giới hạn độ sâu** (ví dụ chặn `page > 500` ở API, trả lỗi gợi ý thu hẹp bộ lọc ngày) làm lưới an toàn.

**`payment_attempts (status, next_check_at)`:** xác nhận đúng — truy vấn `payments:reconcile` là `WHERE status = 'pending' AND next_check_at <= NOW() ORDER BY next_check_at LIMIT :batch_size`, index composite này cho index seek + order-by tận dụng index (không cần filesort). `IX(status, created_at)` không cần thêm cho bảng này vì không thấy truy vấn tương ứng trong thiết kế.

### 2.5 `lesson_progress` — thiết kế ghi tần suất cao

**Xác nhận tải ghi không phải vấn đề:** 50 UPDATE/giây (heartbeat) là tải rất nhẹ đối với InnoDB (mỗi UPDATE là 1 lần tra PK/unique `(user_id, lesson_id)` rồi ghi tại chỗ) — một server MySQL tầm trung xử lý được hàng nghìn transaction nhỏ/giây. Không cần thay đổi thiết kế vì lý do thông lượng.

**Ước lượng dung lượng (tham khảo, cần đo lại khi có dữ liệu thật):** mỗi dòng ~ id(8) + 3×FK bigint(24) + 2×int(8) + status varchar ngắn (~5) + 5 cột datetime (~25) + overhead InnoDB (~15–20) ≈ **90–110 byte/dòng dữ liệu**, cộng 3 index phụ (`U(user_id,lesson_id)`, `IX(user_id,course_id,status)`, `IX(user_id,course_id,last_accessed_at)`) mỗi index leaf ghi lại cột khoá + PK (~30–40 byte/dòng/index) → tổng ước lượng **~250–300 byte/dòng kể cả index**. Với 30 triệu dòng sau 3 năm → **~7–9 GB** cho bảng + index (chưa tính buffer pool/undo log tạm thời khi ghi). Đây là quy mô hoàn toàn trong khả năng InnoDB trên phần cứng thông thường, **không cần partition ở MVP**.

**Về việc có cần partition không (trả lời trực tiếp câu hỏi của Architect):** **không cần** ở quy mô 30M dòng/3 năm nếu index đúng như thiết kế. Chỉ nên cân nhắc partition nếu sau này:
- Cần **xoá/archive theo lô rẻ** dữ liệu tiến độ của các năm học cũ (partition RANGE theo `created_at`, dùng `DROP PARTITION` thay vì `DELETE` — nhanh, không sinh undo log lớn).
- Lưu ý MySQL bắt buộc: **mọi unique key (kể cả PRIMARY KEY) của bảng partition phải chứa cột dùng để partition**. Với `lesson_progress` hiện có PK `(id)` và unique `(user_id, lesson_id)` không chứa `created_at`, nếu sau này muốn partition theo `created_at` sẽ phải đổi PK thành `(id, created_at)` — là một migration cấu trúc không nhỏ. Nếu nhìn trước khả năng cần archive theo năm học, nên quyết định sớm hơn (ví dụ giữ nguyên MVP, nhưng ghi chú kỹ thuật rằng "partition sau này cần đổi PK" vào data-model để Dev không ngạc nhiên).

**Về việc có cần bảng tổng hợp không:** data-model §3.3 đã chủ động **không denormalize** % tiến độ (tính khi đọc bằng `COUNT`/`GROUP BY`, giới hạn ≤ 12 khoá/trang). Đồng ý với quyết định này cho MVP — số dòng cần quét cho 1 trang "Khoá học của tôi" bị chặn trên bởi (≤12 khoá × số bài/khoá), không phụ thuộc tổng 30M dòng nhờ index `(user_id, course_id, status)`. Đây là **ứng viên đầu tiên** nên chuyển sang bảng tổng hợp (`enrollment_progress_summary` cập nhật khi heartbeat chuyển `completed`, hoặc job tổng hợp định kỳ) **nếu sau go-live đo được p95 latency trang "Khoá học của tôi" vượt ngưỡng chấp nhận** — đề xuất ghi việc này vào rủi ro R8 (README §7) như một mốc theo dõi cụ thể thay vì để chung chung.

**Góp ý kỹ thuật nhỏ cho `ProgressService::heartbeat` (ADR-002 §4, ảnh hưởng tới Dev, không phải schema):** logic tính `credited = min(watchedDelta, elapsed*2+5)` cần đọc `last_heartbeat_at`/`watched_seconds` hiện tại rồi mới UPDATE (không phải 1 câu `UPDATE ... SET x = x + ?` thuần tuý) — đề xuất dùng `lockForUpdate()` khi SELECT dòng trước khi tính toán, để tránh lost-update nếu có 2 heartbeat gần như đồng thời cho cùng `(user_id, lesson_id)` (hiếm vì đã bị giới hạn 1 phiên/học sinh — ADR-003 — nhưng vẫn có thể xảy ra do retry mạng phía client). Với 1 dòng PK duy nhất, chi phí khoá này không đáng kể.

**Cấu hình InnoDB (tham khảo cho DBA hạ tầng khi có server thật):** `innodb_buffer_pool_size` nên đủ chứa working set của `lesson_progress` + index (ước lượng ở trên) cộng `enrollments`/`orders` đang hoạt động — khuyến nghị chung MySQL là 70–80% RAM dành cho DB server. `innodb_flush_log_at_trx_commit=1` (an toàn dữ liệu đầy đủ) nên **giữ nguyên** dù có thêm chi phí fsync, vì đây là setting toàn instance (không thể chỉnh riêng theo bảng) và các bảng tiền (`orders`, `payment_attempts`) bắt buộc phải durable — 50 ghi/giây của `lesson_progress` không đủ lớn để phải đánh đổi độ an toàn của dữ liệu tiền.

### 2.6 `JSON_SET` autosave quiz & giới hạn kích thước

**Xác nhận cú pháp đúng:**
```sql
UPDATE quiz_attempts
SET answers = JSON_SET(answers, CONCAT('$."', ?, '"'), ?)
WHERE id = ? AND submitted_at IS NULL;
```
- Path JSON được dựng động qua `CONCAT` là hợp lệ (MySQL chỉ cần path là 1 chuỗi hợp lệ tại thời điểm chạy, không bắt buộc literal).
- Bọc key trong dấu `"..."` là **bắt buộc đúng**: JSON Path không cho phép tên thành viên bắt đầu bằng chữ số nếu không có dấu ngoặc kép (vì `question_id` là số) — thiết kế đã làm đúng.
- `question_id` đã được validate thuộc `question_ids` của attempt trước khi build câu lệnh → không có rủi ro JSON-path injection dù dùng `CONCAT`.
- 1 câu `UPDATE` là nguyên tử ở mức row, không mất cập nhật khi nhiều request autosave chồng nhau (khác câu hỏi) — đúng như mô tả.

**Đề xuất bổ sung (phòng vệ ở tầng DB, không thay App validation):** thêm CHECK giới hạn số câu hỏi tối đa mỗi lượt làm, chặn trường hợp bug/tấn công sinh JSON khổng lồ dù đã validate ở app:
```sql
ALTER TABLE quiz_attempts
  ADD CONSTRAINT chk_quiz_attempts_question_count
  CHECK (JSON_LENGTH(question_ids) BETWEEN 1 AND 200);
```
(200 là ví dụ — chốt theo cấu hình thực tế số câu tối đa/đề mà PO/BA cho phép). CHECK constraint MySQL 8.0.16+ cho phép dùng hàm JSON, hoạt động bình thường.

**Đề xuất tối ưu (tuỳ chọn, không bắt buộc):** bật `binlog_row_value_options = PARTIAL_JSON` (MySQL 8.0.3+, yêu cầu `binlog_format=ROW` — đã có sẵn theo thiết kế) để MySQL chỉ ghi **phần thay đổi** của giá trị JSON vào binlog thay vì toàn bộ document mỗi lần `JSON_SET`/`JSON_REPLACE`/`JSON_REMOVE` — giảm dung lượng binlog/I/O replication khi có nhiều lượt autosave. Cần kiểm tra tương thích với công cụ backup/CDC nếu có trước khi bật (một số công cụ đọc binlog cũ không hỗ trợ partial JSON), nên đây là đề xuất "nice-to-have", không chặn triển khai.

**Chấm/lọc:** `QuizGrader` đọc câu hỏi theo PK (`withTrashed()`) từ danh sách `question_ids` đã chốt — không cần index JSON/`JSON_TABLE` vì luôn truy vấn theo khoá chính đã biết trước, không có truy vấn tìm kiếm *trong* nội dung JSON ở MVP. Xác nhận thiết kế phù hợp quy mô hiện tại.

### 2.7 Lưu trữ & dọn log IPN/đối soát, export files

**Xác nhận chủ trương:** giữ `payment_webhook_events` 24 tháng là hợp lý — lưu ý bảng này **chỉ là log kỹ thuật thô** phục vụ tranh chấp/audit; các bản ghi nghiệp vụ có giá trị pháp lý/kế toán lâu dài (`orders`, `order_status_logs`, `payment_attempts`, `coupon_usages`) **không** nằm trong phạm vi xoá và **không có** chính sách dọn — đúng, vì đó là hồ sơ giao dịch cần giữ theo luật kế toán (thường ≥ 5 năm ở VN, tuỳ loại chứng từ). **Cần PO/kế toán xác nhận 24 tháng** cho riêng log kỹ thuật là đủ (cửa sổ tranh chấp/khiếu nại thực tế của MoMo thường ngắn hơn nhiều, 24 tháng đã khá rộng rãi) — đây là câu hỏi nghiệp vụ, không phải kỹ thuật.

**Phát hiện: thiếu job vận hành.** `data-model.md` §6 nêu chính sách "giữ 24 tháng ... DBA đề xuất", nhưng rà `docs/architecture/README.md` §4 (bảng cron/queue) và `docs/architecture/tasks.md` **không có bất kỳ task/cron nào** thực thi việc này (danh sách cron hiện tại: `orders:expire-pending`, `quizzes:auto-submit-expired`, `payments:reconcile`, `exports:purge`, `courses:recount-enrollments`, `videos:check-stuck`, `otp:prune`, `videolab:cleanup`). Đề xuất Architect bổ sung task mới, ví dụ **T-mới `payments:purge-webhook-events`**, chạy hằng ngày (giờ thấp điểm), xoá theo lô để tránh transaction dài/khoá bảng lâu ở giờ cao điểm:

```php
// app/Console/Commands/PurgeWebhookEvents.php (minh hoạ, KHÔNG chạy trong review này)
$cutoff = now()->subMonths(24);
do {
    $deleted = DB::table('payment_webhook_events')
        ->where('received_at', '<', $cutoff)
        ->limit(5000)
        ->delete();
    usleep(200_000); // nhường CPU/IO cho traffic thật
} while ($deleted > 0);
```

**Phát hiện: index hiện tại không tối ưu cho truy vấn theo thời gian.** Index khai báo là `IX(gateway, received_at)`. Ở MVP chỉ có 1 cổng thanh toán (`momo`) nên cột `gateway` gần như hằng số — đặt làm cột dẫn đầu của composite index khiến index **không hỗ trợ tốt** các truy vấn chỉ lọc theo `received_at` (ví dụ job xoá theo lô ở trên, hoặc admin tra cứu log theo khoảng ngày khi hỗ trợ khiếu nại) vì `gateway` không có tính chọn lọc. Đề xuất đổi thành:
```php
$table->index('received_at', 'payment_webhook_events_received_at_index');
```
(bỏ hẳn tiền tố `gateway`, hoặc đảo thứ tự `(received_at, gateway)` nếu vẫn muốn giữ khả năng lọc theo cổng khi có nhiều cổng thanh toán trong tương lai — tuỳ Architect quyết định, không ảnh hưởng lớn ở quy mô hiện tại).

**Export files (`exports` table + disk):** cơ chế `exports:purge` xoá **file** hết hạn sau 24h đã hợp lý; bảng `exports` (metadata) nên **giữ nguyên không xoá** vì rất nhỏ (vài chục dòng/ngày) và có giá trị audit "ai đã xuất báo cáo gì" — không cần thêm chính sách dọn ở MVP. Đề xuất nhỏ: thêm 1 cron dọn "mồ côi" (file tồn tại > 48h dù DB báo `done`/`failed` từ lâu, phòng trường hợp job crash không cập nhật trạng thái) như lưới an toàn bổ sung cho `exports:purge` hiện tại — không bắt buộc.

### 2.8 Collation utf8mb4 cho tiếng Việt

**Xác nhận cách tiếp cận `search_text` (courses) là đúng và nên áp dụng nhất quán:** chuẩn hoá bằng PHP (`Str::ascii` + lowercase) thay vì dựa vào hành vi collation — cách này cho kết quả **nhất quán, kiểm thử được bằng unit test**, không phụ thuộc phiên bản MySQL/collation cụ thể. Đúng như data-model đã chọn.

**Xác nhận hiện tượng đã ghi trong data-model (không phải lỗi):** `utf8mb4_unicode_ci` (dựa trên UCA — Unicode Collation Algorithm phiên bản cũ 4.0.0) thực sự coi nhiều tổ hợp chữ cái có dấu và không dấu là **bằng nhau** ở mức so sánh chính (ví dụ "Hình học" ~ "Hinh hoc", "é" ~ "e") — đây là hành vi MySQL đã biết, không phải giả định sai. Việc chấp nhận `subjects.name` unique bị ảnh hưởng bởi hành vi này (ghi rõ ở US-011 BR1) là hợp lý.

**Đề xuất cân nhắc trước migration đầu tiên (vì đổi sau sẽ tốn kém):** MySQL 8.0 có collation UCA 9.0.0 mới hơn, `utf8mb4_0900_ai_ci` (accent-insensitive, case-insensitive) và `utf8mb4_0900_as_cs` (accent+case sensitive) — nhanh hơn `utf8mb4_unicode_ci` (UCA 4.0.0 cũ, cách cài đặt kém hiệu quả hơn) trong so sánh/sắp xếp, đồng thời cho ngữ nghĩa "bỏ dấu khi so sánh" rõ ràng, tường minh hơn (đặt tên collation nói rõ `ai` = accent-insensitive) thay vì hành vi ẩn của `unicode_ci`. Vì dự án **chưa có migration nào chạy** (greenfield thật sự), đây là thời điểm rẻ nhất để đổi. Nếu đổi sau khi đã có dữ liệu lớn, cần `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci` — quét/viết lại toàn bảng, tốn kém trên `lesson_progress`/`orders` ở quy mô hàng chục triệu dòng.
- Đề xuất: `config/database.php` → `'collation' => 'utf8mb4_0900_ai_ci'` (yêu cầu MySQL ≥ 8.0.1, dự án đã nhắm 8.0.16+/8.4 — thoả điều kiện).
- **Rủi ro cần kiểm chứng trước khi đổi:** hành vi gộp dấu giữa `unicode_ci` (UCA 4.0.0) và `0900_ai_ci` (UCA 9.0.0) **không đảm bảo giống hệt nhau 100%** cho mọi ký tự tiếng Việt (dấu thanh, tổ hợp Unicode dựng sẵn vs tổ hợp base+combining mark) — cần viết 1 test nhỏ (insert vài cặp tên chuyên đề có/không dấu, kiểm tra unique) trước khi chốt, đúng như checklist mục 5.
- Đây là **quyết định của Architect/PO** (đổi mặc định Laravel hay giữ nguyên) — DBA chỉ khuyến nghị, không tự sửa data-model.

**Góp ý nhỏ khác:**
- `users.email varchar(191)` — giới hạn 191 là di sản từ thời MySQL 5.6/InnoDB Antelope (giới hạn index 767 byte). MySQL 8 + `ROW_FORMAT=DYNAMIC` (mặc định) cho phép index tới 3072 byte (~768 ký tự utf8mb4), nên không còn ràng buộc kỹ thuật. Đề xuất tăng lên `varchar(254)` để khớp đúng giới hạn tối đa của địa chỉ email theo RFC 5321 (191 có thể cắt một số email hợp lệ dài, dù hiếm) — thay đổi rẻ, nên làm ngay từ migration đầu.
- Xác nhận **không cần** `Schema::defaultStringLength(191)` như data-model đã ghi — đúng cho MySQL 8 InnoDB DYNAMIC row format.

### 2.9 Kiểu tiền, thời gian, thứ tự migration

**Tiền tệ:** `int unsigned` cho VNĐ (không thập phân) là lựa chọn đúng — tránh sai số dấu phẩy động, đủ dư miền giá trị (tối đa ~4,29 tỷ VNĐ, vượt xa mọi đơn hàng thực tế), và `unsigned` tự nhiên đảm bảo không âm mà không cần CHECK riêng. Đồng ý.

**Đề xuất bổ sung:** cột `coupons.discount_value` đang dùng chung 1 kiểu `int unsigned` cho 2 ngữ nghĩa khác nhau (`percent`: 1–100, `fixed_amount`: số tiền VNĐ) và chỉ được kiểm ở tầng App. Đề xuất thêm CHECK ở DB làm lớp phòng vệ thứ 2 (chống dữ liệu sai từ script/tinker/import thủ công, đúng tinh thần data-model §6 đã áp dụng cho `grade_level`):
```sql
ALTER TABLE coupons
  ADD CONSTRAINT chk_coupons_percent_range
  CHECK (discount_type <> 'percent' OR discount_value BETWEEN 1 AND 100);
```
MySQL 8.0.16+ cho phép CHECK tham chiếu nhiều cột cùng 1 dòng (không phải subquery) — hợp lệ.

**Thời gian:** `APP_TIMEZONE=Asia/Ho_Chi_Minh` + connection Laravel `'timezone' => '+07:00'` — đây **là** khoá cấu hình hợp lệ của Laravel cho MySQL (khác với khoá `isolation_level` ở mục 2.1 không tồn tại), Laravel sẽ chạy `SET time_zone = '+07:00'` khi mở kết nối, đảm bảo `NOW()`/`CURRENT_TIMESTAMP()` phía MySQL khớp giờ VN nếu có raw SQL nào dùng tới. Việc dùng `datetime` (không dùng `timestamp`, vốn lưu UTC nội bộ và tự quy đổi theo session) cho toàn bộ cột nghiệp vụ là lựa chọn nhất quán, tránh lệ thuộc quy đổi timezone 2 lớp (App + DB). Đồng ý toàn bộ, không cần sửa.

**Thứ tự migration (data-model §5):** đã rà theo phụ thuộc khoá ngoại — **không phát hiện vi phạm thứ tự FK** nào (ví dụ `lessons` cần `video_assets` đã được đặt sau `video_assets`; `enrollments` cần `orders` đã đặt sau nhóm `orders`; `coupon_usages` cần cả `coupons` lẫn `orders` đã đặt sau cả 2). Đồng ý giữ nguyên thứ tự.

**Lưu ý vận hành khi Dev viết migration cho generated column/CHECK:** mỗi migration thêm CHECK bằng `DB::statement('ALTER TABLE ... ADD CONSTRAINT chk_xxx CHECK (...)')` phải đặt **tên constraint tường minh** (như các ví dụ ở trên, không để MySQL tự sinh tên) để `down()` có thể `DROP CHECK chk_xxx` chính xác — data-model đã nhắc điểm này ở §5, xác nhận đúng, chỉ nhấn mạnh lại vì dễ bị Dev bỏ qua khi copy-paste nhanh.

---

## 3. Việc Architect cần sửa trong `data-model.md`/tài liệu liên quan trước khi Dev bắt đầu

1. **§6, bảng "Isolation level":** sửa mô tả cấu hình — bỏ `'isolation_level' => 'READ COMMITTED'` (khoá không tồn tại cho driver `mysql`), thay bằng ví dụ `PDO::MYSQL_ATTR_INIT_COMMAND` ở mục 2.1. Ghi rõ phạm vi là session/connection-level.
2. **§4 vs ADR-001 §4:** thống nhất lại thứ tự khoá `coupons` so với `enrollments` (mục 2.3) — sửa câu tóm tắt ở data-model §4 cho khớp với thứ tự thật sự trong luồng IPN mô tả ở ADR-001.
3. **README §4 / tasks.md:** bổ sung task/cron dọn `payment_webhook_events` theo chính sách 24 tháng đã nêu ở data-model §6 nhưng chưa có job thực thi (mục 2.7). Cần thêm 1 dòng **[DBA]** trong tasks.md tương tự các task khác.
4. **§3.5, `payment_webhook_events`:** đổi index `IX(gateway, received_at)` → `IX(received_at)` (hoặc `(received_at, gateway)`) vì `gateway` gần như hằng số ở MVP (mục 2.7).
5. **§3.5, `coupons`:** thêm CHECK `discount_type <> 'percent' OR discount_value BETWEEN 1 AND 100` (mục 2.9); cân nhắc thêm cron `coupons:recount-used` đối soát `used_count` giống `courses:recount-enrollments` (mục 2.3).
6. **§3.4, `quiz_attempts`:** thêm CHECK `JSON_LENGTH(question_ids) BETWEEN 1 AND 200` (số cụ thể theo PO chốt) làm lớp phòng vệ DB (mục 2.6).
7. **§0, dòng "DB":** chốt collation cuối cùng trước migration đầu tiên — `utf8mb4_unicode_ci` (giữ mặc định Laravel) hay `utf8mb4_0900_ai_ci` (đề xuất, nhanh hơn, MySQL 8 native) (mục 2.8). Đây là quyết định 1 lần, đổi sau sẽ tốn kém.
8. **§3.1, `users.email`:** cân nhắc tăng `varchar(191)` → `varchar(254)` (mục 2.8) — nhỏ, không bắt buộc.
9. **§6 (hoặc README §6):** ghi rõ quyết định phân trang cho danh sách đơn quản trị — `cursorPaginate` (khuyến nghị) hay OFFSET có giới hạn độ sâu — cần thống nhất với `laravel-designer` vì ảnh hưởng UI phân trang (mục 2.4). Hiện README §6 mới ghi "DBA xem có cần cursorPaginate" mà chưa có kết luận.
10. **§2 (khối lượng dữ liệu):** ghi rõ mốc theo dõi cụ thể cho "khi nào cần bảng tổng hợp tiến độ" (ví dụ ngưỡng p95 latency hoặc số dòng `lesson_progress`) thay vì để chung chung ở R8 — giúp việc quyết định sau go-live có tiêu chí rõ ràng (mục 2.5).

---

## 4. Rủi ro còn lại (không chặn triển khai, cần theo dõi)

| Rủi ro | Mức | Ghi chú |
|---|---|---|
| Đổi isolation toàn connection là thay đổi hành vi toàn ứng dụng | Trung bình | Cần test hồi quy các luồng đọc-sửa-đọc nhiều bước trong 1 transaction, đặc biệt code viết sau này (T18 trở đi) |
| `coupons`/`courses` là hot row khi có chiến dịch giảm giá/khoá bán chạy | Trung bình | Cần load test kịch bản N học sinh cùng 1 mã giảm giá trước khi chạy campaign lớn |
| Chưa biết cấu hình hạ tầng MySQL thật (RAM, `innodb_buffer_pool_size`, bản 8.0.x hay 8.4) | Trung bình | CLAUDE.md ghi "chưa có" hạ tầng cụ thể — cần chốt trước go-live để size buffer pool cho `lesson_progress` |
| 24 tháng lưu `payment_webhook_events` là quyết định nghiệp vụ, chưa qua PO/kế toán | Thấp–Trung bình | Các bảng có giá trị pháp lý (`orders`, `payment_attempts`, `order_status_logs`) không bị ảnh hưởng, chỉ log kỹ thuật thô |
| Đổi collation `unicode_ci` → `0900_ai_ci` (nếu Architect chọn) thay đổi hành vi gộp dấu unique | Thấp | Cần test cụ thể với vài cặp tên tiếng Việt trước khi chốt (mục 2.8), làm **trước** khi có dữ liệu thật |
| `cursorPaginate` (nếu chọn) đổi UX phân trang, ảnh hưởng mockup hiện có | Thấp | Cần `laravel-designer` xác nhận trước khi Dev code list đơn hàng |
| Tìm kiếm "tên học sinh" trong danh sách đơn (LIKE + join) không tối ưu hoá được bằng index | Thấp | Chấp nhận được ở quy mô công cụ nội bộ; theo dõi nếu sau này mở rộng thành báo cáo tần suất cao |

---

## 5. Checklist kiểm chứng khi Dev tạo migration đầu tiên (local/dev)

Vì hiện chưa có DB thật, đây là checklist DBA đề xuất chạy **khi bắt đầu code (T07 trở đi)**, trước khi migration chung được review/merge:

1. `php artisan migrate` local → `SHOW CREATE TABLE enrollments\G` / `orders\G` / `quiz_attempts\G`: xác nhận generated column có `GENERATED ALWAYS AS (...) STORED` đúng biểu thức và unique index gồm đúng cột generated.
2. Test race (Pest, 2 kết nối DB song song hoặc mô phỏng bằng transaction lồng): tạo 2 đơn pending cho cùng 1 học sinh gần như đồng thời → xác nhận request thứ 2 nhận lỗi `1062 Duplicate entry` (bắt bằng `QueryException`), không tạo ra 2 dòng pending. Lặp lại cho `enrollments` và `quiz_attempts`.
3. `SELECT @@transaction_isolation;` ngay sau khi Laravel mở kết nối (ví dụ trong `tinker` hoặc test) → xác nhận đúng `READ-COMMITTED`, kiểm chứng cấu hình PDO init command hoạt động.
4. Seed dữ liệu đủ lớn ở môi trường staging trước go-live (khuyến nghị ≥ 100k `orders`, ≥ 1M `lesson_progress` bằng factory + `chunkById` để không hết bộ nhớ) → chạy `EXPLAIN ANALYZE` cho: danh sách đơn theo filter phổ biến, tìm kiếm tên học sinh, "Khoá học của tôi", job đối soát, job auto-submit quiz — xác nhận `type = ref/range` (index seek), không có `type = ALL` (full scan) trên các bảng lớn.
5. Test CHECK constraint (nếu áp dụng các đề xuất ở mục 2.6/2.9): insert vi phạm → xác nhận lỗi `3819 (HY000): Check constraint ... is violated`.
6. Test deadlock có chủ đích: script N request checkout đồng thời dùng chung 1 mã giảm giá đã set `max_uses` nhỏ → theo dõi `SHOW ENGINE INNODB STATUS \G` (phần `LATEST DETECTED DEADLOCK`) và xác nhận tổng số đơn thành công dùng mã **không vượt** `max_uses`, đồng thời không có request nào bị lỗi 500 (retry `DB::transaction($fn, 3)` xử lý hết).
7. Nếu Architect chọn đổi collation (mục 2.8): test insert 2 chuyên đề tên có/không dấu → xác nhận hành vi unique giống mong đợi trước khi merge migration đầu tiên.

---

## 6. Tóm tắt cho người dùng

**File đã tạo:** `docs/db/design-review.md` (tài liệu này).

**Kết luận theo từng điểm 1–9:**
1. Isolation `READ COMMITTED` — đồng ý về chủ trương, **cần sửa cách cấu hình** (khoá `isolation_level` không hợp lệ với driver MySQL của Laravel), áp dụng ở mức session/connection.
2. Generated column + unique — đồng ý, thiết kế đúng chuẩn MySQL 8, không cần sửa.
3. Thứ tự khoá — đồng ý về bản chất (không có deadlock cycle thật), nhưng phát hiện **mâu thuẫn tài liệu** giữa data-model §4 và ADR-001 §4 cần thống nhất; đề xuất thêm job đối soát `coupons.used_count`.
4. Index `orders`/`payment_attempts` — đồng ý bộ index hiện có; cần quyết định chiến lược phân trang (cursor vs offset) và cách xử lý ô tìm kiếm tự do trước khi code.
5. `lesson_progress` — đồng ý thiết kế hiện tại, **không cần partition/bảng tổng hợp ở MVP**; 50 ghi/giây là tải nhẹ; dung lượng ước tính ~7–9 GB/3 năm.
6. `JSON_SET` autosave — đồng ý cú pháp; đề xuất thêm CHECK `JSON_LENGTH` phòng vệ.
7. Lưu trữ log IPN/đối soát — đồng ý chủ trương 24 tháng; **phát hiện thiếu cron job thực thi** và index chưa tối ưu cho truy vấn theo thời gian.
8. Collation utf8mb4 — đồng ý cách tiếp cận `search_text`; đề xuất cân nhắc đổi sang `utf8mb4_0900_ai_ci` trước migration đầu tiên (đổi sau tốn kém).
9. Kiểu tiền/thời gian/thứ tự migration — đồng ý toàn bộ; đề xuất thêm CHECK cho `coupons.discount_value`.

**Việc Architect cần sửa trước khi Dev bắt đầu:** 10 mục cụ thể ở phần 3 (đánh số trực tiếp trong data-model để dễ đối chiếu).

**Không có lệnh ghi DB nào được chạy** (đúng phạm vi review thiết kế, chưa có DB thật). Các đoạn SQL/migration trong tài liệu này là **minh hoạ**, cần Dev/laravel-dev đưa vào migration thật khi code (task T07 và các task **[DBA]** liên quan trong `tasks.md`).

**Chuyển tiếp:**
- `laravel-architect`: cập nhật 10 điểm ở mục 3 vào `data-model.md`/`README.md`/`tasks.md`.
- `laravel-dev`: khi code migration, tham khảo các ví dụ ở mục 2.1/2.2/2.6/2.9 và chạy checklist ở mục 5 trước khi merge.
- `laravel-security`: không phát sinh thêm vấn đề bảo mật mới ngoài phạm vi đã giao ở README §9 (ADR-001 vẫn cần review chữ ký/HMAC như đã yêu cầu).
