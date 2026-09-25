# US-006: Xem bài giảng video trong khóa học đã mua

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là học sinh đã mua/được duyệt khóa học, tôi muốn xem video bài giảng theo đúng thứ tự chương/bài để học kiến thức Toán một cách có hệ thống, trên một phiên đăng nhập duy nhất của tài khoản mình.

## Bối cảnh
Phụ thuộc `enrollment` từ US-005 (khóa học có phí) hoặc US-012 (khóa học miễn phí đã được duyệt) và cấu trúc chương/bài từ US-009. Đây là trải nghiệm học chính của học sinh sau khi có quyền truy cập khóa học.

## Business rules
- BR1: Chỉ học sinh có `enrollment.status = active` với khóa học mới xem được toàn bộ video bài học (ngoại trừ bài preview đã mở công khai ở US-003).
- BR2 (Quyết định của PO): Học sinh xem đạt **≥ 90% thời lượng video** của một bài học thì bài học **tự động** được đánh dấu "đã hoàn thành" (không cần bấm nút thủ công).
- BR3: Tiến độ xem của học sinh được lưu lại để có thể học tiếp đúng vị trí ở lần truy cập sau (tối thiểu ở mức bài học, không bắt buộc mức giây trong MVP — cần xác nhận).
- BR4: Nếu khóa học bị admin unpublish sau khi học sinh đã mua, học sinh có enrollment active vẫn giữ quyền truy cập toàn bộ nội dung.
- BR5 (Quyết định của PO): Video bài giảng được phát thông qua **dịch vụ streaming chuyên dụng** (định hướng dài hạn kiểu Cloudflare Stream/Bunny). Ở giai đoạn phát triển hiện tại, đội dev **tự xây dựng một dịch vụ lưu trữ/phát video nội bộ trên Laravel mô phỏng theo mô hình của Bunny** (upload, tạo signed URL phát video có kiểm soát quyền truy cập) để phục vụ MVP, có thể thay thế bằng dịch vụ streaming thuê ngoài sau này mà không đổi hành vi nghiệp vụ của story này. **Đây là quyết định kiến trúc — cần `laravel-architect` viết ADR riêng mô tả thiết kế dịch vụ nội bộ này**, không thuộc phạm vi đặc tả nghiệp vụ của BA.
- BR6 (Quyết định của PO): Áp dụng giới hạn **1 thiết bị/1 phiên đăng nhập tại một thời điểm** để chống chia sẻ tài khoản — chi tiết quy tắc và acceptance criteria xem story riêng **US-014**. Hệ quả với story này: nếu phiên bị vô hiệu hóa do đăng nhập nơi khác, học sinh không thể tiếp tục tải/xem video ở phiên cũ.

## Acceptance criteria
- AC1: Given học sinh đã mua khóa học, When vào trang học và chọn 1 bài học trong outline, Then video phát được và outline chương/bài hiển thị bên cạnh để điều hướng.
- AC2: Given học sinh xem đạt ≥ 90% thời lượng video của một bài, When mốc đó đạt được, Then bài học được đánh dấu "Đã hoàn thành" tự động và tiến độ tổng của khóa học được cập nhật.
- AC3: Given học sinh chưa mua khóa học, When cố truy cập trực tiếp URL của một bài học không phải preview, Then hệ thống chặn và chuyển hướng về trang chi tiết khóa học kèm thông báo yêu cầu mua khóa học.
- AC4: Given học sinh đang xem 1 bài học, When bấm "Bài tiếp theo", Then hệ thống chuyển đúng sang bài kế tiếp theo thứ tự đã cấu hình trong chương.
- AC5: Given link video của một bài học bị lỗi/không tải được, When học sinh mở bài học đó, Then hiển thị thông báo lỗi rõ ràng ("Không tải được video, vui lòng thử lại") thay vì màn hình trắng hoặc loading vô hạn.
- AC6: Given học sinh đã hoàn thành một số bài trước đó, When quay lại khóa học ở phiên học sau, Then hệ thống đưa học sinh tới bài học tiếp theo chưa hoàn thành (không bắt học lại từ đầu).
- AC7: Given học sinh đang xem video hợp lệ trên thiết bị A, When tài khoản đó đăng nhập thành công trên thiết bị B (theo quy tắc US-014), Then phiên trên thiết bị A bị vô hiệu hóa và lần gọi API video tiếp theo trên thiết bị A bị từ chối, yêu cầu đăng nhập lại.

## Trường hợp biên & lỗi
- Học sinh xem lại (replay) bài đã hoàn thành → vẫn cho xem lại tự do, không yêu cầu học lại từ đầu, không đổi trạng thái "đã hoàn thành".
- Học sinh dùng công cụ dev tool để tua nhanh video giả lập đạt 90% mà không xem thực → rủi ro chấp nhận được ở MVP, ghi nhận là hạn chế đã biết (không chặn bằng DRM ở MVP).
- Mạng yếu, video load chậm → cần có trạng thái loading rõ ràng, không đơ giao diện.
- Enrollment bị admin thu hồi (ví dụ do hoàn tiền, US-010) trong khi học sinh đang xem → lần tải bài học tiếp theo phải bị chặn ngay.
- Học sinh bị đăng xuất cưỡng bức giữa lúc đang xem dở video (theo US-014) → tiến độ đã lưu tới thời điểm gần nhất không bị mất, học sinh học tiếp bình thường sau khi đăng nhập lại.

## Phân quyền
| Vai trò | Xem video đầy đủ | Đánh dấu hoàn thành |
|---|---|---|
| Học Sinh có enrollment active | Có | Có (tự động) |
| Học Sinh không có enrollment | Chỉ bài preview | Không |
| Giáo Viên phụ trách khóa học (một trong các giáo viên đồng giảng dạy) | Có (để kiểm tra nội dung) | Không áp dụng |
| Quản lý trang / Admin | Có (để kiểm tra nội dung) | Không áp dụng |

## Ảnh hưởng dữ liệu
- Bảng `lesson_progress`: `id`, `user_id`, `lesson_id`, `watched_percent`, `status` (in_progress/completed), `completed_at`, `last_accessed_at`.
- Đọc từ `lessons`, `chapters`, `enrollments` đã có ở các story trước.
- Cân nhắc index (`user_id`, `lesson_id`) unique trên `lesson_progress`.
- Video lưu trữ/metadata: tùy thiết kế của dịch vụ nội bộ kiểu Bunny (BR5) — có thể cần bảng `videos`/`video_assets` riêng (chi tiết do Architect/DBA quyết định), `lessons.video_url` có thể đổi thành tham chiếu tới asset nội bộ.
- Phụ thuộc cơ chế phiên đăng nhập của US-014 để kiểm soát quyền phát video theo phiên hiện hành.

## Ngoài phạm vi
- Tải video về xem offline.
- Ghi chú (note-taking) trong lúc xem video.
- Tốc độ phát video tùy chỉnh (0.5x, 1.5x...) — có thể là tính năng của player có sẵn, không thuộc phạm vi nghiệp vụ story này.
- Thiết kế kỹ thuật chi tiết dịch vụ streaming nội bộ kiểu Bunny (thuộc phạm vi Architect/ADR, không phải BA).
- Chi tiết cơ chế giới hạn 1 thiết bị/1 phiên (thuộc phạm vi US-014).

## Quyết định của PO
- Đánh dấu hoàn thành bài học: tự động khi xem ≥ 90% thời lượng video.
- Video dùng dịch vụ streaming chuyên dụng về lâu dài; giai đoạn dev hiện tại tự xây hệ thống Laravel mô phỏng Bunny.
- Bắt buộc giới hạn 1 thiết bị/1 phiên đăng nhập (chi tiết ở US-014).

## Câu hỏi mở
- Không còn câu hỏi mở riêng cho story này (cơ chế thiết bị/phiên chuyển sang US-014; thiết kế kỹ thuật video chuyển cho Architect).

## Ghi chú cho Designer / Dev / QA
- Designer: giao diện học cần outline chương/bài luôn hiển thị bên cạnh player, đánh dấu rõ bài đã hoàn thành (dấu tick) và bài đang học; cần màn hình/thông báo khi phiên bị vô hiệu hóa do đăng nhập thiết bị khác (phối hợp US-014).
- Dev: kiểm tra quyền truy cập video ở tầng route/API (middleware), không cấp URL video trực tiếp public nếu chưa xác thực quyền sở hữu; phối hợp với Architect để dùng interface phát video (signed URL) tương thích cả hệ thống nội bộ hiện tại lẫn dịch vụ streaming ngoài sau này.
- QA: kiểm thử học sinh cố truy cập trực tiếp API lấy link video khi chưa mua; kiểm thử enrollment bị thu hồi giữa phiên học; kiểm thử phiên bị vô hiệu hóa khi đăng nhập thiết bị khác trong lúc đang xem video.
