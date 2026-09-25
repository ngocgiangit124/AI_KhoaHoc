# Đặc tả UX — US-003: Trang chi tiết khóa học

## 1. Luồng người dùng
```
/khoa-hoc hoặc /lop-{grade} → bấm thẻ khóa học → /khoa-hoc/{slug}
  Nếu có phí, chưa mua      → nút "Mua ngay" → thêm vào giỏ (US-004) → /gio-hang
  Nếu miễn phí, chưa đăng ký → nút "Đăng ký" → tạo yêu cầu pending → nút đổi thành "Đang chờ duyệt" (US-012)
  Nếu đã sở hữu (active)     → nút "Vào học" → /hoc/{course}/bai/{lesson gần nhất}
  Bấm bài học is_preview=true → phát video ngay trong modal/khu vực preview
  Bấm bài học khoá (chưa mua) → hiện lời nhắc mua, cuộn tới nút hành động chính
```

## 2. Màn hình

### 2.1 `/khoa-hoc/{slug}` — Chi tiết khóa học
**Bố cục mobile (thứ tự dọc):**
1. Ảnh/video giới thiệu (nếu có) + tên khóa học + badge Lớp + badge Miễn phí (nếu có)
2. Giá (to, đậm) hoặc "Miễn phí"; số học sinh đã đăng ký: "1.240 học sinh đã đăng ký" — nếu 0 thì "Chưa có học sinh đăng ký" (không hiển thị "0 học sinh")
3. Nút hành động chính full-width, sticky ở đáy màn hình khi cuộn (mobile) — nội dung nút đổi theo trạng thái (xem bảng mục 3)
4. Mô tả khóa học (rich text, có thể "Xem thêm/Thu gọn")
5. Danh sách giáo viên phụ trách: hàng ngang các `<Avatar>` + tên + mô tả ngắn, có thể nhiều giáo viên (AC6)
6. Outline chương/bài — accordion theo chương, mỗi bài hiển thị: tên bài, thời lượng, icon trạng thái:
   - Icon "▶ Xem thử" (badge accent) nếu `is_preview = true` — bấm mở video preview
   - Icon khoá 🔒 nếu chưa mua — bấm hiện lời nhắc mua (không mở video)
   - Icon ✔ (emerald) nếu học sinh đã hoàn thành (chỉ hiện khi đã enrolled, đồng bộ US-006/US-008)

**Bố cục desktop:** 2 cột — trái 60% (mô tả + outline), phải 40% (card sticky: ảnh, giá, nút hành động, giáo viên).

### 2.2 Modal/khu vực xem thử video (preview)
Player đơn giản, nút đóng, tiêu đề bài học, không cần outline bên cạnh (khác US-006).

### 2.3 Lời nhắc mua khi bấm bài khoá
`<Alert variant="info">` hoặc small popover ngay dưới bài học: "Bài học này chỉ dành cho học sinh đã mua khóa học." + nút "Mua ngay"/"Đăng ký" tương ứng, cuộn mượt (`scroll-into-view`) tới nút hành động chính.

**Ghi chú kỹ thuật:** trang chi tiết render công khai bằng `publicFetch` (`GET /courses/{slug}`, cache được — không chứa URL/ID video). Trạng thái CTA ở mục 3 (đã mua/đang chờ duyệt/đã sở hữu...) lấy riêng qua `GET /courses/{slug}/viewer-state` bằng `authFetch` (`cache: 'no-store'`, cần đăng nhập nhưng route vẫn cho khách xem — chỉ gọi `viewer-state` khi đã có phiên) — tách 2 lời gọi để phần nội dung công khai vẫn cache/SEO tốt.

## 3. Bảng trạng thái nút hành động chính (quan trọng nhất của story)

| Điều kiện | Nút hiển thị | Hành động |
|---|---|---|
| Có phí, khách/học sinh chưa mua | "Mua ngay" (primary) | Thêm vào giỏ → chuyển `/gio-hang` |
| Miễn phí, chưa đăng ký | "Đăng ký" (primary) | Tạo enrollment pending (US-012) |
| Miễn phí, đang `pending_approval` | "Đang chờ duyệt" (disabled, màu `warning`, có icon đồng hồ) | Không click được, tooltip "Yêu cầu của bạn đang chờ giáo viên/admin duyệt" |
| Miễn phí, bị từ chối trước đó | "Đăng ký lại" (primary) | Tạo yêu cầu pending mới (US-012 AC5) |
| Đã sở hữu (`enrollment.status = active`) | "Vào học" (primary, emerald) | Chuyển `/hoc/{course}/bai/{last_accessed hoặc bài đầu}` |
| Khách chưa đăng nhập, khóa có phí | "Mua ngay" | Bấm → chuyển `/dang-nhap` (giữ redirect quay lại sau khi đăng nhập) |
| Khách chưa đăng nhập, khóa miễn phí | "Đăng ký" | Bấm → chuyển `/dang-nhap` |

## 4. Bảng trạng thái UI khác & thông điệp

| Trạng thái | Hiển thị |
|---|---|
| Đang tải trang | Skeleton toàn bộ khung (ảnh, giá, outline) |
| Khóa học không tồn tại/đã xoá | Trang 404 thân thiện: "Không tìm thấy khóa học" + nút "Về trang danh mục" |
| Chưa có chương/bài (outline rỗng) | `<EmptyState>` nhỏ trong khu vực outline: "Nội dung khóa học đang được cập nhật" |
| Không có bài preview nào | Ẩn hoàn toàn khu vực/nút "Xem thử" |
| Video preview lỗi | Trong player: "Không phát được video xem thử, vui lòng thử lại sau." |
| Khóa học đã unpublish nhưng học sinh đã sở hữu | Hiển thị bình thường, không banner cảnh báo (theo BR đã enroll vẫn xem được) |
| Số học sinh đăng ký = 0 | "Chưa có học sinh đăng ký" |

## 5. Component React dùng/tạo mới
- `<Badge variant="free">`, `<Avatar>` (dùng lại — `packages/ui`)
- Outline accordion: component `<CourseOutline>` (mới — `apps/web`) dùng chung với US-006 (chỉ khác quyền click)
- `<Button>` biến thể theo trạng thái (đã liệt kê bảng mục 3) — cân nhắc 1 component `<CourseCtaButton course={course} viewerState={viewerState}>` (mới — `apps/web`) gói toàn bộ logic hiển thị để tái sử dụng và tránh lặp điều kiện ở nhiều nơi
- `<Alert variant="info">` cho lời nhắc mua (dùng lại — `packages/ui`)

## 6. Điểm cần PO duyệt
- Không có điểm "chờ PO xác nhận" mới phát sinh riêng cho story này (đã chốt ở US-003).
