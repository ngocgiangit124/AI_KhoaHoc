# US-003: Trang chi tiết khóa học

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là khách hoặc học sinh, tôi muốn xem thông tin chi tiết của một khóa học (nội dung, giáo viên, học phí, outline bài học) để quyết định có mua/đăng ký khóa học hay không.

## Bối cảnh
Phụ thuộc dữ liệu khóa học/chương/bài học từ US-009. Là bước trung gian giữa danh mục (US-002) và giỏ hàng (US-004) đối với khóa học có phí; đối với khóa học miễn phí, dẫn tới luồng đăng ký & phê duyệt riêng (US-012).

## Business rules
- BR1: Trang chi tiết hiển thị đầy đủ outline (danh sách chương và tên bài học) cho mọi người xem, không cần mua.
- BR2: Chỉ học sinh có `enrollment` đang active với khóa học mới được phát video đầy đủ; người chưa mua chỉ xem được bài học được đánh dấu `is_preview = true`.
- BR3: Nếu học sinh đã sở hữu khóa học (enrollment active), nút hành động chính đổi từ "Mua ngay"/"Đăng ký" thành "Vào học".
- BR4: Khóa học không tồn tại hoặc đã bị xóa mềm → trả về trang lỗi 404.
- BR5 (Quyết định của PO): Khóa học có thể **miễn phí** (`price = 0`). Với khóa học miễn phí, nút hành động chính là **"Đăng ký"** thay vì "Mua ngay"; bấm vào không qua giỏ hàng/thanh toán mà tạo yêu cầu đăng ký cần **Admin hoặc Giáo viên phụ trách phê duyệt** trước khi học sinh được cấp quyền học đầy đủ. Chi tiết luồng đăng ký & phê duyệt xem US-012.
- BR6: "Bài học gần nhất đang học dở" được xác định theo bản ghi `lesson_progress.last_accessed_at` gần nhất của học sinh trong khóa học đó.
- BR7 (Quyết định của PO): Trang chi tiết hiển thị số lượng học sinh đã đăng ký (enrollment active) khóa học để tăng độ tin cậy.

## Acceptance criteria
- AC1: Given khóa học có 5 chương, 20 bài, When khách xem trang chi tiết, Then thấy đầy đủ outline chương/bài (tên bài, thời lượng), không phát được video của bài không phải preview.
- AC2: Given khách chưa đăng nhập hoặc chưa mua, When bấm vào bài học có `is_preview = true`, Then video preview phát được ngay không cần mua.
- AC3: Given khách chưa mua bấm vào bài học không phải preview, When truy cập, Then hệ thống chặn phát video và hiển thị lời nhắc mua/đăng ký khóa học.
- AC4: Given học sinh đã có enrollment active với khóa học, When xem trang chi tiết, Then nút hiển thị "Vào học" và bấm vào sẽ chuyển tới bài học có `last_accessed_at` gần nhất (hoặc bài đầu tiên nếu chưa học bài nào).
- AC5: Given `course_id`/slug không tồn tại hoặc khóa học đã bị xóa, When truy cập URL trực tiếp, Then trả về trang 404 thân thiện.
- AC6: Given khóa học có một hoặc nhiều giáo viên phụ trách (đồng giảng dạy, xem US-009), When xem trang chi tiết, Then hiển thị tên và mô tả ngắn của **tất cả** giáo viên phụ trách.
- AC7: Given khóa học có `price = 0`, When khách/học sinh xem trang chi tiết, Then nút hành động chính hiển thị "Đăng ký" (thay vì "Mua ngay"/"Thêm vào giỏ hàng"), bấm vào dẫn tới luồng đăng ký chờ duyệt mô tả ở US-012.
- AC8: Given khóa học đã có học sinh đăng ký, When xem trang chi tiết, Then hiển thị số lượng học sinh đã đăng ký (chỉ tính enrollment `active`, không tính `pending_approval`/`revoked`).

## Trường hợp biên & lỗi
- Khóa học chưa có chương/bài học nào (mới tạo) → hiển thị outline rỗng, không lỗi.
- Khóa học không có bài preview nào → không hiển thị nút "Xem thử", chỉ hiển thị "Mua ngay"/"Đăng ký".
- Video preview bị lỗi link → hiển thị thông báo lỗi phát video thay vì màn hình trắng.
- Khóa học đã unpublish nhưng học sinh đã mua trước đó cố truy cập → vẫn cho học sinh đã enroll xem bình thường (không bị 404), chỉ ẩn khỏi danh mục công khai và chặn người chưa mua.
- Khóa học miễn phí có học sinh đang ở trạng thái `pending_approval` → trang chi tiết với học sinh đó hiển thị trạng thái "Đang chờ duyệt" thay vì nút "Đăng ký" (tránh gửi yêu cầu trùng, chi tiết ở US-012).
- Số lượng học sinh đăng ký = 0 → hiển thị "Chưa có học sinh đăng ký" thay vì "0 học sinh" gây cảm giác tiêu cực (cần Designer xử lý câu chữ phù hợp).

## Phân quyền
| Vai trò | Xem outline | Xem preview | Xem toàn bộ video |
|---|---|---|---|
| Khách | Có | Có | Không |
| Học Sinh chưa mua/chưa được duyệt | Có | Có | Không |
| Học Sinh đã mua hoặc đã được duyệt (enrolled active) | Có | Có | Có |
| Giáo Viên (không phụ trách khóa học) | Có | Có | Không |
| Giáo Viên phụ trách (một trong các giáo viên đồng giảng dạy) / Quản lý trang / Admin | Có (qua trang quản trị) | Có | Có |

## Ảnh hưởng dữ liệu
- Bảng `chapters`: `id`, `course_id`, `title`, `order`.
- Bảng `lessons`: `id`, `chapter_id`, `title`, `order`, `video_url`, `duration_seconds`, `is_preview` (boolean).
- Bảng `enrollments`: `id`, `user_id`, `course_id`, `status` (enum mở rộng: `pending_approval`, `active`, `rejected`, `revoked` — xem US-012), `purchased_at`.
- Bảng `lesson_progress` (US-006): dùng `last_accessed_at` để xác định bài học gần nhất (BR6).
- Bảng pivot `course_teacher` (thay cho `teacher_id` đơn, xem US-009) để hiển thị nhiều giáo viên phụ trách ở AC6.
- Đếm `enrollments` theo `status = active` cho AC8 — cân nhắc denormalize nếu dữ liệu lớn (đồng bộ ghi chú với US-002).

## Ngoài phạm vi
- Đánh giá/nhận xét của học sinh về khóa học.
- Hỏi đáp (Q&A) trên trang chi tiết.
- Gợi ý khóa học liên quan.
- Chi tiết quy trình phê duyệt đăng ký khóa học miễn phí (màn hình duyệt, thông báo...) — xem US-012.

## Quyết định của PO
- Có khóa học miễn phí trong MVP; đăng ký khóa học miễn phí phải qua phê duyệt của Admin hoặc Giáo viên phụ trách (chi tiết → US-012).
- "Bài học gần nhất đang học dở" xác định theo lần xem gần nhất.
- Hiển thị số lượng học sinh đã đăng ký trên trang chi tiết.

## Câu hỏi mở
- Không còn câu hỏi mở riêng cho story này (các câu hỏi phát sinh về quy trình duyệt được chuyển sang US-012).

## Ghi chú cho Designer / Dev / QA
- Designer: outline chương/bài cần phân biệt rõ trực quan bài preview (có icon "xem thử") và bài khóa (icon khóa); thiết kế trạng thái nút động: "Mua ngay" (có phí, chưa mua) / "Đăng ký" (miễn phí, chưa đăng ký) / "Đang chờ duyệt" (miễn phí, pending) / "Vào học" (đã có quyền truy cập); hiển thị avatar/tên nhiều giáo viên phụ trách dạng danh sách.
- Dev: kiểm tra quyền xem video ở tầng route/controller, không chỉ ẩn ở UI (tránh truy cập trực tiếp URL video).
- QA: thử truy cập trực tiếp URL video/bài học bằng Postman/curl khi chưa đăng nhập và khi đăng nhập nhưng chưa mua để đảm bảo bị chặn ở backend; kiểm thử hiển thị đúng nút hành động cho từng trạng thái (chưa mua/miễn phí chưa đăng ký/đang chờ duyệt/đã sở hữu).
