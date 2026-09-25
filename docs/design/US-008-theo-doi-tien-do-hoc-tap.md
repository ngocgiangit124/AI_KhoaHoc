# Đặc tả UX — US-008: Theo dõi tiến độ học tập (Khóa học của tôi)

## 1. Luồng người dùng
```
Header/Bottom-nav → "Khóa học của tôi" → /tai-khoan/khoa-hoc-cua-toi
→ bấm 1 khóa học → xem chi tiết tiến độ (danh sách quiz đã làm + điểm cao nhất) hoặc "Tiếp tục học" → /hoc/{course}/bai/{lesson tiếp theo chưa hoàn thành}
```

## 2. Màn hình

### 2.1 `/tai-khoan/khoa-hoc-cua-toi` — Danh sách khóa học đã mua
**Bố cục:** danh sách thẻ dọc (mobile) / lưới 2 cột (desktop), sắp xếp theo thời điểm học gần nhất lên đầu (AC4). Mỗi thẻ:
- Ảnh khóa học, tên, lớp
- `<ProgressBar percent={30}>` + text "6/20 bài · 30%"
- Badge "Đã hoàn thành" (`emerald`) nếu 100%, ngược lại badge "Đang học" (`sky`)
- Nút "Tiếp tục học" (nếu đang học) / "Xem lại" (nếu đã hoàn thành) → vào bài học tiếp theo chưa hoàn thành hoặc bài đầu

### 2.2 Chi tiết tiến độ 1 khóa học (mở rộng/trang riêng)
- Thanh tiến độ tổng lớn trên cùng
- Danh sách chương/bài dạng outline (giống US-006) chỉ để xem trạng thái, không bắt buộc phát video tại đây (bấm vào vẫn chuyển sang trang học)
- Khối "Bài kiểm tra đã làm": bảng/danh sách tên quiz + điểm cao nhất (thang 10) + số lần đã làm, bấm vào 1 dòng → xem lại kết quả lần làm tốt nhất (liên kết US-007 màn kết quả)

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Hiển thị |
|---|---|
| Chưa mua khóa học nào (AC3) | `<EmptyState>` "Bạn chưa có khóa học nào", nút "Khám phá khóa học ngay" → `/khoa-hoc` |
| Đang tải | `<Skeleton variant="card">` × 3 |
| Khóa học 0 bài học (dữ liệu chưa hoàn chỉnh) | Progress bar hiện "Chưa có nội dung" thay vì 0% gây hiểu nhầm lỗi |
| Nhiều khóa học (>20) | Cuộn tải thêm (infinite scroll) hoặc `<Pagination>`, ưu tiên infinite scroll trên mobile |
| Lỗi tải dữ liệu | `<Alert variant="danger">` "Không tải được danh sách khóa học của bạn" + nút thử lại |

## 4. Component React dùng/tạo mới
- `<ProgressBar>`, `<Badge>`, `<EmptyState>`, `<Skeleton>`, `<Pagination>` (dùng lại — `packages/ui`)
- `<CourseOutline>` (dùng lại từ US-006 — `apps/web`, chế độ chỉ xem)
- `<QuizScoreList>` (mới — `apps/web`, nhỏ, dùng riêng ở đây)

## 5. Điểm cần PO duyệt
- Không phát sinh điểm mới.
