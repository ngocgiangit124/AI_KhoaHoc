# US-011: Quản lý chuyên đề khóa học (CRUD)

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là Admin/Quản lý trang, tôi muốn tạo, sửa, ẩn/xóa chuyên đề Toán (ví dụ Đại số, Hình học, Ôn thi vào 10...) để linh hoạt tổ chức danh mục khóa học theo nhu cầu thực tế thay vì bị giới hạn bởi danh sách cố định.

## Bối cảnh
Phát sinh từ quyết định của PO ở US-002: danh sách chuyên đề không cố định, Admin/Quản lý trang tự bổ sung. Đây là phân hệ dữ liệu nền cho bộ lọc danh mục (US-002) và form tạo khóa học (US-009).

## Business rules
- BR1: Tên chuyên đề duy nhất (unique, không phân biệt hoa/thường), tự động sinh slug.
- BR2: Không cho xóa cứng chuyên đề đang được gán cho ít nhất 1 khóa học; phải gỡ khỏi toàn bộ khóa học trước, hoặc chuyển chuyên đề sang trạng thái **ẩn** (`hidden`) thay vì xóa.
- BR3: Chuyên đề ở trạng thái ẩn không xuất hiện trong bộ lọc công khai (US-002) và không được chọn khi tạo khóa học mới (US-009), nhưng các khóa học đã gán trước đó vẫn giữ nguyên liên kết dữ liệu.
- BR4: Chỉ Admin và Quản lý trang có quyền tạo/sửa/ẩn/xóa chuyên đề; Giáo Viên chỉ được xem danh sách chuyên đề để chọn khi tạo/sửa khóa học của mình (US-009).

## Acceptance criteria
- AC1: Given admin nhập tên chuyên đề mới hợp lệ chưa tồn tại, When bấm "Tạo chuyên đề", Then chuyên đề được tạo ở trạng thái `active` và xuất hiện ngay trong bộ lọc chuyên đề ở US-002 và trong form tạo khóa học ở US-009.
- AC2: Given tên chuyên đề đã tồn tại (không phân biệt hoa/thường), When tạo mới với tên trùng, Then hệ thống báo lỗi "Chuyên đề đã tồn tại" và không tạo bản ghi mới.
- AC3: Given chuyên đề đang được gán cho ít nhất 1 khóa học, When admin bấm "Xóa", Then hệ thống chặn xóa và gợi ý chuyển sang "Ẩn" thay vì xóa.
- AC4: Given chuyên đề chưa gán cho khóa học nào, When admin bấm "Xóa" và xác nhận, Then chuyên đề bị xóa khỏi hệ thống.
- AC5: Given admin đổi tên 1 chuyên đề đang được sử dụng bởi nhiều khóa học, When lưu thay đổi, Then tên mới hiển thị ngay trên các khóa học và bộ lọc liên quan mà không cần cập nhật thủ công từng khóa học.
- AC6: Given admin chuyển 1 chuyên đề đang active sang "Ẩn", When khách xem bộ lọc chuyên đề ở US-002, Then chuyên đề đó không còn xuất hiện trong danh sách lựa chọn, nhưng khóa học đã gán chuyên đề này trước đó vẫn hiển thị bình thường trên trang chi tiết.

## Trường hợp biên & lỗi
- Tên chuyên đề rỗng hoặc chỉ toàn khoảng trắng → báo lỗi validate, không tạo được.
- Tên chuyên đề chứa ký tự đặc biệt/HTML → escape khi hiển thị, tránh XSS.
- Giáo viên cố gọi API tạo/sửa/xóa chuyên đề trực tiếp → bị từ chối quyền (403), chỉ Admin/Quản lý trang mới thao tác được.
- Ẩn chuyên đề đang là điều kiện áp dụng của 1 mã giảm giá theo phạm vi chuyên đề (nếu US-013 hỗ trợ) → cần đảm bảo mã giảm giá đó không bị lỗi khi tính toán (kiểm tra chéo khi hiện thực).

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Ẩn | Xoá |
|---|---|---|---|---|---|
| Admin | Có | Có | Có | Có | Có (nếu chưa gán khóa học) |
| Quản lý trang | Có | Có | Có | Có | Có (nếu chưa gán khóa học) |
| Giáo Viên | Có (chỉ để chọn khi tạo khóa học) | Không | Không | Không | Không |
| Học Sinh | Có (gián tiếp qua bộ lọc danh mục) | Không | Không | Không | Không |

## Ảnh hưởng dữ liệu
- Bảng `subjects`: `id`, `name`, `slug` (unique), `status` (enum: active, hidden), timestamps.
- Bảng pivot `course_subject`: `course_id`, `subject_id` (đã có ở US-002/US-009, không đổi).

## Ngoài phạm vi
- Sắp xếp thứ tự hiển thị tùy chỉnh cho chuyên đề trên bộ lọc (mặc định theo alphabet hoặc thời gian tạo).
- Chuyên đề phân cấp cha-con (ví dụ "Đại số" là cha của "Phương trình", "Bất phương trình").
- Gộp chung với khái niệm "mã giới thiệu"/"mã giảm giá" — đây là 2 khái niệm khác nhau (chuyên đề dùng để phân loại nội dung, không liên quan mã khuyến mãi).

## Câu hỏi mở
- [ ] Có cần thứ tự hiển thị tùy chỉnh cho chuyên đề trên bộ lọc danh mục không, hay theo alphabet/thời gian tạo là đủ cho MVP?
- [ ] Danh sách chuyên đề khởi tạo ban đầu (seed data) gồm những gì để có dữ liệu mẫu khi go-live? (Đề xuất: Đại số, Hình học, Số học, Ôn thi vào lớp 10, Ôn thi THPT Quốc gia — cần PO/chuyên môn xác nhận danh sách chính thức.)

## Ghi chú cho Designer / Dev / QA
- Designer: màn hình CRUD đơn giản dạng bảng (danh sách + form tạo/sửa), có toggle Ẩn/Hiện; đảm bảo dùng chung component multi-select chuyên đề giữa US-002 (bộ lọc) và US-009 (form tạo khóa học).
- Dev: đảm bảo ràng buộc unique ở tầng CSDL cho `subjects.name`/`slug`; kiểm tra ràng buộc khóa ngoại trước khi cho xóa cứng (AC3/AC4).
- QA: kiểm thử xóa chuyên đề đang được gán cho khóa học (phải bị chặn); kiểm thử ẩn chuyên đề và xác nhận khóa học cũ vẫn hiển thị đúng trên trang chi tiết dù chuyên đề bị ẩn khỏi bộ lọc.
