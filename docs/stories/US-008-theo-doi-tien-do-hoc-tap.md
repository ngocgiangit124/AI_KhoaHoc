# US-008: Theo dõi tiến độ học tập (Khóa học của tôi)

**Trạng thái:** Draft
**Ưu tiên:** Should

## User story
Là học sinh, tôi muốn xem tổng quan tiến độ học tập của các khóa học đã mua để biết mình đã học tới đâu và cần tiếp tục học gì.

## Bối cảnh
Tổng hợp dữ liệu từ `enrollments` (US-005/US-012), `lesson_progress` (US-006), `quiz_attempts` (US-007). Không tạo dữ liệu mới, chủ yếu là màn hình tổng hợp/báo cáo cho học sinh.

## Business rules
- BR1: Tiến độ hoàn thành của một khóa học = số bài học đã hoàn thành / tổng số bài học của khóa học đó × 100%.
- BR2 (Quyết định của PO): Khóa học được đánh dấu "Hoàn thành" khi 100% bài học (video) đã hoàn thành — **không** yêu cầu thêm điều kiện điểm quiz tối thiểu.
- BR3: Danh sách "Khóa học của tôi" chỉ hiển thị khóa học có enrollment active của học sinh đang đăng nhập.

## Acceptance criteria
- AC1: Given học sinh đã hoàn thành 6/20 bài học trong một khóa học, When vào trang "Khóa học của tôi", Then thanh tiến độ của khóa học đó hiển thị đúng 30%.
- AC2: Given học sinh đã hoàn thành 100% bài học của một khóa học, When xem lại khóa học đó, Then khóa học được đánh dấu rõ ràng là "Đã hoàn thành".
- AC3: Given học sinh chưa mua khóa học nào, When vào trang "Khóa học của tôi", Then hiển thị trạng thái rỗng kèm gợi ý liên kết tới trang danh mục khóa học.
- AC4: Given học sinh có nhiều khóa học đang học dở, When vào trang "Khóa học của tôi", Then danh sách được sắp xếp theo thời điểm học gần nhất lên đầu.
- AC5: Given học sinh xem chi tiết tiến độ của một khóa học, When mở trang chi tiết tiến độ, Then thấy được danh sách các quiz đã làm kèm điểm số cao nhất của từng quiz (thang điểm 10, theo US-007).

## Trường hợp biên & lỗi
- Khóa học có 0 bài học (trường hợp hiếm, dữ liệu chưa hoàn chỉnh) → tránh lỗi chia cho 0 khi tính phần trăm, hiển thị 0% hoặc "Chưa có nội dung".
- Enrollment bị thu hồi (hoàn tiền, US-010) → khóa học đó không còn xuất hiện trong "Khóa học của tôi" nữa.
- Học sinh có rất nhiều khóa học (ví dụ > 20) → cần phân trang hoặc cuộn tải thêm, không load chậm.

## Phân quyền
| Vai trò | Xem tiến độ |
|---|---|
| Học Sinh | Chỉ tiến độ của chính mình |
| Giáo Viên | Không (xem báo cáo học sinh là tính năng khác, ngoài phạm vi story này) |
| Quản lý trang / Admin | Không (báo cáo tổng hợp là tính năng khác, ngoài phạm vi story này) |

## Ảnh hưởng dữ liệu
- Không cần bảng mới; truy vấn tổng hợp từ `enrollments`, `lesson_progress`, `quiz_attempts`, `lessons`, `chapters`.
- Đề xuất thêm cột `last_accessed_at` trên bảng `enrollments` để phục vụ sắp xếp AC4 (cập nhật mỗi khi học sinh mở bài học ở US-006).

## Ngoài phạm vi
- Chứng chỉ/chứng nhận hoàn thành khóa học (PDF, mã xác thực).
- Báo cáo tiến độ gửi cho giáo viên/phụ huynh.
- Nhắc nhở học tập qua email/thông báo đẩy.

## Quyết định của PO
- Điều kiện hoàn thành khóa học: chỉ cần xem hết 100% video, không yêu cầu điểm quiz.
- Không cấp chứng nhận hoàn thành ở MVP.
- Không gửi báo cáo tiến độ định kỳ cho phụ huynh.

## Câu hỏi mở
- Không còn câu hỏi mở cho story này.

## Ghi chú cho Designer / Dev / QA
- Designer: thanh tiến độ dạng phần trăm trực quan, dùng màu sắc phân biệt "đang học" và "đã hoàn thành".
- Dev: cân nhắc cache/tính toán trước (denormalize) phần trăm tiến độ nếu số lượng bài học lớn để tránh tính toán nặng mỗi lần tải trang.
- QA: kiểm thử khóa học 0 bài học, khóa học vừa mua chưa học bài nào (0%), khóa học đã hoàn thành 100%.
