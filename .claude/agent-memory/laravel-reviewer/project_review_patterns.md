---
name: project-review-patterns
description: Lỗi/mẫu lặp lại và quy ước review của dự án VitaminVui (Laravel 13, MySQL, admin-api/api)
metadata:
  type: project
---

- Báo cáo review lưu ở `docs/reviews/review-<Txx>.md` (không phải docs/review/), kết luận dạng "PASS (n BLOCKER, n SHOULD, n NIT)"; tiếng Việt.
- Stack thực tế MySQL, không phải SQL Server: bỏ qua checklist sqlsrv. Host không có PHP; chạy script tạm bằng `cat x.php | docker exec -i vitaminvui-php-1 php` (container mount repo chính, KHÔNG mount worktree).
- Mẫu lỗi lặp lại: (1) quy tắc nghiệp vụ chỉ chặn ở một đường đi, đường tương đương lách được (T09: xoá chương bị chặn nhưng xoá từng bài thì không); (2) bất biến publish (BR3) chỉ kiểm lúc publish; (3) FormRequest wildcard `distinct` không có trần kích thước (bậc hai, Nginx cho body 5 MB).
- Điểm tốt đã có: ContentLock (course->chapter->lesson), audit không chứa URL/ID video, ExternalVideoLink whitelist chặt (thử 50 payload không bypass).
- Người giao việc có thể không đính kèm "giả định của Dev"; hỏi/ghi rõ nếu suy ra từ code.
