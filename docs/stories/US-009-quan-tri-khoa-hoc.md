# US-009: Quản trị khóa học (tạo, sửa, xóa, xuất bản)

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là Admin/Quản lý trang, tôi muốn tạo và quản lý khóa học (thông tin, chương, bài học, giáo viên phụ trách) để đưa nội dung lên bán trên website; là Giáo Viên, tôi muốn tự tạo khóa học của mình hoặc quản lý nội dung của các khóa học được giao để cập nhật bài giảng.

## Bối cảnh
Đây là phân hệ nguồn dữ liệu cho toàn bộ các story còn lại (US-002, US-003, US-006, US-007). Cần làm trước hoặc song song với các story khác vì các story kia phụ thuộc dữ liệu khóa học tồn tại.

## Business rules
- BR1: Khóa học mới tạo mặc định `status = draft`, không hiển thị công khai cho tới khi được xuất bản.
- BR2: Chỉ Admin và Quản lý trang được phép xuất bản (`publish`)/ngừng bán (`unpublish`) khóa học. Giáo Viên không có quyền publish, kể cả với khóa học do chính mình tạo.
- BR3: Điều kiện tối thiểu để publish: khóa học có ít nhất 1 chương và 1 bài học.
- BR4: Khóa học đã có ít nhất 1 enrollment (đã có học sinh mua/được duyệt) không được xóa cứng, chỉ được chuyển sang `unpublished` để bảo toàn quyền truy cập của học sinh đã có.
- BR5: Khóa học chưa có enrollment nào được phép xóa mềm (soft delete) cùng toàn bộ chương/bài liên quan.
- BR6 (Quyết định của PO — cập nhật): Một khóa học có thể có **nhiều giáo viên phụ trách** (đồng giảng dạy), quan hệ nhiều-nhiều qua bảng `course_teacher` (thay cho `teacher_id` đơn ở bản đặc tả trước). Một giáo viên chỉ được xem và sửa nội dung của khóa học mà mình **có tên trong danh sách giáo viên phụ trách**, không được truy cập khóa học mà mình không được gán.
- BR7: Thứ tự hiển thị của chương và bài học trong khóa học do người quản trị chủ động sắp xếp (kéo thả hoặc nhập số thứ tự).
- BR8 (Quyết định của PO): Giáo viên có **cả 2** quyền: (a) **tự tạo khóa học mới** (khóa học được tạo với `status = draft`, giáo viên đó tự động là 1 trong các giáo viên phụ trách), và (b) được **Admin/Quản lý trang gán** làm giáo viên phụ trách cho khóa học do admin tạo. Dù ở trường hợp nào, việc xuất bản khóa học vẫn luôn cần Admin/Quản lý trang duyệt và bấm "Xuất bản" (theo BR2) — đây chính là cơ chế "duyệt" khóa học do giáo viên tự tạo.
- BR9 (Quyết định của PO): Vai trò **Quản lý trang** có quyền quản trị **gần như tương đương Admin** trên toàn bộ các phân hệ nghiệp vụ (khóa học, chương/bài, chuyên đề — US-011, đơn hàng — US-010, mã giảm giá — US-013, phê duyệt đăng ký — US-012), **ngoại trừ** các chức năng **cấu hình hệ thống** (ví dụ quản lý tài khoản Admin khác, cấu hình chung của hệ thống — các chức năng này chưa thuộc phạm vi các story hiện tại).
- BR10 (Quyết định của PO): Video bài học hỗ trợ **cả 2 hình thức** nhập liệu: (a) tải video lên trực tiếp lưu trữ trên hệ thống nội bộ (theo US-006 BR5), hoặc (b) dán link nhúng từ nền tảng streaming ngoài. Người quản trị chọn 1 trong 2 hình thức khi tạo/sửa từng bài học.

## Acceptance criteria
- AC1: Given admin điền đầy đủ thông tin bắt buộc (tên, lớp học 6–12, chuyên đề, mô tả, học phí, ảnh đại diện, ít nhất 1 giáo viên phụ trách), When bấm "Tạo khóa học", Then khóa học được tạo với `status = draft` và xuất hiện trong danh sách quản trị.
- AC2: Given khóa học draft đã có ít nhất 1 chương và 1 bài học, When admin/quản lý trang bấm "Xuất bản", Then `status` chuyển sang `published` và khóa học xuất hiện trên danh mục công khai (US-002).
- AC3: Given khóa học draft chưa có chương/bài học nào, When admin cố bấm "Xuất bản", Then hệ thống chặn thao tác và báo lỗi rõ "Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản".
- AC4: Given khóa học đã có học sinh mua (enrollment > 0), When admin bấm "Xóa", Then hệ thống chặn xóa cứng, chỉ cho phép chuyển sang "Ngừng bán" kèm cảnh báo rõ ràng về việc học sinh đã mua vẫn giữ quyền truy cập.
- AC5: Given khóa học chưa có học sinh nào mua, When admin bấm "Xóa" và xác nhận, Then khóa học cùng chương/bài liên quan bị xóa mềm và không còn hiển thị ở bất kỳ danh sách nào (trừ log/audit nếu có).
- AC6: Given giáo viên đăng nhập vào trang quản lý khóa học, When xem danh sách, Then chỉ thấy các khóa học mà mình **có tên trong danh sách giáo viên phụ trách** (`course_teacher`).
- AC7: Given giáo viên cố truy cập trực tiếp URL sửa một khóa học mà mình không phải là giáo viên phụ trách, When truy cập, Then hệ thống trả về lỗi 403 (không có quyền).
- AC8: Given admin thêm/sắp xếp lại thứ tự các chương và bài học trong một khóa học, When lưu lại, Then thứ tự hiển thị cho học sinh (US-003, US-006) cập nhật đúng theo thứ tự mới ngay lập tức.
- AC9: Given giáo viên đăng nhập, When bấm "Tạo khóa học mới" và điền đầy đủ thông tin bắt buộc, Then khóa học được tạo với `status = draft`, giáo viên đó được tự động thêm vào danh sách giáo viên phụ trách, và khóa học xuất hiện trong danh sách chờ Admin/Quản lý trang xem xét xuất bản.
- AC10: Given admin đang tạo/sửa khóa học có nhiều giáo viên phụ trách, When thêm giáo viên thứ 2 vào danh sách phụ trách, Then cả 2 giáo viên đều thấy khóa học trong danh sách quản lý của mình (AC6) và đều có quyền sửa nội dung.
- AC11: Given admin tạo 1 bài học mới, When chọn hình thức "Tải video lên hệ thống" hoặc "Dán link video ngoài", Then hệ thống lưu đúng theo hình thức đã chọn và bài học phát được video tương ứng cho học sinh có quyền xem (US-006).

## Trường hợp biên & lỗi
- Nhập học phí âm hoặc không phải số → server phải từ chối, không chỉ chặn ở UI.
- Tên khóa học trùng nhau (không bắt buộc unique nhưng cần slug unique) → tự động sinh slug khác hoặc báo lỗi nếu trùng slug.
- Xóa 1 chương đang có bài học bên trong → cảnh báo và xóa cascade các bài học thuộc chương đó (nếu khóa học chưa publish/chưa có enrollment) hoặc chặn nếu đã có học sinh đang học.
- Gán giáo viên phụ trách cho một tài khoản không có role `giao_vien` → hệ thống phải từ chối gán sai role.
- Ảnh đại diện khóa học upload file không đúng định dạng/kích thước quá lớn → validate và báo lỗi rõ ràng.
- Admin unpublish một khóa học đang có học sinh học dở → học sinh có enrollment vẫn truy cập được bình thường (theo BR4/US-003), chỉ khách mới không thấy khóa học nữa.
- Gỡ giáo viên duy nhất khỏi danh sách phụ trách của 1 khóa học → hệ thống phải chặn (khóa học luôn cần tối thiểu 1 giáo viên phụ trách) hoặc yêu cầu gán giáo viên khác trước khi gỡ.
- Giáo viên tự tạo khóa học nhưng bỏ dở chưa hoàn thiện lâu ngày (draft "mồ côi") → ngoài phạm vi xử lý tự động ở MVP, chỉ hiển thị trong danh sách quản trị để admin theo dõi thủ công.

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Xoá | Xuất bản/Ngừng bán |
|---|---|---|---|---|---|
| Admin | Tất cả khóa học | Có | Tất cả | Tất cả (theo BR4/BR5) | Có |
| Quản lý trang | Tất cả khóa học | Có | Tất cả | Tất cả (theo BR4/BR5) | Có |
| Giáo Viên | Chỉ khóa học mình phụ trách | **Có** (khóa học tự tạo ở trạng thái draft, tự động là giáo viên phụ trách) | Chỉ khóa học mình phụ trách (nội dung: chương/bài) | Không | Không |
| Học Sinh | Không (chỉ xem công khai qua US-002/US-003) | – | – | – | – |

## Ảnh hưởng dữ liệu
- Bảng `courses`: `id`, `title`, `slug`, `short_description`, `description`, `grade_level`, `price`, `thumbnail`, `status` (enum: draft, published, unpublished), `deleted_at` (soft delete), timestamps. (Đã **bỏ** cột `teacher_id` đơn, thay bằng bảng pivot dưới đây.)
- Bảng `course_teacher` (mới, thay cho `teacher_id`): `course_id`, `teacher_id` (FK `users.id`, điều kiện role = giáo viên kiểm tra ở tầng ứng dụng), timestamps.
- Bảng `subjects`, `course_subject` (quản lý ở US-011).
- Bảng `chapters`: `id`, `course_id`, `title`, `order`, `deleted_at`.
- Bảng `lessons`: `id`, `chapter_id`, `title`, `order`, `video_source` (enum: `upload`, `external_link`), `video_url`/`video_asset_id` (tùy `video_source`), `duration_seconds`, `is_preview`, `deleted_at`.

## Ngoài phạm vi
- Lịch sử chỉnh sửa (audit log) chi tiết từng thay đổi nội dung.
- Đa ngôn ngữ cho nội dung khóa học.
- Import hàng loạt khóa học từ Excel.
- Quy trình duyệt/từ chối chính thức có ghi lý do cho khóa học giáo viên tự tạo (MVP chỉ dùng cơ chế publish/chưa publish như cơ chế duyệt ngầm định — xem câu hỏi mở nếu PO muốn quy trình duyệt tường minh hơn).

## Quyết định của PO
- Giáo viên vừa được tự tạo khóa học, vừa có thể được Admin/Quản lý trang gán làm giáo viên phụ trách.
- Quản lý trang có quyền gần như Admin, trừ chức năng cấu hình hệ thống.
- Một khóa học có thể có nhiều giáo viên phụ trách (đồng giảng dạy).
- Video hỗ trợ cả tải lên hệ thống nội bộ lẫn dán link ngoài.

## Câu hỏi mở
- [ ] Cơ chế "duyệt" khóa học do giáo viên tự tạo hiện dùng chung với thao tác "Xuất bản" (BR8) — PO có cần một bước duyệt tường minh riêng biệt (ví dụ trạng thái "Chờ duyệt" khác với "Draft" thông thường, có thể ghi lý do từ chối) hay dùng chung cơ chế publish là đủ?
- [ ] Có giới hạn số lượng khóa học một giáo viên được tự tạo không?

## Ghi chú cho Designer / Dev / QA
- Designer: cần màn hình quản trị dạng danh sách + form tạo/sửa khóa học (hỗ trợ chọn nhiều giáo viên phụ trách dạng multi-select), và một giao diện quản lý chương/bài dạng cây có kéo thả sắp xếp thứ tự; form thêm bài học cần toggle rõ giữa "Tải video" và "Dán link".
- Dev: áp dụng Laravel Policy/Gate để kiểm soát quyền theo quan hệ `course_teacher` ở tầng backend (AC6, AC7), không chỉ ẩn menu ở giao diện; validate luôn còn ít nhất 1 giáo viên phụ trách khi gỡ.
- QA: kiểm thử giáo viên A cố sửa khóa học của giáo viên B qua URL trực tiếp; kiểm thử publish khi chưa đủ điều kiện; kiểm thử xóa khóa học đã có enrollment; kiểm thử giáo viên tự tạo khóa học và luồng admin xuất bản khóa học đó.
