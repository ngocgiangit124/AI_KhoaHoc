# US-007: Làm bài tập/đề kiểm tra trắc nghiệm và xem kết quả

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là học sinh đã mua khóa học, tôi muốn làm bài tập trắc nghiệm gắn với bài học/chương (có thể có giới hạn thời gian) và xem kết quả cùng đáp án ngay để tự đánh giá mức độ hiểu bài.

## Bối cảnh
Phụ thuộc enrollment (US-005/US-006/US-012) và nội dung quiz do giáo viên/admin tạo (US-009 mở rộng — quản lý câu hỏi). MVP chỉ hỗ trợ trắc nghiệm 1 đáp án đúng trên 4 lựa chọn, phù hợp đặc thù đề thi Toán trắc nghiệm THPT/vào 10.

## Business rules
- BR1: Mỗi quiz gắn với 1 bài học hoặc 1 chương, gồm nhiều câu hỏi trắc nghiệm, mỗi câu có 4 đáp án và đúng 1 đáp án đúng.
- BR2 (Quyết định của PO): Bài làm được chấm điểm tự động ngay khi học sinh nộp bài, hiển thị theo **thang điểm 10** (điểm = số câu đúng / tổng số câu × 10).
- BR3: Học sinh chỉ làm được quiz của khóa học mà mình đã sở hữu (enrollment active).
- BR4 (Quyết định của PO): Học sinh làm lại quiz **không giới hạn số lần**; hệ thống lưu mọi lần làm (`quiz_attempts`) và hiển thị điểm cao nhất trên tiến độ.
- BR5 (Quyết định của PO): Sau khi nộp bài, học sinh được **xem đáp án đúng** cho từng câu (không chỉ đúng/sai) để học từ lỗi sai.
- BR6 (Quyết định của PO): Quiz có thể cấu hình **giới hạn thời gian làm bài** (`time_limit_minutes`). Khi hết giờ, hệ thống tự động nộp bài với các câu đã chọn tại thời điểm đó.
- BR7 (Quyết định của PO): Không yêu cầu điểm quiz tối thiểu để mở khóa bài học tiếp theo — quiz chỉ mang tính tham khảo, không chặn tiến trình học.
- BR8: Để tránh mất dữ liệu khi hết giờ hoặc mất kết nối, câu trả lời được **lưu ngay khi học sinh chọn đáp án** (autosave từng câu), không chỉ lưu một lần lúc bấm "Nộp bài".

## Acceptance criteria
- AC1: Given quiz có 10 câu hỏi trắc nghiệm, When học sinh chọn đáp án cho tất cả câu và bấm "Nộp bài", Then hệ thống chấm điểm ngay và hiển thị số câu đúng/tổng cùng điểm số theo thang 10.
- AC2: Given học sinh chưa trả lời hết câu hỏi, When bấm "Nộp bài", Then hệ thống cảnh báo số câu chưa trả lời và yêu cầu xác nhận trước khi nộp chính thức (không chặn nộp nếu học sinh xác nhận).
- AC3: Given học sinh đã nộp bài, When xem lại kết quả, Then với mỗi câu hỏi thấy được đáp án học sinh đã chọn, đáp án đúng, và trạng thái đúng/sai.
- AC4: Given học sinh làm lại quiz lần 2 của cùng 1 quiz, When nộp bài, Then hệ thống lưu thành một attempt mới (không ghi đè attempt cũ) và cập nhật điểm cao nhất hiển thị trên trang tiến độ.
- AC5: Given học sinh chưa mua khóa học, When cố truy cập trực tiếp URL làm quiz của khóa học đó, Then hệ thống chặn và chuyển hướng về trang chi tiết khóa học.
- AC6: Given quiz không có câu hỏi nào (chưa được giáo viên soạn), When học sinh cố vào làm bài, Then hệ thống hiển thị thông báo "Bài kiểm tra chưa sẵn sàng" thay vì màn hình trống.
- AC7: Given quiz được cấu hình `time_limit_minutes = 15`, When học sinh bắt đầu làm bài, Then đồng hồ đếm ngược 15 phút hiển thị rõ trên màn hình; khi đếm ngược về 0, hệ thống tự động nộp bài với các câu đã chọn tại thời điểm đó và chuyển sang màn hình kết quả.
- AC8: Given học sinh đã chọn đáp án cho một số câu nhưng chưa bấm "Nộp bài", When mất kết nối mạng đột ngột rồi kết nối lại trước khi hết giờ, Then các câu đã chọn trước đó vẫn được giữ nguyên (không bị mất) nhờ cơ chế autosave.

## Trường hợp biên & lỗi
- Học sinh đóng trình duyệt/mất kết nối giữa chừng khi đang làm bài và quiz có giới hạn thời gian → khi hết giờ (theo thời gian server), hệ thống tự động nộp bài với dữ liệu autosave gần nhất, kể cả khi học sinh không còn ở trang làm bài.
- Gửi request nộp bài 2 lần liên tiếp (double click / double submit) → chỉ tạo 1 attempt, không tạo bản ghi trùng.
- Câu hỏi bị giáo viên sửa/xóa sau khi học sinh đã làm attempt cũ → kết quả các attempt cũ phải giữ nguyên dữ liệu đã chấm tại thời điểm làm bài (không bị ảnh hưởng bởi thay đổi sau này).
- Học sinh cố gửi `question_id` hoặc `option_id` không thuộc quiz đang làm (can thiệp request) → hệ thống phải từ chối/bỏ qua, không cho điểm sai lệch.
- Số lượng câu hỏi lớn (ví dụ đề thi 50 câu) → giao diện và thời gian chấm vẫn phải phản hồi nhanh.
- Đồng hồ đếm ngược ở client và server lệch nhau (do độ trễ mạng) → thời điểm hết giờ thực tế phải tính theo **server**, không tin tưởng đồng hồ trình duyệt, để tránh học sinh gian lận kéo dài thời gian.

## Phân quyền
| Vai trò | Làm bài | Xem kết quả của mình | Xem kết quả học sinh khác |
|---|---|---|---|
| Học Sinh có enrollment active | Có | Có | Không |
| Học Sinh không có enrollment | Không | Không | Không |
| Giáo Viên phụ trách khóa học | Không (không làm bài) | – | Có (xem báo cáo, ngoài phạm vi story này) |
| Quản lý trang / Admin | Không | – | Có (báo cáo tổng hợp, ngoài phạm vi story này) |

## Ảnh hưởng dữ liệu
- Bảng `quizzes`: `id`, `lesson_id` hoặc `chapter_id` (nullable tùy loại gắn), `title`, `time_limit_minutes` (nullable = không giới hạn thời gian).
- Bảng `quiz_questions`: `id`, `quiz_id`, `content`, `order`.
- Bảng `quiz_options`: `id`, `question_id`, `content`, `is_correct` (boolean).
- Bảng `quiz_attempts`: `id`, `user_id`, `quiz_id`, `score`, `total_questions`, `correct_count`, `started_at`, `submitted_at`, `auto_submitted` (boolean — đánh dấu có phải do hết giờ tự động nộp không).
- Bảng `quiz_attempt_answers`: `id`, `attempt_id`, `question_id`, `selected_option_id`, `is_correct`, `answered_at` (phục vụ autosave BR8).

## Ngoài phạm vi
- Câu hỏi tự luận / điền đáp án số (chỉ trắc nghiệm 1 đáp án đúng ở MVP).
- Ngân hàng câu hỏi ngẫu nhiên hóa thứ tự câu/đáp án mỗi lần làm.
- Đề thi thử theo cấu trúc chính thức của kỳ thi vào 10/THPT Quốc gia (đề trộn nhiều chuyên đề, chấm theo ma trận điểm riêng).
- Xếp hạng/leaderboard giữa các học sinh.

## Quyết định của PO
- Không giới hạn số lần làm lại quiz.
- Hiện đáp án đúng sau khi nộp bài.
- Điểm hiển thị theo thang 10.
- Có giới hạn thời gian làm bài (đếm ngược, tự nộp khi hết giờ).
- Không yêu cầu điểm tối thiểu để mở khóa bài học tiếp theo.

## Câu hỏi mở
- [ ] Thời gian làm bài mặc định/cách admin-giáo viên cấu hình `time_limit_minutes` cho từng quiz cụ thể ra sao — có bắt buộc mọi quiz đều có giới hạn thời gian không, hay tùy chọn theo từng quiz?

## Ghi chú cho Designer / Dev / QA
- Designer: màn hình làm bài cần hiển thị rõ số câu đã trả lời/tổng số câu và đồng hồ đếm ngược nổi bật; màn hình kết quả cần dễ đọc trên mobile, hiển thị rõ đáp án đúng cho từng câu.
- Dev: việc chấm điểm và tính thời gian hết hạn phải thực hiện ở server (không tin tưởng dữ liệu điểm/thời gian gửi từ client); validate `question_id`/`option_id` thuộc đúng quiz trước khi chấm; lưu câu trả lời autosave qua API riêng mỗi khi học sinh chọn đáp án.
- QA: kiểm thử double submit, kiểm thử gửi option_id không hợp lệ/không thuộc quiz, kiểm thử tính điểm với quiz rỗng, kiểm thử tự động nộp bài khi hết giờ và trường hợp mất mạng giữa chừng.
