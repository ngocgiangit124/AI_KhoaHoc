# Đặc tả UX — US-002: Danh mục khóa học theo lớp và chuyên đề

## 1. Luồng người dùng
```
Trang chủ / Header → /khoa-hoc (danh mục tổng)
   → chọn filter Lớp/Chuyên đề/Từ khóa/Sắp xếp → danh sách cập nhật (không load lại trang, giữ URL query)
   → bấm khóa học → /khoa-hoc/{slug} (US-003)

Link SEO ngoài (Google, mạng xã hội) → /lop-{grade} → danh mục riêng theo lớp, có thể lọc thêm theo chuyên đề trong phạm vi lớp đó
```

**Ghi chú kỹ thuật (ADR-004 §2.5, tasks.md FW2):** `/khoa-hoc` và `/lop-{grade}` là 2 route Next.js **công khai**, render bằng `publicFetch` (không gửi cookie), dùng SSR/ISR (`revalidate`) để tốt cho SEO và cache CDN — khớp với response `Cache-Control: public, max-age=60` của `GET /courses`. Việc đổi filter (lớp/chuyên đề/từ khóa/sắp xếp) cập nhật qua query string, không cần đăng nhập. Trạng thái riêng theo từng học sinh (đã mua/đang chờ duyệt/đã sở hữu) **không** hiển thị ở 2 trang này — chỉ xuất hiện ở trang chi tiết `/khoa-hoc/{slug}` qua endpoint `viewer-state` riêng (`authFetch`, xem US-003).

## 2. Màn hình

### 2.1 `/khoa-hoc` — Danh mục khóa học
**Bố cục mobile:** thanh tìm kiếm trên cùng → hàng chip filter cuộn ngang (Lớp 6…12) → nút "Bộ lọc" mở bottom-sheet (chuyên đề, sắp xếp) → lưới thẻ khóa học 1 cột (2 cột từ `sm:`) → `<Pagination>` cuối trang.

**Bố cục desktop:** sidebar trái cố định (Lớp — radio/checkbox, Chuyên đề — checkbox multi, khoảng giá bị loại khỏi MVP) + khu vực chính: thanh tìm kiếm + dropdown sắp xếp (Mới nhất/Phổ biến nhất/Nổi bật) ở trên cùng bên phải, lưới thẻ 3 cột.

**`<CourseCard>` hiển thị:** ảnh đại diện, badge lớp (VD "Lớp 9"), badge "Miễn phí" (màu accent) nếu `price = 0`, tên khóa học, tên chuyên đề (chip nhỏ), giá (hoặc "Miễn phí"), số học sinh đã đăng ký (nhỏ, `text-xs text-gray-500`, ví dụ "1.240 học sinh đã học").

**Hành động:** click chip lớp, checkbox chuyên đề, nhập từ khóa + Enter/nút tìm, đổi dropdown sắp xếp, chuyển trang.

### 2.2 `/lop-{grade}` — Trang danh mục theo lớp (SEO)
Giống 2.1 nhưng:
- H1 rõ ràng: "Khóa học Toán lớp 9" (không phải chỉ là filter ẩn)
- Đoạn mô tả ngắn dưới H1 phục vụ SEO (2–3 câu, admin/content có thể cấu hình sau, MVP có thể hard-code theo lớp)
- Bộ lọc chỉ còn Chuyên đề + Sắp xếp (đã cố định Lớp = {grade}, không cho đổi lớp tại đây — muốn đổi lớp thì có link nhanh "Xem lớp khác" dẫn về `/khoa-hoc`)
- Breadcrumb: `Trang chủ / Lớp 9`

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Hiển thị |
|---|---|
| Đang tải | 6 `<Skeleton variant="card">` nhấp nháy đúng kích thước `<CourseCard>` |
| Rỗng (chưa có khóa học nào published) | `<EmptyState>` icon sách, "Chưa có khóa học nào. Quay lại sau nhé!" |
| Rỗng do lọc không khớp (AC3) | `<EmptyState>` "Không tìm thấy khóa học phù hợp", mô tả "Hãy thử bỏ bớt điều kiện lọc", nút "Xoá bộ lọc" |
| Lỗi tải trang | `<Alert variant="danger">` "Không tải được danh sách khóa học, vui lòng thử lại." + nút "Tải lại" |
| `/lop-99` không hợp lệ | Trang 404 chuẩn của Next.js (`not-found.tsx`) |
| Phân trang | `<Pagination>` ẩn nếu chỉ có 1 trang (≤ 25 kết quả) |

## 4. Component React dùng/tạo mới
- `<CourseCard>` (mới — `apps/web`, dùng lại ở US-003 phần "khóa học liên quan" nếu sau này mở rộng, và ở US-008 nếu cần)
- `<Pagination>`, `<EmptyState>`, `<Alert>`, `<Skeleton>` (dùng lại — `packages/ui`)
- `<Badge variant="free">` cho nhãn "Miễn phí" (dùng lại — `packages/ui`)
- Bottom-sheet filter mobile: state React cục bộ (`useState`) trong 1 component riêng `FilterSheet` của `apps/web`, không cần thư viện ngoài

## 5. Điểm cần PO duyệt
- Nội dung mô tả SEO mặc định cho từng trang `/lop-{grade}` (ai viết: content/PO).
- Ngưỡng thời gian tải chấp nhận được khi danh mục lớn (câu hỏi mở của story, không ảnh hưởng trực tiếp UI nhưng ảnh hưởng có cần thêm skeleton/lazy-load hay không).
