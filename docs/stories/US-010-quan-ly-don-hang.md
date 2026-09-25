# US-010: Quản lý đơn hàng

**Trạng thái:** Draft
**Ưu tiên:** Should

## User story
Là Admin/Quản lý trang, tôi muốn xem danh sách, chi tiết và xuất báo cáo các đơn hàng để theo dõi doanh thu và hỗ trợ học sinh khi có vấn đề về thanh toán.

## Bối cảnh
Phụ thuộc dữ liệu đơn hàng tạo ra từ US-005. Đây là công cụ vận hành/hỗ trợ khách hàng cho đội ngũ quản trị, liên quan dữ liệu tiền nên cần review bảo mật. PO xác nhận quy mô dự kiến khoảng **100.000 đơn hàng/năm đầu tiên** — cần tính toán hiệu năng ngay từ thiết kế.

## Business rules
- BR1 (Quyết định của PO — làm rõ thuật ngữ): "Quản trị viên" mà PO nhắc tới chính là vai trò **Quản lý trang** đã định nghĩa trong CLAUDE.md (không phải vai trò thứ 5). Cả **Admin và Quản lý trang** đều được xem toàn bộ đơn hàng và thực hiện đánh dấu hoàn tiền, không có giới hạn khác biệt giữa 2 vai trò này cho phân hệ đơn hàng.
- BR2: MVP chỉ hỗ trợ đánh dấu hoàn tiền thủ công (không tích hợp API hoàn tiền tự động của cổng thanh toán); admin/quản lý trang xử lý hoàn tiền thực tế ngoài hệ thống rồi cập nhật trạng thái trên hệ thống.
- BR3: Khi một đơn hàng được đánh dấu "Đã hoàn tiền", các `enrollment` liên quan tới khóa học trong đơn đó bị chuyển sang `status = revoked`, học sinh mất quyền truy cập nội dung khóa học tương ứng ngay lập tức.
- BR4 (Quyết định của PO): **Không** hỗ trợ hoàn tiền một phần — hoàn tiền luôn áp dụng cho **toàn bộ đơn hàng** (tất cả khóa học trong đơn đều bị thu hồi enrollment).
- BR5 (Quyết định của PO): Hệ thống hỗ trợ **xuất báo cáo đơn hàng ra Excel/CSV** theo bộ lọc đang áp dụng, là yêu cầu bắt buộc trong MVP (không còn là tính năng tùy chọn).

## Acceptance criteria
- AC1: Given có đơn hàng đã thanh toán thành công, When admin/quản lý trang vào trang danh sách đơn hàng, Then thấy đầy đủ mã đơn, tên học sinh, danh sách khóa học, tổng tiền (kèm số tiền giảm giá nếu có), thời gian, trạng thái.
- AC2: Given admin/quản lý trang áp dụng bộ lọc theo khoảng thời gian và trạng thái đơn hàng, When submit filter, Then danh sách chỉ hiển thị các đơn khớp điều kiện.
- AC3: Given một đơn hàng ở trạng thái thất bại/hủy, When admin/quản lý trang mở chi tiết đơn, Then thấy được lý do thất bại/hủy (bao gồm cả trường hợp tự động hủy do quá 12 giờ pending theo US-005 BR7) để hỗ trợ học sinh.
- AC4: Given học sinh khiếu nại cần hoàn tiền cho một đơn đã thanh toán, When admin hoặc quản lý trang thực hiện thao tác "Đánh dấu hoàn tiền" và xác nhận, Then trạng thái đơn chuyển thành "Đã hoàn tiền" (toàn bộ đơn) và **tất cả** enrollment liên quan tới đơn đó bị thu hồi (`status = revoked`), học sinh không còn xem được nội dung các khóa học đó.
- AC5: Given không có đơn hàng nào khớp với bộ lọc thời gian đang chọn, When áp dụng filter, Then hiển thị thông báo trống rõ ràng thay vì bảng trắng không giải thích.
- AC6: Given admin/quản lý trang đang xem danh sách đơn hàng theo bộ lọc bất kỳ, When bấm "Xuất báo cáo Excel/CSV", Then file xuất ra khớp đúng với dữ liệu đang hiển thị theo bộ lọc, tải về thành công trong thời gian hợp lý (kể cả khi số lượng bản ghi lớn).

## Trường hợp biên & lỗi
- Số lượng đơn hàng lớn (~100.000 đơn/năm, dự kiến tiếp tục tăng) → trang danh sách bắt buộc phân trang và có index CSDL phù hợp (`orders.created_at`, `orders.status`, `orders.user_id`) để không chậm; **khuyến nghị gọi `laravel-dba`** khi thiết kế kỹ thuật để tư vấn chiến lược index/phân trang/khả năng cần archiving dữ liệu cũ.
- Xuất Excel/CSV với bộ lọc trả về số lượng bản ghi rất lớn (ví dụ toàn bộ 100.000 đơn) → cần xử lý xuất theo dạng streaming/queue job chạy nền thay vì tải đồng bộ trong 1 request, tránh timeout — chi tiết kỹ thuật do Architect/Dev quyết định.
- Admin thao tác hoàn tiền 2 lần liên tiếp cho cùng 1 đơn → thao tác lần 2 phải bị chặn/báo đã xử lý, không thu hồi enrollment 2 lần gây lỗi trạng thái.
- Đơn hàng đang ở trạng thái "pending" quá lâu (chưa thanh toán xong, chưa tới 12 giờ để tự hủy) → cần hiển thị riêng để admin dễ nhận biết cần theo dõi.

## Phân quyền
| Vai trò | Xem danh sách đơn hàng | Xem chi tiết đơn | Đánh dấu hoàn tiền | Xuất báo cáo |
|---|---|---|---|---|
| Admin | Có | Có | Có | Có |
| Quản lý trang | Có | Có | Có (ngang quyền Admin, theo BR1) | Có |
| Giáo Viên | Không | Không | Không | Không |
| Học Sinh | Chỉ đơn hàng của chính mình (US-005 AC7) | Chỉ đơn hàng của chính mình | Không | Không |

## Ảnh hưởng dữ liệu
- Sử dụng lại bảng `orders`, `order_items`, `enrollments` đã định nghĩa ở US-005.
- Thêm giá trị `status = refunded` vào enum trạng thái của `orders` và `enrollments` (`revoked`).
- Cần index trên `orders.created_at`, `orders.status`, `orders.user_id` để phục vụ lọc/báo cáo hiệu quả ở quy mô ~100.000 đơn/năm.
- Cân nhắc queue job riêng cho tác vụ xuất Excel/CSV lớn (bảng `export_jobs` hoặc tương đương để theo dõi trạng thái xuất file — chi tiết kỹ thuật do Dev/Architect quyết định).
- Liên quan US-018 (quyền dữ liệu cá nhân): khi tài khoản học sinh bị ẩn danh hóa theo yêu cầu, đơn hàng liên quan vẫn được giữ nguyên (không xóa) để làm chứng từ, chỉ thông tin liên hệ học sinh hiển thị đã ẩn danh.

## Ngoài phạm vi
- Tích hợp API hoàn tiền tự động qua cổng thanh toán.
- Hoàn tiền một phần cho đơn nhiều khóa học.
- Báo cáo doanh thu nâng cao (biểu đồ, so sánh theo kỳ).
- Đối soát tự động với cổng thanh toán.

## Quyết định của PO
- "Quản trị viên" = vai trò "Quản lý trang"; cả Admin và Quản lý trang đều được hoàn tiền, không giới hạn khác biệt.
- Không hỗ trợ hoàn tiền một phần.
- Bắt buộc có tính năng xuất báo cáo Excel/CSV trong MVP.
- Quy mô dự kiến ~100.000 đơn hàng/năm đầu — cần thiết kế tối ưu hiệu năng, khuyến nghị có sự tham gia của `laravel-dba`.

## Câu hỏi mở
- Không còn câu hỏi mở cho story này.

## Ghi chú cho Designer / Dev / QA
- Designer: bảng danh sách đơn hàng cần hỗ trợ lọc nhanh theo trạng thái (tab hoặc dropdown), nút "Xuất Excel/CSV" luôn hiển thị cạnh bộ lọc, và trang chi tiết đơn hiển thị rõ lịch sử trạng thái.
- Dev: thao tác hoàn tiền và thu hồi enrollment phải nằm trong 1 database transaction để đảm bảo tính nhất quán; đây là tính năng liên quan tiền, cần review với `laravel-security`; xuất báo cáo lớn nên chạy qua queue job, không xử lý đồng bộ trong request HTTP; nên phối hợp `laravel-dba` sớm do quy mô dữ liệu dự kiến lớn (100.000 đơn/năm).
- QA: kiểm thử hoàn tiền 2 lần liên tiếp cùng 1 đơn, kiểm thử học sinh mất quyền truy cập ngay sau khi bị revoke, kiểm thử filter và xuất báo cáo với dữ liệu lớn (mô phỏng hàng chục nghìn bản ghi).
