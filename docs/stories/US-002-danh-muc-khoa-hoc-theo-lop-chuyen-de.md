# US-002: Duyệt danh mục khóa học theo lớp và chuyên đề

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là khách hoặc học sinh, tôi muốn xem danh sách khóa học Toán và lọc theo lớp học, chuyên đề để nhanh chóng tìm được khóa học phù hợp với nhu cầu.

## Bối cảnh
Đây là trang chính để người dùng khám phá sản phẩm trước khi mua. Phụ thuộc dữ liệu khóa học được admin/giáo viên tạo ở US-009, và danh sách chuyên đề được quản lý ở US-011 (Quản lý chuyên đề).

## Business rules
- BR1: Mỗi khóa học gắn với đúng 1 lớp học (6–12) và ít nhất 1 chuyên đề.
- BR2: Danh mục công khai chỉ hiển thị khóa học có `status = published`.
- BR3: Có thể lọc kết hợp đồng thời nhiều tiêu chí (lớp + chuyên đề + từ khóa tìm kiếm).
- BR4 (Quyết định của PO): Danh sách khóa học phân trang **25 khóa học/trang**.
- BR5 (Quyết định của PO): Danh sách chuyên đề **không cố định** — Admin/Quản lý trang tự tạo, sửa, ẩn chuyên đề qua phân hệ quản trị riêng (xem US-011). Bộ lọc chuyên đề ở trang này luôn phản ánh danh sách chuyên đề đang ở trạng thái hiển thị (active) tại thời điểm truy cập.
- BR6 (Quyết định của PO): Hỗ trợ 3 kiểu sắp xếp: **mới nhất** (mặc định, theo `published_at`/`created_at` giảm dần), **phổ biến nhất** (theo số lượng enrollment giảm dần), và **thứ tự thủ công** do Admin/Quản lý trang tự set (nếu khóa học có set `manual_order`, ưu tiên hiển thị theo thứ tự đó khi người dùng chọn kiểu sắp xếp "Nổi bật"/"Đề xuất").
- BR7 (Quyết định của PO): Có trang danh mục riêng cho từng lớp phục vụ SEO, theo dạng URL `/lop-{grade}` (ví dụ `/lop-9`), liệt kê toàn bộ khóa học published của lớp đó.

## Acceptance criteria
- AC1: Given có khóa học đã published thuộc lớp 8, When khách chọn filter "Lớp 8", Then danh sách chỉ hiển thị khóa học lớp 8 đã published.
- AC2: Given khách chọn thêm filter chuyên đề "Hình học" cùng lớp 8, When áp dụng đồng thời, Then chỉ hiển thị khóa học thỏa cả hai điều kiện.
- AC3: Given không có khóa học nào khớp bộ lọc, When áp dụng filter, Then hiển thị thông báo rõ ràng "Không tìm thấy khóa học phù hợp" kèm gợi ý bỏ bớt điều kiện lọc.
- AC4: Given khóa học ở trạng thái draft hoặc unpublished, When khách duyệt danh mục, Then khóa học đó không xuất hiện trong danh sách công khai.
- AC5: Given khách nhập từ khóa tìm kiếm (ví dụ "hình học lớp 9") vào ô tìm kiếm, When submit, Then kết quả trả về các khóa học có tên hoặc mô tả ngắn khớp từ khóa (không phân biệt hoa/thường, không dấu).
- AC6: Given danh sách khóa học có hơn 25 kết quả, When khách chuyển trang, Then hệ thống phân trang đúng theo 25 khóa học/trang, không trùng lặp hoặc bỏ sót khóa học.
- AC7: Given khách chọn kiểu sắp xếp "Mới nhất"/"Phổ biến nhất", When áp dụng, Then thứ tự danh sách thay đổi đúng theo tiêu chí đã chọn.
- AC8: Given khách truy cập URL `/lop-9`, When trang tải, Then hiển thị toàn bộ khóa học lớp 9 đã published, có thể lọc thêm theo chuyên đề trong phạm vi lớp 9, và trang có thẻ tiêu đề/mô tả tối ưu SEO cho "Khóa học Toán lớp 9".
- AC9: Given một chuyên đề bị Admin chuyển sang trạng thái ẩn (US-011), When khách mở bộ lọc chuyên đề, Then chuyên đề đó không còn xuất hiện trong danh sách lựa chọn lọc (dù khóa học cũ vẫn giữ liên kết dữ liệu).

## Trường hợp biên & lỗi
- Chưa có khóa học nào published trong toàn hệ thống → trang danh mục hiển thị trạng thái rỗng thân thiện, không lỗi trắng trang.
- Từ khóa tìm kiếm chứa ký tự đặc biệt/SQL injection → phải được xử lý an toàn qua query builder/Eloquent, không lỗi 500.
- Truy cập URL filter với giá trị lớp không hợp lệ (ví dụ `?grade=99` hoặc `/lop-99`) → hệ thống bỏ qua filter không hợp lệ hoặc trả 404 cho trang lớp không tồn tại (6–12), không crash.
- Số lượng khóa học lớn (vài trăm) → thời gian tải trang phải nằm trong ngưỡng chấp nhận được (cần PO/QA thống nhất ngưỡng cụ thể, ví dụ < 2 giây).
- Nhiều khóa học có cùng `manual_order` hoặc không set → cần quy tắc tie-break rõ ràng (ví dụ theo `created_at`) khi sắp xếp "Nổi bật".

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Xoá |
|---|---|---|---|---|
| Khách | Danh mục khóa học published | – | – | – |
| Học Sinh | Danh mục khóa học published | – | – | – |
| Giáo Viên | Danh mục khóa học published | – | – | – |
| Quản lý trang | Toàn bộ (kể cả draft, qua trang quản trị riêng US-009) | – | – | – |
| Admin | Toàn bộ (kể cả draft, qua trang quản trị riêng US-009) | – | – | – |

## Ảnh hưởng dữ liệu
- Bảng `courses`: `id`, `title`, `slug`, `short_description`, `grade_level` (tinyint 6–12), `price`, `thumbnail`, `status` (enum: draft, published, unpublished), `published_at`, `manual_order` (integer, nullable), timestamps. (`teacher_id` đơn được thay bằng quan hệ nhiều-nhiều `course_teacher`, xem US-009.)
- Bảng `subjects` (chuyên đề, quản lý ở US-011): `id`, `name`, `slug`, `status` (active/hidden).
- Bảng pivot `course_subject`: `course_id`, `subject_id`.
- Cần trường/subquery đếm `enrollments` theo `course_id` để phục vụ sắp xếp "phổ biến nhất" — cân nhắc denormalize (cột `enrollment_count`) nếu dữ liệu lớn, có thể cần `laravel-dba` tư vấn khi lượng khóa học/enrollment tăng.
- Index trên `courses.grade_level`, `courses.status`, `courses.published_at`.

## Ngoài phạm vi
- Sắp xếp theo đánh giá/rating (chưa có tính năng đánh giá ở MVP).
- Gợi ý cá nhân hóa theo lịch sử học.
- Bộ lọc theo khoảng giá.

## Quyết định của PO
- Danh sách chuyên đề: admin tự tạo/bổ sung qua CRUD (không cố định) → xem story mới US-011.
- Số khóa học mỗi trang: 25.
- Sắp xếp mặc định/tùy chọn: mới nhất, phổ biến nhất; hỗ trợ thêm thứ tự thủ công nếu admin set.
- Có trang riêng theo từng lớp cho SEO (dạng `/lop-{grade}`).

## Câu hỏi mở
- [ ] Ngưỡng thời gian tải trang chấp nhận được là bao nhiêu giây khi danh mục có vài trăm khóa học?
- [ ] Tiêu chí "phổ biến nhất" tính theo tổng số enrollment mọi thời điểm, hay theo số enrollment trong khoảng thời gian gần đây (ví dụ 30 ngày)?

## Ghi chú cho Designer / Dev / QA
- Designer: thiết kế bộ lọc dễ dùng trên mobile (đa số học sinh dùng điện thoại); cần trạng thái rỗng và trạng thái loading rõ ràng; thiết kế thêm dropdown chọn kiểu sắp xếp và trang landing riêng theo lớp (`/lop-{grade}`) tối ưu SEO (H1, meta description).
- Dev: dùng query scope cho `published`, cân nhắc cache danh mục và cache trang `/lop-{grade}` nếu lượng truy cập lớn; đồng bộ danh sách chuyên đề với US-011.
- QA: kiểm thử tổ hợp filter (lớp + chuyên đề + từ khóa + sắp xếp), kiểm thử với dữ liệu rỗng và dữ liệu lớn (200+ khóa học), kiểm thử route `/lop-{grade}` với lớp hợp lệ/không hợp lệ.
